<?php

declare(strict_types=1);

namespace Nod32Mirror\Mirror;

use Nod32Mirror\Config\Config;
use Nod32Mirror\Contract\DownloaderInterface;
use Nod32Mirror\Enum\LinkMethod;
use Nod32Mirror\Log\Log;
use Nod32Mirror\Log\Language;
use Nod32Mirror\Parser\Parser;
use Nod32Mirror\Tools;
use Nod32Mirror\ValueObject\Credential;
use Nod32Mirror\ValueObject\DownloadableFile;
use Nod32Mirror\ValueObject\DownloadResult;
use Nod32Mirror\ValueObject\MirrorInfo;
use Nod32Mirror\ValueObject\UpdateVariant;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RecursiveRegexIterator;
use RegexIterator;

final class Mirror
{
    private string $version;
    private string $name;
    private ?string $channel = null;
    private ?string $primaryChannel = null;
    private ?Credential $credential = null;

    /** @var MirrorInfo[] */
    private array $mirrors = [];

    /** @var UpdateVariant[] */
    private array $updateVariants = [];

    private ?string $primaryVariant = null;

    /** @var string[]|true */
    private array|bool $platforms = true;

    /** @var string[]|true */
    private array|bool $channels = true;

    /** @var string[] */
    private array $platformsFound = [];

    private int $totalDownloads = 0;
    private bool $updated = false;
    private bool $unAuthorized = false;

    public function __construct(
        private readonly DownloaderInterface $downloader,
        private readonly Parser $parser,
        private readonly Config $config,
        private readonly Log $log,
        private readonly Language $language
    ) {
    }

    /**
     * Initialize mirror for a specific version
     *
     * @param array<string, mixed> $dirConfig
     * @param string[]|true $platforms
     * @param string[]|true $channels
     */
    public function init(
        string $version,
        array $dirConfig,
        array|bool $platforms = true,
        array|bool $channels = true
    ): void {
        $this->log->trace($this->language->t('log.running', __METHOD__), $version);

        $this->version = $version;
        $this->name = $dirConfig['name'] ?? $version;
        $this->platforms = $platforms;
        $this->channels = $channels;
        $this->platformsFound = [];
        $this->totalDownloads = 0;
        $this->updated = false;
        $this->unAuthorized = false;
        $this->mirrors = [];
        $this->credential = null;

        $this->initVariants($dirConfig);

        $this->log->trace($this->language->t('mirror.initialized', $this->name), $version);
    }

    /**
     * @param array<string, mixed> $dirConfig
     */
    private function initVariants(array $dirConfig): void
    {
        $webDir = $this->config->getWebDir();
        $this->updateVariants = [];
        $this->primaryVariant = null;
        $this->primaryChannel = null;
        $this->channel = null;

        if (isset($dirConfig['channels'])) {
            foreach ($dirConfig['channels'] as $channelName => $variants) {
                if (is_array($this->channels) && !in_array($channelName, $this->channels, true)) {
                    continue;
                }

                foreach (['file', 'dll'] as $variantType) {
                    if (empty($variants[$variantType])) {
                        continue;
                    }

                    $sourcePath = $variants[$variantType];
                    $verFolder = $this->extractVersionFolder($sourcePath);

                    $variant = UpdateVariant::create(
                        $channelName,
                        $variantType,
                        $sourcePath,
                        $webDir,
                        TMP_PATH,
                        $verFolder
                    );

                    $this->updateVariants[$variant->key] = $variant;

                    Tools::ensureDirectory(dirname($variant->tmpPath));
                    Tools::ensureDirectory(dirname($variant->localPath));
                }
            }
        } else {
            // Legacy structure fallback
            foreach (['file', 'dll'] as $variantKey) {
                if (empty($dirConfig[$variantKey])) {
                    continue;
                }

                $sourcePath = $dirConfig[$variantKey];
                $verFolder = $this->extractVersionFolder($sourcePath);

                if (preg_match('#^eset_upd/update\.ver$#i', $sourcePath)) {
                    $fixedPath = Tools::ds('eset_upd', $this->version, 'update.ver');
                } else {
                    $fixedPath = preg_replace(
                        '/eset_upd\/update\.ver/is',
                        Tools::ds('eset_upd', 'v3', 'update.ver'),
                        $sourcePath
                    ) ?? $sourcePath;
                }

                $tmpPath = Tools::ds(TMP_PATH, $fixedPath);
                $localPath = Tools::ds($webDir, $fixedPath);

                $this->updateVariants[$variantKey] = new UpdateVariant(
                    key: $variantKey,
                    source: $sourcePath,
                    fixedPath: $fixedPath,
                    tmpPath: $tmpPath,
                    localPath: $localPath
                );

                Tools::ensureDirectory(dirname($tmpPath));
                Tools::ensureDirectory(dirname($localPath));
            }
        }

        // Set primary variant
        if (isset($this->updateVariants['production:file'])) {
            $this->primaryVariant = 'production:file';
        } elseif (isset($this->updateVariants['file'])) {
            $this->primaryVariant = 'file';
        } elseif (!empty($this->updateVariants)) {
            $this->primaryVariant = array_key_first($this->updateVariants);
        }

        if ($this->primaryVariant !== null) {
            $this->primaryChannel = $this->extractChannelFromVariant($this->primaryVariant);
            $this->channel = $this->primaryChannel;
        }
    }

    private function extractVersionFolder(string $sourcePath): string
    {
        if (preg_match('#eset_upd/([^/]+)#', $sourcePath, $m) && !empty($m[1]) && strtolower($m[1]) !== 'update.ver') {
            return $m[1];
        }

        return $this->version;
    }

    private function extractChannelFromVariant(?string $variantKey): ?string
    {
        if (empty($variantKey)) {
            return null;
        }

        if (str_contains($variantKey, ':')) {
            $parts = explode(':', $variantKey, 2);
            return $parts[0] !== '' ? $parts[0] : null;
        }

        return null;
    }

    /**
     * @param MirrorInfo[] $mirrors
     */
    public function setMirrors(array $mirrors): void
    {
        $this->mirrors = $mirrors;
    }

    public function setCredential(Credential $credential): void
    {
        $this->credential = $credential;
    }

    /**
     * Get DB version from update file
     */
    public function getDbVersion(?string $filePath = null): ?int
    {
        $path = $filePath ?? $this->getPrimaryLocalPath();

        if ($path === null) {
            return null;
        }

        return $this->parser->getDbVersion($path);
    }

    public function getPrimarySourcePath(): ?string
    {
        if ($this->primaryVariant === null || !isset($this->updateVariants[$this->primaryVariant])) {
            return null;
        }

        return $this->updateVariants[$this->primaryVariant]->source;
    }

    public function getPrimaryLocalPath(): ?string
    {
        if ($this->primaryVariant === null || !isset($this->updateVariants[$this->primaryVariant])) {
            return null;
        }

        return $this->updateVariants[$this->primaryVariant]->localPath;
    }

    /**
     * Check if all channels are up to date
     */
    public function allChannelsUpToDate(): bool
    {
        $this->log->trace($this->language->t('log.running', __METHOD__), $this->version, $this->channel);

        if (empty($this->mirrors)) {
            return false;
        }

        if (empty($this->updateVariants)) {
            return true;
        }

        $mirror = $this->mirrors[0];

        foreach ($this->updateVariants as $variantKey => $variant) {
            $localVersion = $this->getDbVersion($variant->localPath);
            $remoteVersion = $this->getRemoteVariantVersion($mirror, $variant);

            if ($localVersion !== null) {
                $this->log->trace($this->language->t('mirror.local_version', $localVersion), $this->version, $variant->getChannel());
            }
            if ($remoteVersion !== null) {
                $this->log->trace($this->language->t('mirror.remote_version', $remoteVersion), $this->version, $variant->getChannel());
            }

            if ($remoteVersion === null || $localVersion === null || $localVersion < $remoteVersion) {
                return false;
            }
        }

        return true;
    }

    private function getRemoteVariantVersion(MirrorInfo $mirror, UpdateVariant $variant): ?int
    {
        $previousChannel = $this->channel;
        $this->channel = $variant->getChannel() ?? $this->primaryChannel;

        try {
            $this->downloadUpdateVer($mirror, $variant);

            if (!file_exists($variant->tmpPath)) {
                return null;
            }

            $version = $this->parser->getDbVersion($variant->tmpPath);
            @unlink($variant->tmpPath);

            return $version;
        } finally {
            $this->channel = $previousChannel;
        }
    }

    private function downloadUpdateVer(MirrorInfo $mirror, UpdateVariant $variant): void
    {
        $this->log->trace($this->language->t('log.running', __METHOD__), $this->version, $this->channel);

        if ($this->credential === null) {
            return;
        }

        Tools::ensureDirectory(dirname($variant->tmpPath));

        $schemes = preg_match('#^https?://#i', $mirror->host)
            ? [$mirror->getBaseUrl()]
            : [$mirror->getBaseUrl(true), $mirror->getBaseUrl(false)];

        foreach ($schemes as $baseUrl) {
            $url = rtrim($baseUrl, '/') . '/' . ltrim($variant->source, '/');

            $result = $this->downloader->downloadToFile($url, $variant->tmpPath, $this->credential);

            if ($result->isSuccessful()) {
                if (file_exists($variant->tmpPath) && filesize($variant->tmpPath) === 0) {
                    $this->log->warning($this->language->t('mirror.downloaded_empty_update_ver', $mirror->host), $this->version, $this->channel);
                    @unlink($variant->tmpPath);
                    continue;
                }
                return;
            }
        }

        $this->log->warning($this->language->t('mirror.failed_download_update_ver', $mirror->host, 'n/a'), $this->version, $this->channel);
    }

    /**
     * Download signature and all files
     *
     * @return array{totalSize: ?int, totalDownloads: int, averageSpeed: ?float}
     */
    public function downloadSignature(): array
    {
        $this->log->trace($this->language->t('log.running', __METHOD__), $this->version, $this->channel);

        if (empty($this->updateVariants)) {
            return ['totalSize' => null, 'totalDownloads' => $this->totalDownloads, 'averageSpeed' => null];
        }

        $webDir = $this->config->getWebDir();
        $mirror = !empty($this->mirrors) ? $this->mirrors[0] : null;

        if ($mirror !== null) {
            $this->log->info(
                $this->language->t('mirror.selected_mirror', $mirror->host, $mirror->dbVersion ?? 'n/a'),
                $this->version,
                $this->channel
            );
        }

        $totalSize = 0;
        $totalDuration = 0.0;
        $totalDownloaded = 0;
        $allNeededFiles = [];
        $processed = false;

        foreach ($this->updateVariants as $variantKey => $variant) {
            $result = $this->processUpdateVariant($variant, $mirror);

            if (!$result['processed']) {
                continue;
            }

            $processed = true;
            $totalSize += $result['size'] ?? 0;
            $allNeededFiles = array_merge($allNeededFiles, $result['neededFiles']);
            $totalDuration += $result['duration'];
            $totalDownloaded += $result['downloaded'];
        }

        if ($processed) {
            $allNeededFiles = array_values(array_unique($allNeededFiles));
            $versionPrefix = $this->version === 'v5' ? 'ep5' : $this->version;

            // Delete files not in update list
            foreach (glob(Tools::ds($webDir, $versionPrefix . '-*'), GLOB_ONLYDIR) as $folder) {
                $deletedFiles = $this->deleteOldFiles($folder, $allNeededFiles);
                if ($deletedFiles > 0) {
                    $this->updated = true;
                    $this->log->info(
                        $this->language->t('mirror.deleted_files', $deletedFiles) . ' [' . basename($folder) . ']',
                        $this->version,
                        $this->channel
                    );
                }
            }

            // Delete empty folders
            foreach (glob(Tools::ds($webDir, $versionPrefix . '-*'), GLOB_ONLYDIR) as $folder) {
                $deletedFolders = $this->deleteEmptyFolders($folder);
                if ($deletedFolders > 0) {
                    $this->updated = true;
                    $this->log->info(
                        $this->language->t('mirror.deleted_folders', $deletedFolders) . ' [' . basename($folder) . ']',
                        $this->version,
                        $this->channel
                    );
                }
            }
        } else {
            $host = $mirror?->host ?? 'unknown';
            $this->log->warning($this->language->t('mirror.update_ver_parse_error', $host), $this->version, $this->channel);
        }

        $averageSpeed = ($totalDownloaded > 0 && $totalDuration > 0)
            ? round($totalDownloaded / $totalDuration)
            : null;

        return [
            'totalSize' => $totalSize > 0 ? $totalSize : null,
            'totalDownloads' => $this->totalDownloads,
            'averageSpeed' => $averageSpeed,
        ];
    }

    /**
     * @return array{processed: bool, size: ?int, neededFiles: string[], duration: float, downloaded: int}
     */
    private function processUpdateVariant(UpdateVariant $variant, ?MirrorInfo $mirror): array
    {
        $result = [
            'processed' => false,
            'size' => null,
            'neededFiles' => [],
            'duration' => 0.0,
            'downloaded' => 0,
        ];

        $previousChannel = $this->channel;
        $this->channel = $variant->getChannel() ?? $this->primaryChannel;

        $this->log->debug($this->language->t('mirror.processing_variant', $variant->key), $this->version, $this->channel);

        try {
            if ($mirror === null) {
                $this->log->debug($this->language->t('mirror.variant_skipped', $variant->key), $this->version, $this->channel);
                return $result;
            }

            $this->downloadUpdateVer($mirror, $variant);

            $content = @file_get_contents($variant->tmpPath);

            if ($content === false) {
                $this->log->warning(
                    $this->language->t('mirror.update_ver_parse_error', $mirror->host) . " ({$variant->key})",
                    $this->version,
                    $this->channel
                );
                @unlink($variant->tmpPath);
                return $result;
            }

            if (!preg_match_all('#\[\w+\][^\[]+#', $content, $matches)) {
                $this->log->warning(
                    $this->language->t('mirror.update_ver_parse_error', $mirror->host) . " ({$variant->key})",
                    $this->version,
                    $this->channel
                );
                @unlink($variant->tmpPath);
                return $result;
            }

            $parsed = $this->parser->parseUpdateFile(
                $matches[0],
                fn(DownloadableFile $f): bool => $this->matchesPlatform($f)
            );

            $this->platformsFound = array_merge($this->platformsFound, $parsed['platforms'] ?? []);

            $webDir = $this->config->getWebDir();
            [$downloadFiles, $neededFiles] = $this->createLinks($webDir, $parsed['files']);

            $beforeDownload = $this->totalDownloads;
            $startTime = microtime(true);

            $downloadSuccess = true;
            if (!empty($downloadFiles)) {
                $downloadSuccess = $this->downloadFiles($downloadFiles, $mirror);
                if ($downloadSuccess && !$this->unAuthorized) {
                    $this->updated = true;
                }
            } else {
                $this->log->debug($this->language->t('mirror.no_files_to_download'), $this->version, $this->channel);
            }

            $duration = !empty($downloadFiles) ? (microtime(true) - $startTime) : 0;
            $downloaded = $this->totalDownloads - $beforeDownload;

            if (!$downloadSuccess) {
                $this->log->warning($this->language->t('mirror.required_files_not_downloaded'), $this->version, $this->channel);
                @unlink($variant->tmpPath);
                return $result;
            }

            @file_put_contents($variant->localPath, $parsed['content']);
            @unlink($variant->tmpPath);

            $this->log->info(
                $this->language->t('mirror.total_size', Tools::bytesToSize1024($parsed['totalSize'])) . " ({$variant->key})",
                $this->version,
                $this->channel
            );

            if ($downloaded > 0 && $duration > 0) {
                $speed = round($downloaded / $duration);
                $this->log->info(
                    $this->language->t('mirror.total_downloaded', Tools::bytesToSize1024($downloaded)) . " ({$variant->key})",
                    $this->version,
                    $this->channel
                );
                $this->log->info(
                    $this->language->t('mirror.average_speed', Tools::bytesToSize1024((int) $speed)) . " ({$variant->key})",
                    $this->version,
                    $this->channel
                );
            }

            $result['processed'] = true;
            $result['size'] = $parsed['totalSize'];
            $result['neededFiles'] = $neededFiles;
            $result['duration'] = $duration;
            $result['downloaded'] = max($downloaded, 0);
        } finally {
            $this->channel = $previousChannel;
        }

        return $result;
    }

    /**
     * @param DownloadableFile[] $files
     * @return array{DownloadableFile[], string[]}
     */
    private function createLinks(string $dir, array $files): array
    {
        $this->log->trace($this->language->t('log.running', __METHOD__), $this->version, $this->channel);

        $oldFiles = [];
        $neededFiles = [];
        $downloadFiles = [];
        $linkMethod = $this->config->getLinkMethod();

        // Find existing files for potential linking
        $pregPattern = '/([v|ep]+)(\d+)/is';

        try {
            $iterator = new RegexIterator(
                new RecursiveIteratorIterator(
                    new RecursiveRegexIterator(
                        new RecursiveDirectoryIterator($dir),
                        '/[v|ep]+\d+[-]+/i'
                    )
                ),
                '/\.nup$/i'
            );

            $versionMatch = null;
            preg_match($pregPattern, $this->version, $versionMatch);

            foreach ($iterator as $file) {
                $filepath = $file->getPathname();
                if (is_link($filepath)) {
                    continue;
                }

                $pathVersionMatch = null;
                preg_match($pregPattern, $filepath, $pathVersionMatch);

                if ($versionMatch && is_array($pathVersionMatch) && (int) ($versionMatch[2] ?? 0) > (int) ($pathVersionMatch[2] ?? 0)) {
                    $oldFiles[] = $filepath;
                }
            }
        } catch (\Exception) {
            // Directory might not exist yet
        }

        foreach ($files as $file) {
            $path = Tools::ds($dir, $file->path);
            $neededFiles[] = $path;

            if (file_exists($path)) {
                $stat = @stat($path);
                if ($stat && !Tools::compareFiles($stat, ['size' => $file->size])) {
                    @unlink($path);
                }
            }

            if (!file_exists($path)) {
                $results = preg_grep('/' . preg_quote(basename($file->path), '/') . '$/', $oldFiles);

                if (!empty($results)) {
                    foreach ($results as $result) {
                        $stat = @stat($result);
                        if ($stat && Tools::compareFiles($stat, ['size' => $file->size])) {
                            $targetDir = dirname($path);
                            Tools::ensureDirectory($targetDir);

                            $linked = match ($linkMethod) {
                                LinkMethod::Hardlink => @link($result, $path),
                                LinkMethod::Symlink => @symlink($result, $path),
                                default => @copy($result, $path),
                            };

                            if ($linked) {
                                $this->log->info(
                                    $this->language->t(
                                        match ($linkMethod) {
                                            LinkMethod::Hardlink => 'mirror.created_hardlink',
                                            LinkMethod::Symlink => 'mirror.created_symlink',
                                            default => 'mirror.copied_file',
                                        },
                                        basename($result),
                                        basename($path)
                                    ),
                                    $this->version,
                                    $this->channel
                                );
                                $this->updated = true;
                            }
                            break;
                        }
                    }
                }

                if (!file_exists($path) && !in_array($file->path, array_map(fn($f) => $f->path, $downloadFiles), true)) {
                    $downloadFiles[] = $file;
                }
            }
        }

        return [$downloadFiles, $neededFiles];
    }

    /**
     * @param DownloadableFile[] $files
     */
    private function downloadFiles(array $files, MirrorInfo $mirror): bool
    {
        $this->log->trace($this->language->t('log.running', __METHOD__), $this->version, $this->channel);

        shuffle($files);
        $this->log->info($this->language->t('mirror.downloading_files', count($files)), $this->version, $this->channel);

        $webDir = $this->config->getWebDir();
        $baseUrl = $mirror->getBaseUrl();

        $results = $this->downloader->downloadMultiple($files, $baseUrl, $webDir, $this->credential);

        $allOk = true;

        foreach ($files as $file) {
            $result = $results[$file->path] ?? null;

            if ($result === null) {
                $allOk = false;
                $this->log->warning(
                    $this->language->t('mirror.download_result_missing', $file->path),
                    $this->version,
                    $this->channel
                );
                continue;
            }

            if (!$result->isSuccessful()) {
                $allOk = false;
                $this->log->warning(
                    $this->language->t(
                        'mirror.download_failed',
                        $file->path,
                        $result->httpCode,
                        $result->error ?? $this->language->t('common.na')
                    ),
                    $this->version,
                    $this->channel
                );
                continue;
            }

            $this->totalDownloads += $result->downloadedBytes;

            if ($result->downloadedBytes !== $file->size) {
                $allOk = false;
                $targetPath = Tools::ds($webDir, $file->path);
                @unlink($targetPath);
                $this->log->warning(
                    $this->language->t('mirror.file_size_mismatch', $file->path, $file->size, $result->downloadedBytes),
                    $this->version,
                    $this->channel
                );
            } else {
                $this->log->info(
                    $this->language->t(
                        'mirror.downloaded_file',
                        $mirror->host,
                        basename($file->path),
                        Tools::bytesToSize1024($result->downloadedBytes),
                        Tools::bytesToSize1024((int) $result->getSpeed())
                    ),
                    $this->version,
                    $this->channel
                );
            }
        }

        return $allOk;
    }

    private function matchesPlatform(DownloadableFile $file): bool
    {
        if ($file->platform === null) {
            return true;
        }

        if ($this->platforms === true || empty($this->platforms)) {
            return true;
        }

        if (!is_array($this->platforms)) {
            return true;
        }

        return in_array($file->platform, $this->platforms, true);
    }

    /**
     * @param string[] $neededFiles
     */
    private function deleteOldFiles(string $folder, array $neededFiles): int
    {
        $count = 0;

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($folder),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $fileObject) {
                if (!$fileObject->isDir()) {
                    $testFile = $fileObject->getPathname();

                    if (!in_array($testFile, $neededFiles, true)) {
                        @unlink($testFile);
                        $count++;
                    }
                }
            }
        } catch (\Exception) {
            // Ignore
        }

        return $count;
    }

    private function deleteEmptyFolders(string $folder): int
    {
        $count = 0;

        try {
            $iterator = new RecursiveDirectoryIterator($folder);

            foreach ($iterator as $fileObject) {
                $testFolder = $fileObject->getPathname();

                if (is_dir($testFolder) && count(glob(Tools::ds($testFolder, '*')) ?: []) === 0) {
                    @rmdir($testFolder);
                    $count++;
                }
            }

            if (count(glob(Tools::ds($folder, '*')) ?: []) === 0) {
                @rmdir($folder);
                $count++;
            }
        } catch (\Exception) {
            // Ignore
        }

        return $count;
    }

    public function isUpdated(): bool
    {
        return $this->updated;
    }

    public function getTotalDownloads(): int
    {
        return $this->totalDownloads;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getChannel(): ?string
    {
        return $this->channel;
    }

    /**
     * @return string[]
     */
    public function getPlatformsFound(): array
    {
        return array_values(array_unique($this->platformsFound));
    }

    /**
     * @return UpdateVariant[]
     */
    public function getUpdateVariants(): array
    {
        return $this->updateVariants;
    }
}

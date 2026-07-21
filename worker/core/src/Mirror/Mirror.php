<?php

declare(strict_types=1);

namespace Nod32Mirror\Mirror;

use Nod32Mirror\Config\Config;
use Nod32Mirror\Contract\DownloaderInterface;
use Nod32Mirror\Enum\StatusAction;
use Nod32Mirror\Enum\StatusPhase;
use Nod32Mirror\FileSystem\SafeFileOperations;
use Nod32Mirror\Log\Log;
use Nod32Mirror\Log\Language;
use Nod32Mirror\Parser\Parser;
use Nod32Mirror\Storage\BlobStore;
use Nod32Mirror\Storage\ContentIndex;
use Nod32Mirror\Storage\PublishedPathManager;
use Nod32Mirror\Storage\StorageConfig;
use Nod32Mirror\Status\StatusReporter;
use Nod32Mirror\Tools;
use Nod32Mirror\ValueObject\Credential;
use Nod32Mirror\ValueObject\DownloadableFile;
use Nod32Mirror\ValueObject\MirrorInfo;
use Nod32Mirror\ValueObject\UpdateVariant;

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

    public function __construct(
        private readonly DownloaderInterface $downloader,
        private readonly Parser $parser,
        private readonly Config $config,
        private readonly Log $log,
        private readonly Language $language,
        private readonly SafeFileOperations $fileOps,
        private readonly StorageConfig $storageConfig,
        private readonly BlobStore $blobStore,
        private readonly ContentIndex $contentIndex,
        private readonly PublishedPathManager $publishedPathManager,
        private readonly StatusReporter $statusReporter
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
                        $this->config->getTmpDir(),
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

                $tmpPath = Tools::ds($this->config->getTmpDir(), $fixedPath);
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
        $this->statusReporter->updateVersionAction(
            $this->version,
            StatusPhase::CheckingMirrorVersions,
            StatusAction::CheckLocalDatabase,
            $this->language->t('status.message.checking_local_database', $this->version),
            mirror: $mirror->host
        );

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

            if ($this->hasMissingLocalFilesForVariant($variant)) {
                return false;
            }
        }

        return true;
    }

    private function hasMissingLocalFilesForVariant(UpdateVariant $variant): bool
    {
        if (!is_file($variant->localPath)) {
            return true;
        }

        $content = $this->fileOps->readFile($variant->localPath, false);
        if ($content === null || !preg_match_all('#\[\w+\][^\[]+#', $content, $matches)) {
            return true;
        }

        $parsed = $this->parser->parseUpdateFile(
            $matches[0],
            fn(DownloadableFile $f): bool => $this->matchesPlatform($f)
        );

        $webDir = $this->config->getWebDir();
        foreach ($parsed['files'] as $file) {
            if (!is_file(Tools::ds($webDir, $file->path))) {
                return true;
            }
        }

        return false;
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
            $this->fileOps->deleteFile($variant->tmpPath);

            return $version;
        } finally {
            $this->channel = $previousChannel;
        }
    }

    private function downloadUpdateVer(MirrorInfo $mirror, UpdateVariant $variant): void
    {
        $this->log->trace($this->language->t('log.running', __METHOD__), $this->version, $this->channel);
        $this->statusReporter->updateVersionAction(
            $this->version,
            StatusPhase::ProcessingVariant,
            StatusAction::DownloadUpdateVer,
            $this->language->t('status.message.downloading_update_ver', $variant->key),
            $this->channel,
            $variant->key,
            $mirror->host
        );

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
                    $this->fileOps->deleteFile($variant->tmpPath);
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
     * @return array{totalSize: ?int, totalDownloads: int, averageSpeed: ?float, processed: bool}
     */
    public function downloadSignature(): array
    {
        $this->log->trace($this->language->t('log.running', __METHOD__), $this->version, $this->channel);

        if (empty($this->updateVariants)) {
            $this->log->debug($this->language->t('log.mirror_no_variants'), $this->version, $this->channel);
            return [
                'totalSize' => null,
                'totalDownloads' => $this->totalDownloads,
                'averageSpeed' => null,
                'processed' => false,
            ];
        }

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
        $processed = false;

        foreach ($this->updateVariants as $variantKey => $variant) {
            $this->statusReporter->updateVersionAction(
                $this->version,
                StatusPhase::ProcessingVariant,
                StatusAction::ProcessVariant,
                $this->language->t('status.message.processing_variant', $variant->key),
                $variant->getChannel() ?? $this->primaryChannel,
                $variant->key,
                $mirror?->host
            );

            $result = $this->processUpdateVariant($variant, $mirror);

            if (!$result['processed']) {
                $this->log->debug($this->language->t('log.mirror_variant_skipped', $variant->key), $this->version, $this->channel);
                continue;
            }

            $processed = true;
            $totalSize += $result['size'] ?? 0;
            $totalDuration += $result['duration'];
            $totalDownloaded += $result['downloaded'];

            $this->log->debug(
                $this->language->t('log.mirror_variant_processed', $variant->key, count($result['neededFiles']), $result['downloaded']),
                $this->version,
                $this->channel
            );
        }

        if (!$processed) {
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
            'processed' => $processed,
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
            $this->statusReporter->updateVersionAction(
                $this->version,
                StatusPhase::ProcessingVariant,
                StatusAction::ParseUpdateVer,
                $this->language->t('status.message.parsing_update_ver', $variant->key),
                $this->channel,
                $variant->key,
                $mirror->host
            );

            $content = $this->fileOps->readFile($variant->tmpPath, false);

            if ($content === null) {
                $this->log->warning(
                    $this->language->t('mirror.update_ver_parse_error', $mirror->host) . " ({$variant->key})",
                    $this->version,
                    $this->channel
                );
                $this->fileOps->deleteFile($variant->tmpPath);
                return $result;
            }

            if (!preg_match_all('#\[\w+\][^\[]+#', $content, $matches)) {
                $this->log->warning(
                    $this->language->t('mirror.update_ver_parse_error', $mirror->host) . " ({$variant->key})",
                    $this->version,
                    $this->channel
                );
                $this->fileOps->deleteFile($variant->tmpPath);
                return $result;
            }

            $parsed = $this->parser->parseUpdateFile(
                $matches[0],
                fn(DownloadableFile $f): bool => $this->matchesPlatform($f)
            );

            $parsedPlatforms = $parsed['platforms'] ?? [];
            if (is_array($this->platforms) && !empty($this->platforms)) {
                $parsedPlatforms = array_values(array_intersect($parsedPlatforms, $this->platforms));
            }

            $this->platformsFound = array_merge($this->platformsFound, $parsedPlatforms);

            $webDir = $this->config->getWebDir();
            if (!$this->allPublishedPathsSafe($parsed['files'])) {
                $this->fileOps->deleteFile($variant->tmpPath);
                return $result;
            }

            $downloadFiles = $this->collectFilesToDownload($parsed['files'], $webDir);
            $this->statusReporter->updateVersionDownloads(
                $this->version,
                plannedFiles: count($downloadFiles),
                processedFiles: 0,
                downloadedBytes: 0
            );

            $neededFiles = array_map(
                fn(DownloadableFile $file): string => Tools::ds(
                    $webDir,
                    $this->normalizePublishedPath($file->path) ?? $file->path
                ),
                $parsed['files']
            );

            $beforeDownload = $this->totalDownloads;
            $startTime = microtime(true);

            $downloadSuccess = true;
            if (!empty($downloadFiles)) {
                $this->statusReporter->updateVersionAction(
                    $this->version,
                    StatusPhase::DownloadingFiles,
                    StatusAction::DownloadBatch,
                    $this->language->t('status.message.downloading_batch', count($downloadFiles)),
                    $this->channel,
                    $variant->key,
                    $mirror->host
                );
                $downloadSuccess = $this->downloadFiles($downloadFiles, $mirror);
                if ($downloadSuccess) {
                    $this->updated = true;
                }
            } else {
                $this->log->debug($this->language->t('mirror.no_files_to_download'), $this->version, $this->channel);
            }

            $duration = !empty($downloadFiles) ? (microtime(true) - $startTime) : 0;
            $downloaded = $this->totalDownloads - $beforeDownload;
            $this->statusReporter->updateVersionDownloads(
                $this->version,
                processedFiles: count($downloadFiles),
                downloadedBytes: $this->totalDownloads
            );

            if (!$downloadSuccess) {
                $this->log->warning($this->language->t('mirror.required_files_not_downloaded'), $this->version, $this->channel);
                $this->fileOps->deleteFile($variant->tmpPath);
                return $result;
            }

            if (!$this->allFilesReady($parsed['files'], $webDir)) {
                $this->log->warning($this->language->t('mirror.required_files_not_downloaded'), $this->version, $this->channel);
                $this->fileOps->deleteFile($variant->tmpPath);
                return $result;
            }

            $this->statusReporter->updateVersionAction(
                $this->version,
                StatusPhase::PublishingIndex,
                StatusAction::PublishUpdateVer,
                $this->language->t('status.message.publishing_update_ver', $variant->key),
                $this->channel,
                $variant->key,
                $mirror->host
            );
            if (!$this->publishIndexContent($variant->localPath, $parsed['content'])) {
                $this->log->warning($this->language->t('mirror.temp_move_failed', $variant->fixedPath), $this->version, $this->channel);
                $this->fileOps->deleteFile($variant->tmpPath);
                return $result;
            }

            $this->fileOps->deleteFile($variant->tmpPath);

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
     * @return DownloadableFile[]
     */
    private function collectFilesToDownload(array $files, string $webDir): array
    {
        $downloadFiles = [];

        foreach ($files as $file) {
            $relativePath = $this->normalizePublishedPath($file->path);
            if ($relativePath === null) {
                $downloadFiles[] = $file;
                continue;
            }

            $targetPath = Tools::ds($webDir, $relativePath);

            if (!is_file($targetPath)) {
                $downloadFiles[] = $file;
                continue;
            }

            clearstatcache(true, $targetPath);
            $currentSize = (int) (filesize($targetPath) ?: 0);
            if ($currentSize !== $file->size) {
                $downloadFiles[] = $file;
                continue;
            }

            $knownHash = $this->contentIndex->getPublishedHash($relativePath);
            if ($knownHash === null) {
                $downloadFiles[] = $file;
                continue;
            }

            $actualHash = $this->blobStore->hashFile($targetPath);
            if ($actualHash !== $knownHash) {
                $downloadFiles[] = $file;
            }
        }

        return $downloadFiles;
    }

    /**
     * @param DownloadableFile[] $files
     */
    private function allFilesReady(array $files, string $webDir): bool
    {
        foreach ($files as $file) {
            $relativePath = $this->normalizePublishedPath($file->path);
            if ($relativePath === null) {
                return false;
            }

            $targetPath = Tools::ds($webDir, $relativePath);

            if (!is_file($targetPath)) {
                return false;
            }

            clearstatcache(true, $targetPath);
            if ((int) (filesize($targetPath) ?: 0) !== $file->size) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param DownloadableFile[] $files
     */
    private function allPublishedPathsSafe(array $files): bool
    {
        foreach ($files as $file) {
            if ($this->normalizePublishedPath($file->path) === null) {
                $this->log->warning($this->language->t('mirror.unsafe_path_in_update_ver', $file->path), $this->version, $this->channel);
                return false;
            }
        }

        return true;
    }

    private function normalizePublishedPath(string $path): ?string
    {
        $path = str_replace('\\', '/', trim($path));

        if ($path === '' || preg_match('#^[A-Za-z]:/#', $path)) {
            return null;
        }

        $path = ltrim($path, '/');
        if ($path === '') {
            return null;
        }

        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                return null;
            }
        }

        return $path;
    }

    private function publishIndexContent(string $targetPath, string $content): bool
    {
        $tempPath = $this->createPublishTempPath($targetPath);

        if (!$this->fileOps->writeFile($tempPath, $content)) {
            return false;
        }

        if (@rename($tempPath, $targetPath)) {
            return true;
        }

        $this->fileOps->deleteFile($tempPath);
        return false;
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
        $tmpRoot = $this->blobStore->getDownloadTmpRoot();
        $results = $this->downloader->downloadMultiple($files, $baseUrl, $tmpRoot, $this->credential);

        $allOk = true;

        foreach ($files as $file) {
            $result = $results[$file->path] ?? null;
            $tempPath = Tools::ds($tmpRoot, $file->path);
            $relativePath = $this->normalizePublishedPath($file->path);

            if ($relativePath === null) {
                $allOk = false;
                $this->fileOps->deleteFile($tempPath);
                $this->log->warning($this->language->t('mirror.unsafe_path_in_update_ver', $file->path), $this->version, $this->channel);
                continue;
            }

            $targetPath = Tools::ds($webDir, $relativePath);

            if ($result === null) {
                $allOk = false;
                $this->fileOps->deleteFile($tempPath);
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
                $this->fileOps->deleteFile($tempPath);
                continue;
            }

            $this->totalDownloads += $result->downloadedBytes;

            if ($result->downloadedBytes !== $file->size || !is_file($tempPath)) {
                $allOk = false;
                $this->fileOps->deleteFile($tempPath);
                $this->log->warning(
                    $this->language->t('mirror.file_size_mismatch', $file->path, $file->size, $result->downloadedBytes),
                    $this->version,
                    $this->channel
                );
                continue;
            }

            $hash = $this->blobStore->hashFile($tempPath);
            if ($hash === null) {
                $allOk = false;
                $this->log->warning(
                    $this->language->t('mirror.hash_calculation_failed', $this->blobStore->getHashAlgorithm(), $file->path),
                    $this->version,
                    $this->channel
                );
                $this->fileOps->deleteFile($tempPath);
                continue;
            }

            $blobPath = $this->blobStore->ensureBlob($tempPath, $hash, $file->size);
            if ($blobPath === null) {
                $allOk = false;
                $this->log->warning($this->language->t('storage.blob_store_failed', $file->path), $this->version, $this->channel);
                $this->fileOps->deleteFile($tempPath);
                continue;
            }

            if (!$this->publishedPathManager->publishFromBlob($blobPath, $targetPath, $hash, $file->size)) {
                $allOk = false;
                $this->log->warning(
                    $this->language->t('mirror.temp_move_failed', $file->path),
                    $this->version,
                    $this->channel
                );
                $this->fileOps->deleteFile($tempPath);
                continue;
            }

            $this->fileOps->deleteFile($tempPath);
            $this->contentIndex->recordPublished(
                $relativePath,
                $hash,
                $file->size,
                $this->version,
                $this->channel ?? 'default',
                $this->storageConfig->getLinkMethod()->value,
                $blobPath
            );

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

        return $allOk;
    }

    private function createPublishTempPath(string $targetPath): string
    {
        $this->fileOps->createDirectory(dirname($targetPath));

        do {
            $tempPath = $targetPath . '.publish-' . bin2hex(random_bytes(6)) . '.tmp';
        } while (file_exists($tempPath));

        return $tempPath;
    }

    /**
     * Re-read local update.ver variants for metadata such as platforms.
     */
    public function rebuildProvidesFromLocalVariants(): void
    {
        if (empty($this->updateVariants)) {
            return;
        }

        foreach ($this->updateVariants as $variant) {
            if (!is_file($variant->localPath)) {
                continue;
            }

            $content = $this->fileOps->readFile($variant->localPath, false);
            if ($content === null) {
                continue;
            }

            if (!preg_match_all('#\[\w+\][^\[]+#', $content, $matches)) {
                continue;
            }

            $parsed = $this->parser->parseUpdateFile(
                $matches[0],
                fn(DownloadableFile $f): bool => $this->matchesPlatform($f)
            );

            $parsedPlatforms = $parsed['platforms'] ?? [];
            if (is_array($this->platforms) && !empty($this->platforms)) {
                $parsedPlatforms = array_values(array_intersect($parsedPlatforms, $this->platforms));
            }

            $this->platformsFound = array_merge($this->platformsFound, $parsedPlatforms);
        }
    }

    /**
     * @return UpdateVariant[]
     */
    public function getUpdateVariants(): array
    {
        return $this->updateVariants;
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

    public function isUpdated(): bool
    {
        return $this->updated;
    }

    /**
     * @return string[]
     */
    public function getPlatformsFound(): array
    {
        return array_values(array_unique($this->platformsFound));
    }
}

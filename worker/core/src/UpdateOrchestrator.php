<?php

declare(strict_types=1);

namespace Nod32Mirror;

use Nod32Mirror\Config\Config;
use Nod32Mirror\Config\VersionConfig;
use Nod32Mirror\Contract\DownloaderInterface;
use Nod32Mirror\Contract\KeyStorageInterface;
use Nod32Mirror\Key\KeyFinder;
use Nod32Mirror\Key\KeyManager;
use Nod32Mirror\Log\Log;
use Nod32Mirror\Log\Language;
use Nod32Mirror\Mirror\Mirror;
use Nod32Mirror\Parser\Parser;
use Nod32Mirror\Report\HtmlReportGenerator;
use Nod32Mirror\Report\JsonReportGenerator;
use Nod32Mirror\ValueObject\Credential;
use Nod32Mirror\ValueObject\MirrorInfo;

final class UpdateOrchestrator
{
    private int $startTime;

    /** @var array<string, int> */
    private array $totalSizes = [];

    /** @var array<string, int> */
    private array $totalDownloads = [];

    /** @var array<string, float> */
    private array $averageSpeeds = [];

    /** @var array<string, string[]> */
    private array $platformsFound = [];

    public function __construct(
        private readonly Config $config,
        private readonly VersionConfig $versionConfig,
        private readonly Log $log,
        private readonly Language $language,
        private readonly DownloaderInterface $downloader,
        private readonly KeyStorageInterface $keyStorage,
        private readonly KeyManager $keyManager,
        private readonly KeyFinder $keyFinder,
        private readonly Parser $parser,
        private readonly Mirror $mirror,
        private readonly HtmlReportGenerator $htmlGenerator,
        private readonly JsonReportGenerator $jsonGenerator,
        /** @var array<string, array<string, mixed>> */
        private readonly array $directories
    ) {
        $this->startTime = time();
    }

    public function run(): void
    {
        $this->log->trace($this->language->t('log.running', __METHOD__));
        $this->log->info($this->language->t('script.run', $this->getVersion()));

        $this->loadStoredSizes();

        $enabledVersions = $this->versionConfig->getEnabledVersions();
        $this->log->info($this->language->t('script.enabled_versions', implode(', ', $enabledVersions)));

        foreach ($enabledVersions as $version) {
            $this->processVersion($version);
        }

        $this->cleanupTmpDirectory();
        $this->logSummary();
        $this->generateReports();

        $this->log->info($this->language->t('script.total_working_time', Tools::secondsToHumanReadable(time() - $this->startTime)));
        $this->log->info($this->language->t('script.stopping'));
    }

    private function processVersion(string $version): void
    {
        if (!isset($this->directories[$version])) {
            $this->log->warning($this->language->t('config.version_not_in_directories', $version), $version);
            return;
        }

        $dirConfig = $this->directories[$version];
        $this->log->info($this->language->t('script.processing_version', $version), $version);
        $this->log->trace($this->language->t('mirror.init_for_version_in_dir', $version, $dirConfig['name'] ?? $version), $version);

        $platforms = $this->versionConfig->getVersionPlatforms($version);
        $channels = $this->versionConfig->getVersionChannels($version);

        $this->mirror->init($version, $dirConfig, $platforms, $channels);

        $sourceFile = $this->mirror->getPrimarySourcePath();

        if ($sourceFile === null) {
            $this->log->warning($this->language->t('script.stopped'), $version);
            return;
        }

        $mirrors = $this->config->getMirrorList();

        // Try to find working key
        $keyResult = $this->keyManager->findWorkingKey($version, $sourceFile, $mirrors);

        if ($keyResult === null) {
            // Try to find keys from web
            $keyResult = $this->keyFinder->findKeys($version, $sourceFile, $mirrors);

            if ($keyResult === null) {
                $this->log->warning($this->language->t('script.stopped'), $version);
                return;
            }
        }

        /** @var Credential $credential */
        $credential = $keyResult['credential'];
        /** @var MirrorInfo[] $workingMirrors */
        $workingMirrors = $keyResult['mirrors'];

        // Check database versions on mirrors
        $this->checkMirrorVersions($workingMirrors, $credential, $version, $sourceFile);

        $this->mirror->setCredential($credential);
        $this->mirror->setMirrors($workingMirrors);

        $oldVersion = $this->mirror->getDbVersion();

        if (!empty($workingMirrors)) {
            $primaryMirror = $workingMirrors[0];

            if ($this->mirror->allChannelsUpToDate()) {
                $relevantVersion = $oldVersion ?? $primaryMirror->dbVersion;
                $this->log->informer(
                    $this->language->t('report.database_relevant', $relevantVersion),
                    $version
                );

                $prevSize = $this->totalSizes[$version] ?? 0;
                $this->setDatabaseSize($version, $prevSize);
            } else {
                $result = $this->mirror->downloadSignature();

                $this->setDatabaseSize($version, $result['totalSize']);
                $this->platformsFound[$version] = $this->mirror->getPlatformsFound();

                if (!$this->mirror->isUpdated() && $oldVersion !== null && $oldVersion !== 0) {
                    if ($primaryMirror->dbVersion !== null && $oldVersion >= $primaryMirror->dbVersion) {
                        $this->log->informer($this->language->t('report.database_not_updated'), $version);
                    }
                } else {
                    $this->totalSizes[$version] = $result['totalSize'] ?? 0;
                    $this->totalDownloads[$version] = $result['totalDownloads'];

                    if ($result['averageSpeed'] !== null) {
                        $this->averageSpeeds[$version] = $result['averageSpeed'];
                    }

                    if ($oldVersion && $primaryMirror->dbVersion !== null && $oldVersion < $primaryMirror->dbVersion) {
                        $this->log->informer(
                            $this->language->t('report.database_updated_from_to', $oldVersion, $primaryMirror->dbVersion),
                            $version
                        );
                    } else {
                        $this->log->informer(
                            $this->language->t('report.database_updated_to', $primaryMirror->dbVersion ?? 'n/a'),
                            $version
                        );
                    }
                }

                $this->touchTimestamp($version);
            }
        } else {
            $this->log->warning($this->language->t('mirror.all_down'), $version);
        }

        $this->log->debug($this->language->t('script.version_completed', $version), $version);
    }

    /**
     * @param MirrorInfo[] $mirrors
     */
    private function checkMirrorVersions(array &$mirrors, Credential $credential, string $version, string $sourceFile): void
    {
        $this->log->debug($this->language->t('mirror.checking_mirrors', count($mirrors), $version), $version);

        $maxVersion = 0;
        $updatedMirrors = [];

        foreach ($mirrors as $mirror) {
            $dbVersion = $this->getRemoteMirrorVersion($mirror, $credential, $sourceFile);

            if ($dbVersion !== null) {
                $maxVersion = max($maxVersion, $dbVersion);
                $updatedMirrors[] = $mirror->withDbVersion($dbVersion);
                $this->log->debug($this->language->t('mirror.remote_version', $dbVersion), $version);
            } else {
                $this->log->warning($this->language->t('mirror.skipped_unreadable_update_ver', $mirror->host), $version);
            }
        }

        // Filter to only mirrors with max version
        $mirrors = array_values(array_filter(
            $updatedMirrors,
            static fn(MirrorInfo $m): bool => $m->dbVersion === $maxVersion
        ));
    }

    private function getRemoteMirrorVersion(MirrorInfo $mirror, Credential $credential, string $sourceFile): ?int
    {
        $url = $mirror->buildUrl($sourceFile);
        $tmpFile = Tools::ds(TMP_PATH, 'version_check_' . md5($mirror->host) . '.ver');

        $result = $this->downloader->downloadToFile($url, $tmpFile, $credential);

        if (!$result->isSuccessful()) {
            return null;
        }

        $version = $this->parser->getDbVersion($tmpFile);
        @unlink($tmpFile);

        return $version;
    }

    private function loadStoredSizes(): void
    {
        $sizesFile = Tools::ds($this->config->getDataDir(), DATABASES_SIZE);

        if (!file_exists($sizesFile)) {
            return;
        }

        $content = @file_get_contents($sizesFile);

        if ($content === false) {
            return;
        }

        $decoded = json_decode($content, true);

        if (is_array($decoded)) {
            $this->totalSizes = array_map('intval', $decoded);
        }
    }

    private function setDatabaseSize(string $version, ?int $size): void
    {
        $this->log->trace($this->language->t('log.running', __METHOD__), $version);

        $sizesFile = Tools::ds($this->config->getDataDir(), DATABASES_SIZE);
        $sizes = $this->totalSizes;
        $sizes[$version] = $size ?? 0;

        file_put_contents(
            $sizesFile,
            json_encode($sizes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $this->totalSizes = $sizes;
    }

    private function touchTimestamp(string $version): void
    {
        $this->log->trace($this->language->t('log.running', __METHOD__), $version);

        $tsFile = Tools::ds($this->config->getDataDir(), SUCCESSFUL_TIMESTAMP);
        $timestamps = [];

        if (file_exists($tsFile)) {
            $content = @file_get_contents($tsFile);
            if ($content !== false) {
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    $timestamps = $decoded;
                }
            }
        }

        $timestamps[$version] = time();

        file_put_contents(
            $tsFile,
            json_encode($timestamps, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function getTimestamp(string $version): ?int
    {
        $tsFile = Tools::ds($this->config->getDataDir(), SUCCESSFUL_TIMESTAMP);

        if (!file_exists($tsFile)) {
            return null;
        }

        $content = @file_get_contents($tsFile);

        if ($content === false) {
            return null;
        }

        $decoded = json_decode($content, true);

        if (!is_array($decoded) || !isset($decoded[$version])) {
            return null;
        }

        return (int) $decoded[$version];
    }

    private function cleanupTmpDirectory(): void
    {
        foreach (glob(Tools::ds(TMP_PATH, '*')) ?: [] as $folder) {
            Tools::clearDirectory($folder);
            @rmdir($folder);
        }
    }

    private function logSummary(): void
    {
        $totalSize = array_sum($this->totalSizes);
        $totalDownloads = array_sum($this->totalDownloads);
        $totalSpeeds = array_sum($this->averageSpeeds);
        $speedCount = count($this->averageSpeeds);

        $this->log->info($this->language->t('report.total_size_all_databases', Tools::bytesToSize1024($totalSize)));

        if ($totalDownloads > 0) {
            $this->log->info($this->language->t('report.total_downloaded_all_databases', Tools::bytesToSize1024($totalDownloads)));
        }

        if ($totalSpeeds > 0 && $speedCount > 0) {
            $avgSpeed = (int) ($totalSpeeds / $speedCount);
            $this->log->info($this->language->t('report.average_speed_all_databases', Tools::bytesToSize1024($avgSpeed)));
        }
    }

    private function generateReports(): void
    {
        $scriptConfig = $this->config->getOrDefault('script', []);
        $generateConfig = $scriptConfig['generate'] ?? [];

        $metadata = $this->buildMetadata();
        $webDir = $this->config->getWebDir();

        if (!empty($generateConfig['html']['enabled'])) {
            $filename = $generateConfig['html']['filename'] ?? 'index.html';
            $this->htmlGenerator->save($metadata, Tools::ds($webDir, $filename));
        }

        if (!empty($generateConfig['json']['enabled'])) {
            $filename = $generateConfig['json']['filename'] ?? 'index.json';
            $this->jsonGenerator->save($metadata, Tools::ds($webDir, $filename));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMetadata(): array
    {
        $webDir = $this->config->getWebDir();
        $scriptConfig = $this->config->getOrDefault('script', []);
        $generateConfig = $scriptConfig['generate'] ?? [];
        $exportCredentials = !empty($generateConfig['export_credentials']);

        $enabledVersions = $this->versionConfig->getEnabledVersions();
        $versions = [];
        $latestUpdate = null;

        foreach ($enabledVersions as $version) {
            if (!isset($this->directories[$version])) {
                continue;
            }

            $dirConfig = $this->directories[$version];

            $platforms = $this->versionConfig->getVersionPlatforms($version);
            $channels = $this->versionConfig->getVersionChannels($version);

            // Re-init mirror temporarily for metadata
            $this->mirror->init($version, $dirConfig, $platforms, $channels);

            $channelsInfo = $this->buildChannelsInfo($version, $dirConfig, $webDir);
            $foundPlatforms = $this->platformsFound[$version] ?? $this->mirror->getPlatformsFound();

            if ($platforms !== true && is_array($platforms) && !empty($platforms)) {
                $foundPlatforms = array_values(array_intersect($foundPlatforms, $platforms));
            }

            if (!empty($foundPlatforms)) {
                natcasesort($foundPlatforms);
                $foundPlatforms = array_values(array_unique($foundPlatforms));
            }

            $dbVersion = $this->mirror->getDbVersion();
            $sizeBytes = $this->totalSizes[$version] ?? null;
            $timestamp = $this->getTimestamp($version);
            $lastUpdate = $timestamp !== null ? date('c', $timestamp) : null;

            if ($timestamp !== null && ($latestUpdate === null || $timestamp > $latestUpdate)) {
                $latestUpdate = $timestamp;
            }

            $versionData = [
                'name' => $dirConfig['name'] ?? $version,
                'platforms' => $foundPlatforms,
                'channels' => $channelsInfo,
                'database' => [
                    'version' => $dbVersion,
                    'size' => [
                        'bytes' => $sizeBytes,
                        'pretty' => $sizeBytes !== null ? $this->formatSizeDecimal($sizeBytes) : null,
                    ],
                    'last_update' => $lastUpdate,
                    'last_update_ts' => $timestamp,
                ],
            ];

            if ($exportCredentials) {
                $versionData['credentials'] = $this->getCredentialsForVersion($version);
            }

            $versions[$version] = $versionData;
        }

        $totalBytes = 0;
        foreach ($enabledVersions as $v) {
            $totalBytes += $this->totalSizes[$v] ?? 0;
        }

        return [
            'title' => $this->language->t('report.title_update_server'),
            'last_update' => $latestUpdate !== null ? date('c', $latestUpdate) : date('c', $this->startTime),
            'last_update_ts' => $latestUpdate ?? $this->startTime,
            'total_size' => [
                'bytes' => $totalBytes,
                'pretty' => $this->formatSizeDecimal($totalBytes),
            ],
            'versions' => $versions,
        ];
    }

    /**
     * @param array<string, mixed> $dirConfig
     * @return array<string, array<string, mixed>>
     */
    private function buildChannelsInfo(string $version, array $dirConfig, string $webDir): array
    {
        $channelsInfo = [];

        if (isset($dirConfig['channels'])) {
            foreach ($dirConfig['channels'] as $channelName => $channelData) {
                $filePath = isset($channelData['file']) && $channelData['file'] !== false
                    ? $this->getUpdateFilePath($version, $dirConfig, $webDir, $channelName, 'file')
                    : null;

                $dllPath = isset($channelData['dll']) && $channelData['dll'] !== false
                    ? $this->getUpdateFilePath($version, $dirConfig, $webDir, $channelName, 'dll')
                    : null;

                $channelUpdatePath = $filePath ?? $dllPath;
                $channelDbVersion = $channelUpdatePath !== null
                    ? $this->parser->getDbVersion($channelUpdatePath)
                    : null;

                $channelsInfo[$channelName] = [
                    'database_version' => $channelDbVersion,
                    'files' => [
                        'file' => $this->getPublicPath($filePath, $webDir),
                        'dll' => $this->getPublicPath($dllPath, $webDir),
                    ],
                ];
            }
        } else {
            $filePath = isset($dirConfig['file']) && $dirConfig['file'] !== false
                ? $this->getUpdateFilePath($version, $dirConfig, $webDir, null, 'file')
                : null;

            $dllPath = isset($dirConfig['dll']) && $dirConfig['dll'] !== false
                ? $this->getUpdateFilePath($version, $dirConfig, $webDir, null, 'dll')
                : null;

            $channelsInfo['default'] = [
                'database_version' => null,
                'files' => [
                    'file' => $this->getPublicPath($filePath, $webDir),
                    'dll' => $this->getPublicPath($dllPath, $webDir),
                ],
            ];
        }

        return $channelsInfo;
    }

    /**
     * @param array<string, mixed> $dirConfig
     */
    private function getUpdateFilePath(string $version, array $dirConfig, string $webDir, ?string $channel, string $type): ?string
    {
        $sourceFile = null;

        if (isset($dirConfig['channels']) && $channel !== null) {
            $sourceFile = $dirConfig['channels'][$channel][$type] ?? null;
        } elseif (!isset($dirConfig['channels'])) {
            $sourceFile = $dirConfig[$type] ?? null;
        }

        if ($sourceFile === null || $sourceFile === false) {
            return null;
        }

        $verFolder = $version;
        if (preg_match('#eset_upd/([^/]+)#', $sourceFile, $m) && !empty($m[1]) && strtolower($m[1]) !== 'update.ver') {
            $verFolder = $m[1];
        }

        if (isset($dirConfig['channels']) && $channel !== null) {
            $localSuffix = $type === 'dll'
                ? Tools::ds('dll', 'update.ver')
                : 'update.ver';
            $fixedPath = Tools::ds('eset_upd', $verFolder, $channel, $localSuffix);
        } else {
            if (preg_match('#^eset_upd/update\.ver$#i', $sourceFile)) {
                $fixedPath = Tools::ds('eset_upd', $verFolder, 'update.ver');
            } else {
                $fixedPath = $sourceFile;
            }
        }

        return Tools::ds($webDir, $fixedPath);
    }

    private function getPublicPath(?string $fullPath, string $webDir): ?string
    {
        if ($fullPath === null) {
            return null;
        }

        $normalizedBase = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $webDir), DIRECTORY_SEPARATOR);
        $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fullPath);

        if ($normalizedBase !== '' && str_starts_with($normalizedPath, $normalizedBase)) {
            $relative = ltrim(substr($normalizedPath, strlen($normalizedBase)), DIRECTORY_SEPARATOR);
        } else {
            $relative = $normalizedPath;
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }

    /**
     * @return array<array{login: string, password: string, version: ?string}>
     */
    private function getCredentialsForVersion(string $version): array
    {
        $credentials = $this->keyStorage->getValidKeys($version);
        $result = [];

        foreach ($credentials as $cred) {
            $result[] = [
                'login' => $cred->login,
                'password' => $cred->password,
                'version' => $version,
            ];
        }

        return $result;
    }

    private function formatSizeDecimal(int $bytes, int $decimalPlaces = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB'];
        $value = (float) $bytes;
        $index = 0;

        while ($value >= 1000 && $index < count($units) - 1) {
            $value /= 1000;
            $index++;
        }

        $formatted = number_format($value, $decimalPlaces, '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');

        if ($formatted === '') {
            $formatted = '0';
        }

        return $formatted . ' ' . $units[$index];
    }

    private function getVersion(): string
    {
        return '20250121';
    }
}

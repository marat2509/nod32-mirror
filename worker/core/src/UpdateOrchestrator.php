<?php

declare(strict_types=1);

namespace Nod32Mirror;

use Nod32Mirror\Config\Config;
use Nod32Mirror\Config\VersionConfig;
use Nod32Mirror\Contract\DownloaderInterface;
use Nod32Mirror\Contract\KeyStorageInterface;
use Nod32Mirror\Enum\MirrorStrategy;
use Nod32Mirror\Enum\StatusAction;
use Nod32Mirror\Enum\StatusPhase;
use Nod32Mirror\Enum\StatusState;
use Nod32Mirror\Key\KeyFinder;
use Nod32Mirror\Key\KeyManager;
use Nod32Mirror\Log\Log;
use Nod32Mirror\Log\Language;
use Nod32Mirror\Mirror\Mirror;
use Nod32Mirror\Mirror\MirrorDiscovery;
use Nod32Mirror\Mirror\MirrorSelector;
use Nod32Mirror\Parser\Parser;
use Nod32Mirror\Report\HtmlReportGenerator;
use Nod32Mirror\Report\JsonReportGenerator;
use Nod32Mirror\Status\StatusReporter;
use Nod32Mirror\Storage\BlobStore;
use Nod32Mirror\Storage\ContentIndex;
use Nod32Mirror\Storage\ReferenceCollector;
use Nod32Mirror\Storage\StorageConfig;
use Nod32Mirror\Storage\StorageGarbageCollector;
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

    /** @var array<string, MirrorInfo[]> Ordered mirror candidates per version */
    private array $selectedMirrorsByVersion = [];

    /** @var Credential|null Global working credential */
    private ?Credential $globalCredential = null;

    /** @var resource|null */
    private $lockHandle = null;

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
        private readonly MirrorDiscovery $mirrorDiscovery,
        private readonly MirrorSelector $mirrorSelector,
        private readonly HtmlReportGenerator $htmlGenerator,
        private readonly JsonReportGenerator $jsonGenerator,
        private readonly StatusReporter $statusReporter,
        private readonly StorageConfig $storageConfig,
        private readonly BlobStore $blobStore,
        private readonly ContentIndex $contentIndex,
        private readonly ReferenceCollector $referenceCollector,
        private readonly StorageGarbageCollector $storageGarbageCollector,
        /** @var array<string, array<string, mixed>> */
        private readonly array $directories
    ) {
        $this->startTime = time();
    }

    public function run(): void
    {
        $this->log->trace($this->language->t('log.running', __METHOD__));
        $this->log->info($this->language->t('script.run', $this->getVersion()));

        if (!$this->acquireUpdateLock()) {
            $this->log->warning($this->language->t('storage.update_lock_busy'));
            return;
        }

        try {
            $enabledVersions = $this->versionConfig->getEnabledVersions();
            $this->statusReporter->startRun(
                $this->getVersion(),
                getmypid() ?: 'unknown',
                $this->buildVersionNames($enabledVersions)
            );
            $this->statusReporter->setCurrent(
                StatusPhase::AcquiringLock,
                StatusAction::AcquireLock,
                $this->language->t('status.message.lock_acquired')
            );

            $this->statusReporter->setCurrent(
                StatusPhase::LoadingState,
                StatusAction::LoadSizes,
                $this->language->t('status.message.loading_state')
            );
            $this->loadStoredSizes();

            $this->statusReporter->setCurrent(
                StatusPhase::InitializingStorage,
                StatusAction::InitStorage,
                $this->language->t('status.message.initializing_storage')
            );
            $this->initStorage();

            $this->statusReporter->setCurrent(
                StatusPhase::StartupCleanup,
                StatusAction::CleanupStorageTmp,
                $this->language->t('status.message.cleaning_storage_tmp')
            );
            $this->cleanupStorageTmpDirectory();

            $this->statusReporter->setCurrent(
                StatusPhase::StartupCleanup,
                StatusAction::CleanupPublishTmp,
                $this->language->t('status.message.cleaning_publish_tmp')
            );
            $this->cleanupPublishTempFiles();

            $this->log->info($this->language->t('script.enabled_versions', implode(', ', $enabledVersions)));

            $this->prepareMirrors($enabledVersions);

            foreach ($enabledVersions as $version) {
                $this->processVersion($version);
            }

            $this->statusReporter->setCurrent(
                StatusPhase::StartupCleanup,
                StatusAction::CleanupTmp,
                $this->language->t('status.message.cleaning_tmp')
            );
            $this->cleanupTmpDirectory();
            $this->finalizeStorage();
            $this->logSummary();
            $this->generateReports();
            $this->statusReporter->completeRun($this->language->t('status.message.run_completed'));

            $this->log->info($this->language->t('script.total_working_time', Tools::secondsToHumanReadable(time() - $this->startTime)));
            $this->log->info($this->language->t('script.stopping'));
        } catch (\Throwable $e) {
            $this->statusReporter->failRun($e);
            throw $e;
        } finally {
            $this->blobStore->cleanupRunTmp();
            $this->releaseUpdateLock();
        }
    }

    /**
     * Discover and order mirrors before processing versions.
     *
     * @param string[] $enabledVersions
     */
    private function prepareMirrors(array $enabledVersions): void
    {
        $strategy = $this->config->getMirrorStrategy();
        $discoveryEnabled = $this->config->isMirrorDiscoveryEnabled();

        if (!$discoveryEnabled && $strategy !== MirrorStrategy::Best) {
            $this->log->debug($this->language->t('mirror.selection_strategy', $strategy->label()));
            return;
        }

        $this->statusReporter->setCurrent(
            StatusPhase::SelectingMirrors,
            StatusAction::PrepareMirrors,
            $this->language->t('status.message.preparing_mirrors')
        );
        if ($strategy === MirrorStrategy::Best) {
            $this->log->info($this->language->t('mirror.preselecting_best'));
        }

        // Collect test URLs for all versions
        $testUrls = $this->collectTestUrls($enabledVersions);

        if (empty($testUrls)) {
            $this->log->warning($this->language->t('mirror.no_test_urls'));
            return;
        }

        // Find a working credential first
        $credential = $this->findGlobalCredential($enabledVersions);

        if ($credential === null) {
            $this->log->warning($this->language->t('mirror.no_credential_for_testing'));
            return;
        }

        $this->globalCredential = $credential;

        $configuredMirrors = $this->config->getMirrorList();
        $discoveredMirrors = $this->mirrorDiscovery->discover(
            $configuredMirrors,
            $credential,
            $testUrls
        );
        $mirrors = $this->buildMirrorPool($configuredMirrors, $discoveredMirrors);

        foreach ($testUrls as $version => $testUrl) {
            $this->selectedMirrorsByVersion[$version] = $this->mirrorSelector->selectMirrors(
                $mirrors,
                $credential,
                [$version => $testUrl]
            );
        }

        $this->log->info($this->language->t(
            'mirror.selection_ready',
            count($mirrors),
            count($this->selectedMirrorsByVersion)
        ));
    }

    /**
     * @param string[] $configuredMirrors
     * @param string[] $discoveredMirrors
     * @return string[]
     */
    private function buildMirrorPool(array $configuredMirrors, array $discoveredMirrors): array
    {
        if (
            $this->config->getMirrorDiscoveryPool() === 'discovered'
            && $discoveredMirrors !== []
        ) {
            return $discoveredMirrors;
        }

        $pool = [];
        foreach (array_merge($configuredMirrors, $discoveredMirrors) as $mirror) {
            $key = strtolower(trim($mirror));
            if ($key !== '' && !isset($pool[$key])) {
                $pool[$key] = trim($mirror);
            }
        }

        return array_values($pool);
    }

    /**
     * Collect test URLs for all enabled versions
     *
     * @param string[] $enabledVersions
     * @return array<string, string> Map of version => source path
     */
    private function collectTestUrls(array $enabledVersions): array
    {
        $testUrls = [];

        foreach ($enabledVersions as $version) {
            if (!isset($this->directories[$version])) {
                continue;
            }

            $dirConfig = $this->directories[$version];
            $platforms = $this->versionConfig->getVersionPlatforms($version);
            $channels = $this->versionConfig->getVersionChannels($version);

            $this->mirror->init($version, $dirConfig, $platforms, $channels);
            $sourceFile = $this->mirror->getPrimarySourcePath();

            if ($sourceFile !== null) {
                $testUrls[$version] = $sourceFile;
            }
        }

        return $testUrls;
    }

    /**
     * Find a working credential from any version
     *
     * @param string[] $enabledVersions
     */
    private function findGlobalCredential(array $enabledVersions): ?Credential
    {
        $mirrors = $this->config->getMirrorList();

        foreach ($enabledVersions as $version) {
            if (!isset($this->directories[$version])) {
                continue;
            }

            $dirConfig = $this->directories[$version];
            $platforms = $this->versionConfig->getVersionPlatforms($version);
            $channels = $this->versionConfig->getVersionChannels($version);

            $this->mirror->init($version, $dirConfig, $platforms, $channels);
            $sourceFile = $this->mirror->getPrimarySourcePath();

            if ($sourceFile === null) {
                continue;
            }

            // Try to find working key for this version
            $keyResult = $this->keyManager->findWorkingKey($version, $sourceFile, $mirrors);

            if ($keyResult !== null) {
                $this->log->debug($this->language->t('mirror.found_credential_for_testing', $version));
                return $keyResult['credential'];
            }

            // Try to find keys from web
            $keyResult = $this->keyFinder->findKeys($version, $sourceFile, $mirrors);

            if ($keyResult !== null) {
                return $keyResult['credential'];
            }
        }

        return null;
    }

    private function processVersion(string $version): void
    {
        if (!isset($this->directories[$version])) {
            $this->statusReporter->startVersion(
                $version,
                StatusAction::ProcessVersion,
                $this->language->t('status.message.processing_version', $version)
            );
            $this->statusReporter->finishVersion(
                $version,
                StatusState::Skipped,
                StatusAction::ProcessVersion,
                $this->language->t('config.version_not_in_directories', $version)
            );
            $this->log->warning($this->language->t('config.version_not_in_directories', $version), $version);
            return;
        }

        $dirConfig = $this->directories[$version];
        $this->statusReporter->startVersion(
            $version,
            StatusAction::ProcessVersion,
            $this->language->t('status.message.processing_version', $version)
        );
        $this->log->info($this->language->t('script.processing_version', $version), $version);
        $this->log->trace($this->language->t('mirror.init_for_version_in_dir', $version, $dirConfig['name'] ?? $version), $version);

        $platforms = $this->versionConfig->getVersionPlatforms($version);
        $channels = $this->versionConfig->getVersionChannels($version);

        $this->mirror->init($version, $dirConfig, $platforms, $channels);

        $sourceFile = $this->mirror->getPrimarySourcePath();

        if ($sourceFile === null) {
            $this->statusReporter->finishVersion(
                $version,
                StatusState::Skipped,
                StatusAction::ProcessVersion,
                $this->language->t('script.stopped')
            );
            $this->log->warning($this->language->t('script.stopped'), $version);
            return;
        }

        $preferredMirrors = $this->selectedMirrorsByVersion[$version] ?? [];
        $mirrors = $preferredMirrors !== []
            ? array_map(static fn(MirrorInfo $mirror): string => $mirror->host, $preferredMirrors)
            : $this->config->getMirrorList();

        // Try to find working key (use global credential if available)
        $keyResult = null;

        if ($this->globalCredential !== null) {
            // Validate global credential for this version
            $keyResult = $this->keyManager->testKey($this->globalCredential, $version, $sourceFile, $mirrors);

            if ($keyResult === null) {
                $this->keyManager->markKeyInvalid($this->globalCredential, $version);
            }
        }

        if ($keyResult === null) {
            $keyResult = $this->keyManager->findWorkingKey($version, $sourceFile, $mirrors);
        }

        if ($keyResult === null) {
            // Try to find keys from web
            $keyResult = $this->keyFinder->findKeys($version, $sourceFile, $mirrors);

            if ($keyResult === null) {
                $this->statusReporter->finishVersion(
                    $version,
                    StatusState::Failed,
                    StatusAction::SearchKeys,
                    $this->language->t('script.stopped')
                );
                $this->log->warning($this->language->t('script.stopped'), $version);
                return;
            }
        }

        /** @var Credential $credential */
        $credential = $keyResult['credential'];

        /** @var MirrorInfo[] $workingMirrors */
        $workingMirrors = $this->orderWorkingMirrors($preferredMirrors, $keyResult['mirrors']);

        // Check database versions on mirrors
        $this->checkMirrorVersions($workingMirrors, $credential, $version, $sourceFile);

        $this->mirror->setCredential($credential);
        $this->mirror->setMirrors($workingMirrors);

        $oldVersion = $this->mirror->getDbVersion();

        if (!empty($workingMirrors)) {
            $primaryMirror = $workingMirrors[0];
            $this->statusReporter->updateVersionDatabase(
                $version,
                local: $oldVersion,
                remote: $primaryMirror->dbVersion
            );

            if ($this->mirror->allChannelsUpToDate()) {
                $relevantVersion = $oldVersion ?? $primaryMirror->dbVersion;
                $this->statusReporter->updateVersionDatabase($version, result: $relevantVersion);
                $this->log->informer(
                    $this->language->t('report.database_relevant', $relevantVersion),
                    $version
                );

                $prevSize = $this->totalSizes[$version] ?? 0;
                $this->setDatabaseSize($version, $prevSize);

                // Re-read local indexes so reporting metadata stays populated
                // when no download happens in the current run.
                $this->mirror->rebuildProvidesFromLocalVariants();
                $this->platformsFound[$version] = $this->mirror->getPlatformsFound();
            } else {
                $result = $this->mirror->downloadSignature();

                if (empty($result['processed'])) {
                    $this->statusReporter->finishVersion(
                        $version,
                        StatusState::Failed,
                        StatusAction::DownloadBatch,
                        $this->language->t('script.stopped')
                    );
                    $this->log->warning($this->language->t('script.stopped'), $version);
                    return;
                }

                $this->statusReporter->updateVersionDatabase($version, result: $primaryMirror->dbVersion);
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
            $this->statusReporter->finishVersion(
                $version,
                StatusState::Failed,
                StatusAction::CheckingMirrorVersions,
                $this->language->t('mirror.all_down')
            );
            $this->log->warning($this->language->t('mirror.all_down'), $version);
            return;
        }

        $this->statusReporter->finishVersion(
            $version,
            StatusState::Completed,
            StatusAction::Complete,
            $this->language->t('script.version_completed', $version)
        );
        $this->log->debug($this->language->t('script.version_completed', $version), $version);
    }

    /**
     * @param MirrorInfo[] $preferredMirrors
     * @param MirrorInfo[] $workingMirrors
     * @return MirrorInfo[]
     */
    private function orderWorkingMirrors(array $preferredMirrors, array $workingMirrors): array
    {
        if ($preferredMirrors === []) {
            return $workingMirrors;
        }

        $workingByHost = [];
        foreach ($workingMirrors as $mirror) {
            $workingByHost[strtolower($mirror->host)] = $mirror;
        }

        $ordered = [];
        foreach ($preferredMirrors as $preferredMirror) {
            $key = strtolower($preferredMirror->host);
            if (isset($workingByHost[$key])) {
                $ordered[] = $workingByHost[$key];
                unset($workingByHost[$key]);
            }
        }

        return array_merge($ordered, array_values($workingByHost));
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
            $this->statusReporter->updateVersionAction(
                $version,
                StatusPhase::CheckingMirrorVersions,
                StatusAction::CheckRemoteVersion,
                $this->language->t('status.message.checking_remote_version', $mirror->host),
                mirror: $mirror->host
            );
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
        if ($maxVersion > 0) {
            $this->statusReporter->updateVersionDatabase($version, remote: $maxVersion);
        }
    }

    private function getRemoteMirrorVersion(MirrorInfo $mirror, Credential $credential, string $sourceFile): ?int
    {
        $url = $mirror->buildUrl($sourceFile);
        $tmpFile = Tools::ds($this->config->getTmpDir(), 'version_check_' . md5($mirror->host) . '.ver');

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

        Tools::writeJsonPrettyTabsFile($sizesFile, $sizes);

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

        Tools::writeJsonPrettyTabsFile($tsFile, $timestamps);
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
        foreach (glob(Tools::ds($this->config->getTmpDir(), '*')) ?: [] as $folder) {
            Tools::clearDirectory($folder);
            @rmdir($folder);
        }
    }

    private function cleanupStorageTmpDirectory(): void
    {
        $tmpDir = $this->storageConfig->getTmpDir();
        if (!is_dir($tmpDir)) {
            return;
        }

        foreach (glob(Tools::ds($tmpDir, '*')) ?: [] as $folder) {
            Tools::clearDirectory($folder);
            @rmdir($folder);
        }
    }

    private function cleanupPublishTempFiles(): void
    {
        $webDir = $this->config->getWebDir();
        if (!is_dir($webDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($webDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $fileObject) {
            if ($fileObject->isDir()) {
                continue;
            }

            $path = $fileObject->getPathname();
            if (preg_match('/\.publish-[a-f0-9]+\.tmp$/i', $path)) {
                @unlink($path);
            }
        }
    }

    private function acquireUpdateLock(): bool
    {
        $lockDir = Tools::ds($this->config->getDataDir(), 'locks');
        Tools::ensureDirectory($lockDir);

        $lockPath = Tools::ds($lockDir, 'update.lock');
        $handle = @fopen($lockPath, 'c');
        if ($handle === false) {
            return false;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }

        ftruncate($handle, 0);
        fwrite($handle, sprintf("%s\npid=%s\n", ContentIndex::now(), getmypid() ?: 'unknown'));
        $this->lockHandle = $handle;

        return true;
    }

    private function releaseUpdateLock(): void
    {
        if ($this->lockHandle === null) {
            return;
        }

        flock($this->lockHandle, LOCK_UN);
        fclose($this->lockHandle);
        $this->lockHandle = null;
    }

    private function initStorage(): void
    {
        $path = $this->storageConfig->getIndexPath();
        $this->contentIndex->load($path, $this->storageConfig->getHashAlgorithm());
        $this->contentIndex->setHashAlgorithm($this->storageConfig->getHashAlgorithm());
        $this->log->debug($this->language->t('storage.index_initialized', $path));
    }

    private function finalizeStorage(): void
    {
        $this->statusReporter->setCurrent(
            StatusPhase::FinalizingStorage,
            StatusAction::CollectReferences,
            $this->language->t('status.message.collecting_references')
        );
        $this->statusReporter->updateStorage(StatusState::Running, StatusAction::CollectReferences);

        $webDir = $this->config->getWebDir();
        $references = $this->referenceCollector->collect($webDir);
        $this->contentIndex->syncVersionRefs($references);

        $this->statusReporter->setCurrent(
            StatusPhase::FinalizingStorage,
            StatusAction::RunStorageGc,
            $this->language->t('status.message.running_storage_gc')
        );
        $this->statusReporter->updateStorage(StatusState::Running, StatusAction::RunStorageGc);

        $gcState = $this->storageGarbageCollector->run($webDir, $references);
        $this->contentIndex->syncVersionRefs($references);

        $this->statusReporter->setCurrent(
            StatusPhase::FinalizingStorage,
            StatusAction::FinalizeStorage,
            $this->language->t('status.message.saving_storage_state')
        );
        $this->statusReporter->updateStorage(StatusState::Running, StatusAction::FinalizeStorage);

        $this->storageGarbageCollector->saveState(Tools::ds($this->config->getDataDir(), 'gc-state.json'), $gcState);
        $this->contentIndex->save($this->storageConfig->getIndexPath());
        $this->statusReporter->updateStorage(StatusState::Completed);
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
        $reportsConfig = $this->config->getOrDefault('web.reports', []);

        $metadata = $this->buildMetadata();
        $webDir = $this->config->getWebDir();

        if (!empty($reportsConfig['html']['enabled'])) {
            $path = $reportsConfig['html']['file'] ?? 'index.html';
            $this->statusReporter->setCurrent(
                StatusPhase::GeneratingIndex,
                StatusAction::WriteIndexHtml,
                $this->language->t('status.message.generating_html_report')
            );
            $this->htmlGenerator->save($metadata, Tools::ds($webDir, (string) $path));
        }

        if (!empty($reportsConfig['json']['enabled'])) {
            $path = $reportsConfig['json']['file'] ?? 'index.json';
            $this->statusReporter->setCurrent(
                StatusPhase::GeneratingIndex,
                StatusAction::WriteIndexJson,
                $this->language->t('status.message.generating_json_report')
            );
            $this->jsonGenerator->save($metadata, Tools::ds($webDir, (string) $path));
        }
    }

    /**
     * @param string[] $versions
     * @return array<string, string>
     */
    private function buildVersionNames(array $versions): array
    {
        $names = [];

        foreach ($versions as $version) {
            $names[$version] = (string) ($this->directories[$version]['name'] ?? $version);
        }

        return $names;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMetadata(): array
    {
        $webDir = $this->config->getWebDir();
        $exportCredentials = !empty($this->config->getOrDefault('web.reports.export_credentials', false));

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

            // if (empty($foundPlatforms)) {
            //     // Fallback for cases when platforms were not persisted during update phase:
            //     // re-read local update.ver variants and extract platforms for metadata.
            //     $this->mirror->rebuildProvidesFromLocalVariants();
            //     $foundPlatforms = $this->mirror->getPlatformsFound();
            // }

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
        return APP_VERSION;
    }
}

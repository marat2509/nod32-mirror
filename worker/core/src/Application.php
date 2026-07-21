<?php

declare(strict_types=1);

namespace Nod32Mirror;

use Nod32Mirror\Config\Config;
use Nod32Mirror\Config\VersionConfig;
use Nod32Mirror\Download\GuzzleDownloader;
use Nod32Mirror\FileSystem\SafeFileOperations;
use Nod32Mirror\Key\JsonKeyStorage;
use Nod32Mirror\Key\KeyFinder;
use Nod32Mirror\Key\KeyManager;
use Nod32Mirror\Log\Language;
use Nod32Mirror\Log\Log;
use Nod32Mirror\Mirror\Mirror;
use Nod32Mirror\Mirror\MirrorSelector;
use Nod32Mirror\Parser\Parser;
use Nod32Mirror\Report\HtmlReportGenerator;
use Nod32Mirror\Report\JsonReportGenerator;
use Nod32Mirror\Status\StatusReporter;
use Nod32Mirror\Storage\BlobStore;
use Nod32Mirror\Storage\ContentIndex;
use Nod32Mirror\Storage\PublishedPathManager;
use Nod32Mirror\Storage\ReferenceCollector;
use Nod32Mirror\Storage\StorageConfig;
use Nod32Mirror\Storage\StorageGarbageCollector;

/**
 * Application bootstrap and dependency wiring
 */
final class Application
{
    private Config $config;
    private Language $language;
    private Log $log;
    private VersionConfig $versionConfig;
    private GuzzleDownloader $downloader;
    private JsonKeyStorage $keyStorage;
    private KeyManager $keyManager;
    private KeyFinder $keyFinder;
    private Parser $parser;
    private SafeFileOperations $fileOps;
    private StorageConfig $storageConfig;
    private BlobStore $blobStore;
    private ContentIndex $contentIndex;
    private PublishedPathManager $publishedPathManager;
    private ReferenceCollector $referenceCollector;
    private StorageGarbageCollector $storageGarbageCollector;
    private Mirror $mirror;
    private MirrorSelector $mirrorSelector;
    private HtmlReportGenerator $htmlGenerator;
    private JsonReportGenerator $jsonGenerator;
    private StatusReporter $statusReporter;
    private UpdateOrchestrator $orchestrator;

    /**
     * @param array<string, array<string, mixed>> $directories
     */
    public function __construct(array $directories, string $configPath = CONF_FILE)
    {
        // Bootstrap core services
        $this->config = new Config($configPath);
        $this->config->init();

        $this->language = new Language();
        $this->language->init((string) $this->config->getOrDefault('runtime.locale.language', 'en'));

        $this->log = new Log($this->config, $this->language);
        $this->log->init();

        $statusConfig = $this->config->getOrDefault('web.reports.status', []);
        $statusConfig = is_array($statusConfig) ? $statusConfig : [];
        $statusPath = Tools::ds($this->config->getWebDir(), (string) ($statusConfig['file'] ?? 'status.json'));
        $this->statusReporter = new StatusReporter(
            $statusPath,
            $this->language,
            $this->buildVersionNames($directories),
            !empty($statusConfig['enabled'])
        );

        // Build remaining services
        $this->versionConfig = new VersionConfig($this->config, $directories);
        $this->downloader = new GuzzleDownloader($this->config, $this->log, $this->language);
        $this->parser = new Parser($this->log, $this->language);

        $keyFilePath = Tools::ds($this->config->getDataDir(), KEY_FILE);
        $this->keyStorage = new JsonKeyStorage($keyFilePath, $this->log, $this->language);

        $this->keyManager = new KeyManager(
            $this->keyStorage,
            $this->downloader,
            $this->config,
            $this->log,
            $this->language,
            $this->statusReporter,
            $this->parser
        );

        $this->keyFinder = new KeyFinder(
            $this->keyManager,
            $this->downloader,
            $this->parser,
            $this->config,
            $this->log,
            $this->language,
            $this->statusReporter
        );

        // File system services
        $this->fileOps = new SafeFileOperations($this->log, $this->language);
        $this->storageConfig = new StorageConfig($this->config);
        $this->blobStore = new BlobStore($this->storageConfig, $this->fileOps, $this->log, $this->language);
        $this->contentIndex = new ContentIndex($this->fileOps, $this->language);
        $this->publishedPathManager = new PublishedPathManager($this->storageConfig, $this->blobStore, $this->fileOps);

        $this->mirror = new Mirror(
            $this->downloader,
            $this->parser,
            $this->config,
            $this->log,
            $this->language,
            $this->fileOps,
            $this->storageConfig,
            $this->blobStore,
            $this->contentIndex,
            $this->publishedPathManager,
            $this->statusReporter
        );

        $this->referenceCollector = new ReferenceCollector(
            $this->versionConfig,
            $this->parser,
            $this->fileOps,
            $this->log,
            $this->language,
            $this->mirror,
            $directories
        );

        $this->storageGarbageCollector = new StorageGarbageCollector(
            $this->config,
            $this->storageConfig,
            $this->contentIndex,
            $this->blobStore,
            $this->fileOps,
            $this->log,
            $this->language
        );

        $this->mirrorSelector = new MirrorSelector(
            $this->downloader,
            $this->config,
            $this->log,
            $this->language,
            $this->statusReporter
        );

        $this->htmlGenerator = new HtmlReportGenerator($this->config, $this->log, $this->language);
        $this->jsonGenerator = new JsonReportGenerator($this->config, $this->log, $this->language);

        $this->orchestrator = new UpdateOrchestrator(
            $this->config,
            $this->versionConfig,
            $this->log,
            $this->language,
            $this->downloader,
            $this->keyStorage,
            $this->keyManager,
            $this->keyFinder,
            $this->parser,
            $this->mirror,
            $this->mirrorSelector,
            $this->htmlGenerator,
            $this->jsonGenerator,
            $this->statusReporter,
            $this->storageConfig,
            $this->blobStore,
            $this->contentIndex,
            $this->referenceCollector,
            $this->storageGarbageCollector,
            $directories
        );
    }

    public function run(): void
    {
        $this->orchestrator->run();
    }

    /**
     * @param array<string, array<string, mixed>> $directories
     * @return array<string, string>
     */
    private function buildVersionNames(array $directories): array
    {
        $names = [];

        foreach ($directories as $version => $settings) {
            $names[$version] = (string) ($settings['name'] ?? $version);
        }

        return $names;
    }
}

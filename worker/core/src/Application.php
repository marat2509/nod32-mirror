<?php

declare(strict_types=1);

namespace Nod32Mirror;

use Nod32Mirror\Config\Config;
use Nod32Mirror\Config\VersionConfig;
use Nod32Mirror\Download\GuzzleDownloader;
use Nod32Mirror\Key\JsonKeyStorage;
use Nod32Mirror\Key\KeyFinder;
use Nod32Mirror\Key\KeyManager;
use Nod32Mirror\Log\Language;
use Nod32Mirror\Log\Log;
use Nod32Mirror\Mirror\Mirror;
use Nod32Mirror\Parser\Parser;
use Nod32Mirror\Report\HtmlReportGenerator;
use Nod32Mirror\Report\JsonReportGenerator;

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
    private Mirror $mirror;
    private HtmlReportGenerator $htmlGenerator;
    private JsonReportGenerator $jsonGenerator;
    private UpdateOrchestrator $orchestrator;

    /** @var array<string, array<string, mixed>> */
    private array $directories;

    /**
     * @param array<string, array<string, mixed>> $directories
     */
    public function __construct(array $directories, string $configPath = CONF_FILE)
    {
        $this->directories = $directories;

        // Bootstrap core services
        $this->config = new Config($configPath);
        $this->config->init();

        $this->language = new Language();
        $scriptConfig = $this->config->getOrDefault('script', []);
        $this->language->init($scriptConfig['language'] ?? 'en');

        $this->log = new Log($this->config, $this->language);
        $this->log->init();

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
            $this->language
        );

        $this->keyFinder = new KeyFinder(
            $this->keyManager,
            $this->downloader,
            $this->parser,
            $this->config,
            $this->log,
            $this->language
        );

        $this->mirror = new Mirror(
            $this->downloader,
            $this->parser,
            $this->config,
            $this->log,
            $this->language
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
            $this->htmlGenerator,
            $this->jsonGenerator,
            $directories
        );
    }

    public function run(): void
    {
        $this->orchestrator->run();
    }

    public function getConfig(): Config
    {
        return $this->config;
    }

    public function getLog(): Log
    {
        return $this->log;
    }

    public function getLanguage(): Language
    {
        return $this->language;
    }

    public function getVersionConfig(): VersionConfig
    {
        return $this->versionConfig;
    }

    public function getDownloader(): GuzzleDownloader
    {
        return $this->downloader;
    }

    public function getKeyStorage(): JsonKeyStorage
    {
        return $this->keyStorage;
    }

    public function getKeyManager(): KeyManager
    {
        return $this->keyManager;
    }

    public function getKeyFinder(): KeyFinder
    {
        return $this->keyFinder;
    }

    public function getParser(): Parser
    {
        return $this->parser;
    }

    public function getMirror(): Mirror
    {
        return $this->mirror;
    }

    public function getHtmlGenerator(): HtmlReportGenerator
    {
        return $this->htmlGenerator;
    }

    public function getJsonGenerator(): JsonReportGenerator
    {
        return $this->jsonGenerator;
    }

    public function getOrchestrator(): UpdateOrchestrator
    {
        return $this->orchestrator;
    }
}

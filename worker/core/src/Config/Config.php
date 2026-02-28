<?php

declare(strict_types=1);

namespace Nod32Mirror\Config;

use Nod32Mirror\Enum\LinkMethod;
use Nod32Mirror\Enum\LogLevel;
use Nod32Mirror\Enum\MirrorStrategy;
use Nod32Mirror\Enum\ProxyType;
use Nod32Mirror\Exception\ConfigException;
use Nod32Mirror\Exception\ConfigKeyNotFoundException;
use Nod32Mirror\Tools;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class Config
{
    /** @var array<string, mixed> */
    private array $config = [];

    private bool $initialized = false;

    public function __construct(
        private readonly string $configPath = CONF_FILE
    ) {
    }

    /**
     * @throws ConfigException
     */
    public function init(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->loadConfig();
        $this->validateConfig();
        $this->setupEnvironment();

        $this->initialized = true;
    }

    /**
     * @throws ConfigException
     */
    private function loadConfig(): void
    {
        if (!file_exists($this->configPath)) {
            throw new ConfigException('Configuration file not found: ' . $this->configPath);
        }

        if (!is_readable($this->configPath)) {
            throw new ConfigException('Configuration file is not readable: ' . $this->configPath);
        }

        try {
            $parsed = Yaml::parseFile($this->configPath);
        } catch (ParseException $e) {
            throw new ConfigException('Failed to parse configuration: ' . $e->getMessage(), 0, $e);
        }

        if (empty($parsed) || !is_array($parsed)) {
            throw new ConfigException('Configuration file is empty or invalid');
        }

        $this->config = $this->normalizeConfig($parsed);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function normalizeConfig(array $config): array
    {
        $config = $this->arrayChangeKeyCaseRecursive($config, CASE_LOWER);

        $config['script'] = $this->normalizeScript($config['script'] ?? []);
        $config['connection'] = $this->normalizeConnection($config['connection'] ?? []);
        $config['log'] = $this->normalizeLog($config['log'] ?? []);
        $config['data'] = $this->normalizeSection($config, 'data');
        $config['find'] = $this->normalizeFind($config['find'] ?? []);
        $config['eset'] = $this->normalizeSection($config, 'eset');

        $config['eset']['mirror'] = $this->normalizeMirrorConfig($config['eset']['mirror'] ?? []);
        $config['eset']['versions'] = $this->normalizeVersions($config['eset']['versions'] ?? []);

        return $config;
    }

    /**
     * @param array<string, mixed> $scriptConfig
     * @return array<string, mixed>
     */
    private function normalizeScript(array $scriptConfig): array
    {
        $defaults = [
            'language' => 'en',
            'codepage' => 'utf-8',
            'timezone' => null,
            'memory_limit' => '32M',
            'debug_update' => false,
            'link_method' => LinkMethod::Hardlink->value,
            'debug_html' => false,
            'web_dir' => 'www',
            'generate' => [
                'export_credentials' => false,
                'json' => ['enabled' => true, 'filename' => 'index.json'],
                'html' => ['enabled' => true, 'filename' => 'index.html', 'codepage' => 'utf-8', 'only_table' => false],
            ],
        ];

        $script = array_replace_recursive($defaults, $scriptConfig);

        $script['debug_update'] = !empty($script['debug_update']);
        $script['debug_html'] = !empty($script['debug_html']);
        $script['generate']['export_credentials'] = !empty($script['generate']['export_credentials']);
        $script['generate']['json']['enabled'] = !empty($script['generate']['json']['enabled']);
        $script['generate']['html']['enabled'] = !empty($script['generate']['html']['enabled']);
        $script['generate']['html']['only_table'] = !empty($script['generate']['html']['only_table']);

        return $script;
    }

    /**
     * @param array<string, mixed> $connectionConfig
     * @return array<string, mixed>
     */
    private function normalizeConnection(array $connectionConfig): array
    {
        $defaults = [
            'threads' => 32,
            'timeout' => [
                'download' => 30,
                'connect' => 5,
            ],
            'retries' => [
                'attempts' => 5,
                'delay' => 1,
            ],
            'speed_limit' => 0,
            'proxy' => [
                'enabled' => false,
                'type' => ProxyType::Http->value,
                'server' => '',
                'port' => 80,
                'user' => '',
                'password' => '',
            ],
        ];

        $connection = $defaults;

        // threads
        if (isset($connectionConfig['threads'])) {
            $connection['threads'] = (int) $connectionConfig['threads'];
        } elseif (isset($connectionConfig['multidownload']['threads'])) {
            // Legacy format
            $connection['threads'] = (int) $connectionConfig['multidownload']['threads'];
        }

        // timeout (new nested format or legacy flat format)
        if (isset($connectionConfig['timeout']) && is_array($connectionConfig['timeout'])) {
            $connection['timeout']['download'] = (int) ($connectionConfig['timeout']['download'] ?? $defaults['timeout']['download']);
            $connection['timeout']['connect'] = (int) ($connectionConfig['timeout']['connect'] ?? $defaults['timeout']['connect']);
        } elseif (isset($connectionConfig['timeout']) && is_numeric($connectionConfig['timeout'])) {
            // Legacy flat format
            $connection['timeout']['download'] = (int) $connectionConfig['timeout'];
            $connection['timeout']['connect'] = (int) ($connectionConfig['connect_timeout'] ?? $defaults['timeout']['connect']);
        }

        // retries (new nested format or legacy flat format)
        if (isset($connectionConfig['retries']) && is_array($connectionConfig['retries'])) {
            $connection['retries']['attempts'] = (int) ($connectionConfig['retries']['attempts'] ?? $defaults['retries']['attempts']);
            $connection['retries']['delay'] = (int) ($connectionConfig['retries']['delay'] ?? $defaults['retries']['delay']);
        } elseif (isset($connectionConfig['max_retries'])) {
            // Legacy flat format
            $connection['retries']['attempts'] = (int) $connectionConfig['max_retries'];
            $connection['retries']['delay'] = (int) (($connectionConfig['retry_delay'] ?? 1000) / 1000); // convert ms to s
        }

        $connection['speed_limit'] = (int) ($connectionConfig['speed_limit'] ?? $defaults['speed_limit']);

        if (isset($connectionConfig['proxy']) && is_array($connectionConfig['proxy'])) {
            $connection['proxy']['enabled'] = !empty($connectionConfig['proxy']['enabled']);
            $connection['proxy']['type'] = $connectionConfig['proxy']['type'] ?? ProxyType::Http->value;
            $connection['proxy']['server'] = $connectionConfig['proxy']['server'] ?? '';
            $connection['proxy']['port'] = (int) ($connectionConfig['proxy']['port'] ?? 80);
            $connection['proxy']['user'] = $connectionConfig['proxy']['user'] ?? '';
            $connection['proxy']['password'] = $connectionConfig['proxy']['password'] ?? '';
        }

        return $connection;
    }

    /**
     * @param array<string, mixed> $logConfig
     * @return array<string, mixed>
     */
    private function normalizeLog(array $logConfig): array
    {
        $defaults = [
            'stdout' => ['enabled' => true, 'level' => LogLevel::Debug->value],
            'file' => [
                'enabled' => true,
                'level' => LogLevel::Debug->value,
                'dir' => 'log',
                'rotate' => ['enabled' => true, 'size' => '100K', 'qty' => 5],
            ],
        ];

        $merged = array_replace_recursive($defaults, $logConfig);

        $merged['stdout']['enabled'] = !empty($merged['stdout']['enabled']);
        $merged['stdout']['level'] = LogLevel::fromMixed($merged['stdout']['level'] ?? LogLevel::Debug)->value;

        $merged['file']['enabled'] = !empty($merged['file']['enabled']);
        $merged['file']['level'] = LogLevel::fromMixed($merged['file']['level'] ?? LogLevel::Debug)->value;
        $merged['file']['rotate']['enabled'] = !empty($merged['file']['rotate']['enabled']);
        $merged['file']['rotate']['qty'] = (int) ($merged['file']['rotate']['qty'] ?? 5);

        $rotateSize = $merged['file']['rotate']['size'] ?? '100K';
        $merged['file']['rotate']['size'] = is_numeric($rotateSize)
            ? (int) $rotateSize
            : Tools::human2bytes((string) $rotateSize);

        return $merged;
    }

    /**
     * @param array<string, mixed> $findConfig
     * @return array<string, mixed>
     */
    private function normalizeFind(array $findConfig): array
    {
        $defaults = [
            'enabled' => false,
            'auto' => false,
            'remove_invalid_keys' => false,
            'errors_quantity' => 1,
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'headers' => [],
            'query' => [],
            'number_attempts' => 1,
            'pageindex' => 1,
            'page_qty' => 1,
            'recursion_level' => 1,
            'pattern' => '',
            'system' => null,
            'count_keys' => 1,
        ];

        $find = array_replace_recursive($defaults, $findConfig);

        $find['enabled'] = !empty($find['enabled']);
        $find['auto'] = !empty($find['auto']);
        $find['remove_invalid_keys'] = !empty($find['remove_invalid_keys']);
        $find['errors_quantity'] = max(1, (int) ($find['errors_quantity'] ?? 1));

        if (is_string($find['headers'])) {
            $find['headers'] = array_filter(array_map('trim', preg_split('/[\r\n,]+/', $find['headers']) ?: []));
        }

        $find['query'] = $this->normalizeQueryList($find['query'] ?? []);

        return $find;
    }

    /**
     * @param array<string, mixed> $versionsConfig
     * @return array<string, mixed>
     */
    private function normalizeVersions(array $versionsConfig): array
    {
        $overrides = $versionsConfig['version_overrides'] ?? $versionsConfig['overrides'] ?? [];

        $normalized = [
            'platforms' => $this->normalizeList($versionsConfig['platforms'] ?? []),
            'channels' => $this->normalizeList($versionsConfig['channels'] ?? []),
            'overrides' => [],
        ];

        if (!empty($overrides) && is_array($overrides)) {
            foreach ($overrides as $version => $settings) {
                $settings = is_array($settings) ? $settings : [];
                $normalized['overrides'][$version] = [
                    'platforms' => $this->normalizeList($settings['platforms'] ?? []),
                    'channels' => $this->normalizeList($settings['channels'] ?? []),
                    'mirror' => !empty($settings['mirror']),
                ];
            }
        }

        $normalized['version_overrides'] = $normalized['overrides'];

        return $normalized;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function normalizeSection(array $config, string $key): array
    {
        return (isset($config[$key]) && is_array($config[$key])) ? $config[$key] : [];
    }

    /**
     * Normalize mirror configuration
     *
     * Format: eset.mirror: { strategy: "best", list: ["host1", "host2"] }
     *
     * @param array<string, mixed> $mirrorConfig
     * @return array{strategy: string, list: string[]}
     */
    private function normalizeMirrorConfig(array $mirrorConfig): array
    {
        return [
            'strategy' => $this->normalizeMirrorStrategy($mirrorConfig['strategy'] ?? 'random'),
            'list' => $this->normalizeMirrorList($mirrorConfig['list'] ?? []),
        ];
    }

    /**
     * @return string[]
     */
    private function normalizeMirrorList(mixed $mirrorList): array
    {
        if (is_array($mirrorList)) {
            return array_values(array_filter(array_map('trim', $mirrorList), 'strlen'));
        }

        if (is_string($mirrorList)) {
            return Tools::parseCommaList($mirrorList);
        }

        return [];
    }

    /**
     * @return string[]|true
     */
    private function normalizeList(mixed $value): array|bool
    {
        if ($value === true || $value === null) {
            return true;
        }

        if ($value === false || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value), 'strlen'));
        }

        if (is_string($value)) {
            return Tools::parseCommaList($value);
        }

        return [];
    }

    /**
     * @return string[]
     */
    private function normalizeQueryList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value), 'strlen'));
        }

        if (is_string($value) && strlen(trim($value)) > 0) {
            return [trim($value)];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function arrayChangeKeyCaseRecursive(array $input, int $case = CASE_LOWER): array
    {
        $output = [];

        foreach ($input as $key => $value) {
            $newKey = $case === CASE_LOWER ? strtolower((string) $key) : strtoupper((string) $key);
            $output[$newKey] = is_array($value) ? $this->arrayChangeKeyCaseRecursive($value, $case) : $value;
        }

        return $output;
    }

    /**
     * @throws ConfigException
     */
    private function validateConfig(): void
    {
        if (!in_array(PHP_OS_FAMILY, ['Darwin', 'Linux', 'BSD', 'Windows'])) {
            throw new ConfigException('Unsupported operating system: ' . PHP_OS_FAMILY);
        }

        if (empty($this->config['eset']['mirror'])) {
            throw new ConfigException('Mirror list is empty. Please configure eset.mirror in config file.');
        }

        $logConfig = $this->config['log']['file'] ?? [];
        if (!empty($logConfig['rotate']['enabled']) && ($logConfig['rotate']['qty'] ?? 0) < 1) {
            throw new ConfigException('Log rotation quantity must be at least 1');
        }
    }

    private function setupEnvironment(): void
    {
        $scriptConfig = $this->config['script'];

        if (!empty($scriptConfig['timezone'])) {
            @date_default_timezone_set($scriptConfig['timezone']);
        } else {
            @date_default_timezone_set(@date_default_timezone_get() ?: 'UTC');
        }

        $this->setupDirectories();
    }

    private function setupDirectories(): void
    {
        $webDir = $this->getWebDir();
        $dataDir = $this->getDataDir();
        $logDir = $this->config['log']['file']['dir'] ?? 'log';

        // Handle relative paths
        if (!$this->isAbsolutePath($webDir)) {
            $webDir = Tools::ds(SELF, $webDir);
            $this->config['script']['web_dir'] = $webDir;
        }

        if (!$this->isAbsolutePath($dataDir)) {
            $dataDir = Tools::ds(SELF, $dataDir);
            $this->config['data']['dir'] = $dataDir;
        }

        if (!$this->isAbsolutePath($logDir)) {
            $logDir = Tools::ds(SELF, $logDir);
            $this->config['log']['file']['dir'] = $logDir;
        }

        // Clean trailing separators
        $this->config['script']['web_dir'] = Tools::cleanPath($this->config['script']['web_dir']);
        $this->config['data']['dir'] = Tools::cleanPath($dataDir);
        $this->config['log']['file']['dir'] = Tools::cleanPath($logDir);

        // Create directories
        Tools::ensureDirectory(PATTERN);
        Tools::ensureDirectory($this->config['data']['dir']);
        Tools::ensureDirectory($this->config['log']['file']['dir']);
        Tools::ensureDirectory($this->config['script']['web_dir']);
        Tools::ensureDirectory(TMP_PATH);

        if (!empty($this->config['script']['debug_html'])) {
            Tools::ensureDirectory(Tools::ds($this->config['data']['dir'], DEBUG_DIR));
        }
    }

    private function isAbsolutePath(string $path): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $path);
        }

        return str_starts_with($path, '/');
    }

    /**
     * Get configuration value by dot-notation key
     *
     * @throws ConfigKeyNotFoundException
     */
    public function get(string $key): mixed
    {
        $this->ensureInitialized();

        if (isset($this->config[$key])) {
            return $this->config[$key];
        }

        $parts = explode('.', $key);
        $current = $this->config;

        foreach ($parts as $part) {
            $foundKey = $this->findArrayKey($current, $part);

            if ($foundKey === null || !isset($current[$foundKey])) {
                throw new ConfigKeyNotFoundException($key);
            }

            $current = $current[$foundKey];
        }

        return $current;
    }

    /**
     * Get configuration value or default if not found
     */
    public function getOrDefault(string $key, mixed $default = null): mixed
    {
        try {
            return $this->get($key);
        } catch (ConfigKeyNotFoundException) {
            return $default;
        }
    }

    public function getWebDir(): string
    {
        return $this->config['script']['web_dir'] ?? Tools::ds(SELF, 'www');
    }

    public function getDataDir(): string
    {
        return $this->config['data']['dir'] ?? Tools::ds(SELF, 'data');
    }

    public function getLinkMethod(): LinkMethod
    {
        $method = $this->config['script']['link_method'] ?? 'copy';
        return LinkMethod::fromString($method);
    }

    public function getTimeout(): int
    {
        return (int) ($this->config['connection']['timeout']['download'] ?? 30);
    }

    public function getConnectTimeout(): int
    {
        return (int) ($this->config['connection']['timeout']['connect'] ?? 5);
    }

    public function getMaxThreads(): int
    {
        return (int) ($this->config['connection']['threads'] ?? 32);
    }

    public function getMaxRetries(): int
    {
        return (int) ($this->config['connection']['retries']['attempts'] ?? 3);
    }

    public function getRetryDelay(): int
    {
        // Returns delay in milliseconds (config is in seconds)
        return (int) (($this->config['connection']['retries']['delay'] ?? 1) * 1000);
    }

    public function isProxyEnabled(): bool
    {
        return !empty($this->config['connection']['proxy']['enabled']);
    }

    /**
     * @return string[]
     */
    public function getMirrorList(): array
    {
        return $this->config['eset']['mirror']['list'] ?? [];
    }

    public function getMirrorStrategy(): MirrorStrategy
    {
        $strategy = $this->config['eset']['mirror']['strategy'] ?? 'random';
        return MirrorStrategy::fromString($strategy);
    }

    private function normalizeMirrorStrategy(mixed $value): string
    {
        if (is_string($value)) {
            return strtolower(trim($value));
        }
        return 'random';
    }

    /**
     * @param array<string, mixed> $array
     */
    private function findArrayKey(array $array, string $needle): ?string
    {
        foreach ($array as $key => $value) {
            if (strcasecmp((string) $key, $needle) === 0) {
                return (string) $key;
            }
        }

        return null;
    }

    private function ensureInitialized(): void
    {
        if (!$this->initialized) {
            $this->init();
        }
    }

}

<?php

declare(strict_types=1);

namespace Nod32Mirror\Config;

use Nod32Mirror\Enum\LogLevel;
use Nod32Mirror\Enum\MirrorStrategy;
use Nod32Mirror\Enum\ProxyType;
use Nod32Mirror\Enum\StorageLinkMethod;
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

        $parsed = $this->resolveExternalValues($parsed);
        $this->config = $this->normalizeConfig($parsed);
    }

    /**
     * @param array<mixed> $values
     * @return array<mixed>
     * @throws ConfigException
     */
    private function resolveExternalValues(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->resolveExternalValues($value);
                continue;
            }

            if (is_string($value)) {
                $values[$key] = $this->resolveExternalValue($value);
            }
        }

        return $values;
    }

    private function resolveExternalValue(string $value): mixed
    {
        if (str_starts_with($value, 'env://')) {
            $variable = substr($value, strlen('env://'));
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $variable)) {
                throw new ConfigException('Invalid environment variable reference: ' . $value);
            }

            $resolved = getenv($variable);
            if ($resolved === false) {
                throw new ConfigException('Environment variable is not defined: ' . $variable);
            }

            return $this->decodeExternalValue($resolved);
        }

        if (str_starts_with($value, 'file://')) {
            $path = rawurldecode(substr($value, strlen('file://')));
            if ($path === '') {
                throw new ConfigException('File reference path is empty');
            }

            if (!$this->isAbsolutePath($path)) {
                $path = Tools::ds(dirname($this->configPath), $path);
            }
            $path = Tools::cleanPath($path);

            if (!is_file($path)) {
                throw new ConfigException('Referenced configuration file not found: ' . $path);
            }
            if (!is_readable($path)) {
                throw new ConfigException('Referenced configuration file is not readable: ' . $path);
            }

            $resolved = file_get_contents($path);
            if ($resolved === false) {
                throw new ConfigException('Failed to read referenced configuration file: ' . $path);
            }

            return $this->decodeExternalValue(rtrim($resolved, "\r\n"));
        }

        return $value;
    }

    private function decodeExternalValue(string $value): mixed
    {
        $normalized = strtolower(trim($value));
        if ($normalized === 'true') {
            return true;
        }
        if ($normalized === 'false') {
            return false;
        }
        if ($normalized === 'null' || $normalized === '~') {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed !== '' && in_array($trimmed[0], ['[', '{'], true)) {
            try {
                $decoded = Yaml::parse($trimmed);
            } catch (ParseException $e) {
                throw new ConfigException('Failed to parse referenced configuration value: ' . $e->getMessage(), 0, $e);
            }

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function normalizeConfig(array $config): array
    {
        $config = $this->arrayChangeKeyCaseRecursive($config, CASE_LOWER);

        $eset = is_array($config['eset'] ?? null) ? $config['eset'] : [];

        return [
            'runtime' => $this->normalizeRuntime(is_array($config['runtime'] ?? null) ? $config['runtime'] : []),
            'state' => $this->normalizeState(is_array($config['state'] ?? null) ? $config['state'] : []),
            'web' => $this->normalizeWeb(is_array($config['web'] ?? null) ? $config['web'] : []),
            'storage' => $this->normalizeStorageConfig(is_array($config['storage'] ?? null) ? $config['storage'] : []),
            'downloads' => $this->normalizeDownloads(is_array($config['downloads'] ?? null) ? $config['downloads'] : []),
            'logging' => $this->normalizeLogging(is_array($config['logging'] ?? null) ? $config['logging'] : []),
            'credentials' => $this->normalizeCredentials(is_array($config['credentials'] ?? null) ? $config['credentials'] : []),
            'eset' => [
                'mirrors' => $this->normalizeMirrors(is_array($eset['mirrors'] ?? null) ? $eset['mirrors'] : []),
                'versions' => $this->normalizeVersions(is_array($eset['versions'] ?? null) ? $eset['versions'] : []),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $runtimeConfig
     * @return array<string, mixed>
     */
    private function normalizeRuntime(array $runtimeConfig): array
    {
        $defaults = [
            'root' => SELF,
            'temp' => 'tmp',
            'locale' => [
                'language' => 'en',
                'encoding' => 'utf-8',
                'timezone' => null,
            ],
            'php' => ['memory_limit' => '512M'],
            'debug' => [
                'update' => false,
                'html' => false,
            ],
        ];

        $runtime = array_replace_recursive($defaults, $runtimeConfig);
        $runtime['root'] = is_string($runtime['root'] ?? null) && trim($runtime['root']) !== ''
            ? trim((string) $runtime['root'])
            : SELF;
        $runtime['temp'] = is_string($runtime['temp'] ?? null) && trim($runtime['temp']) !== ''
            ? trim((string) $runtime['temp'])
            : 'tmp';
        $runtime['debug']['update'] = !empty($runtime['debug']['update']);
        $runtime['debug']['html'] = !empty($runtime['debug']['html']);

        return $runtime;
    }

    /**
     * @param array<string, mixed> $stateConfig
     * @return array<string, mixed>
     */
    private function normalizeState(array $stateConfig): array
    {
        $state = array_replace_recursive([
            'root' => 'data',
            'database' => ['file' => 'content-index.sqlite'],
            'files' => [
                'credentials' => 'keys.json',
                'database_sizes' => 'databases_size.json',
                'last_update' => 'lastupdate.json',
                'gc_state' => 'gc-state.json',
                'lock' => 'locks/update.lock',
            ],
            'directories' => [
                'debug' => 'debug',
            ],
        ], $stateConfig);
        $state['root'] = $this->normalizePathValue($state['root'] ?? null, 'data');
        $state['database']['file'] = $this->normalizePathValue(
            $state['database']['file'] ?? null,
            'content-index.sqlite'
        );
        if (!is_array($state['files'] ?? null)) {
            $state['files'] = [];
        }
        foreach ([
            'credentials' => 'keys.json',
            'database_sizes' => 'databases_size.json',
            'last_update' => 'lastupdate.json',
            'gc_state' => 'gc-state.json',
            'lock' => 'locks/update.lock',
        ] as $key => $default) {
            $state['files'][$key] = $this->normalizePathValue($state['files'][$key] ?? null, $default);
        }
        if (!is_array($state['directories'] ?? null)) {
            $state['directories'] = [];
        }
        $state['directories']['debug'] = $this->normalizePathValue(
            $state['directories']['debug'] ?? null,
            'debug'
        );

        return $state;
    }

    /**
     * @param array<string, mixed> $webConfig
     * @return array<string, mixed>
     */
    private function normalizeWeb(array $webConfig): array
    {
        $web = array_replace_recursive([
            'root' => 'www',
            'reports' => [
                'export_credentials' => false,
                'json' => ['enabled' => true, 'file' => 'index.json'],
                'html' => ['enabled' => true, 'file' => 'index.html', 'encoding' => 'utf-8', 'table_only' => false],
                'status' => ['enabled' => true, 'file' => 'status.json'],
            ],
        ], $webConfig);
        $web['root'] = $this->normalizePathValue($web['root'] ?? null, 'www');
        $reports = &$web['reports'];
        $reports['export_credentials'] = !empty($reports['export_credentials']);
        $reports['json']['enabled'] = !empty($reports['json']['enabled']);
        $reports['html']['enabled'] = !empty($reports['html']['enabled']);
        $reports['status']['enabled'] = !empty($reports['status']['enabled']);
        $reports['html']['table_only'] = !empty($reports['html']['table_only']);
        $reports['json']['file'] = $this->normalizeRelativePath((string) $reports['json']['file'], 'index.json');
        $reports['html']['file'] = $this->normalizeRelativePath((string) $reports['html']['file'], 'index.html');
        $reports['status']['file'] = $this->normalizeRelativePath((string) $reports['status']['file'], 'status.json');
        unset($reports);

        return $web;
    }

    /**
     * @param array<string, mixed> $storageConfig
     * @return array<string, mixed>
     */
    private function normalizeStorageConfig(array $storageConfig): array
    {
        $defaults = [
            'root' => 'storage',
            'directories' => [
                'blobs' => 'blobs',
                'quarantine' => 'quarantine',
            ],
            'hash' => 'sha256',
            'link_method' => StorageLinkMethod::Hardlink->value,
            'gc' => [
                'enabled' => false,
                'excludes' => [],
            ],
        ];

        $storage = array_replace_recursive($defaults, $storageConfig);
        $storage['root'] = $this->normalizePathValue($storage['root'] ?? null, 'storage');
        if (!is_array($storage['directories'] ?? null)) {
            $storage['directories'] = [];
        }
        $storage['directories']['blobs'] = $this->normalizePathValue(
            $storage['directories']['blobs'] ?? null,
            'blobs'
        );
        $storage['directories']['quarantine'] = $this->normalizePathValue(
            $storage['directories']['quarantine'] ?? null,
            'quarantine'
        );
        $storage['hash'] = is_string($storage['hash'] ?? null) && trim($storage['hash']) !== ''
            ? strtolower(trim((string) $storage['hash']))
            : 'sha256';
        $storage['link_method'] = StorageLinkMethod::fromString((string) ($storage['link_method'] ?? 'hardlink'))->value;

        if (!isset($storage['gc']) || !is_array($storage['gc'])) {
            $storage['gc'] = ['enabled' => false, 'excludes' => []];
        }
        $storage['gc']['enabled'] = !empty($storage['gc']['enabled']);
        $storage['gc']['excludes'] = $this->normalizeStringList($storage['gc']['excludes'] ?? []);

        return $storage;
    }

    private function normalizePathValue(mixed $value, string $default): string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    /**
     * @return string[]
     */
    private function normalizeStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }

            $item = trim(str_replace('\\', '/', $item));
            if ($item !== '') {
                $items[] = ltrim($item, '/');
            }
        }

        return array_values(array_unique($items));
    }

    private function normalizeRelativePath(string $path, string $default): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');

        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        if ($path === '') {
            return $default;
        }

        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                return $default;
            }
        }

        return $path;
    }

    /**
     * @param array<string, mixed> $downloadsConfig
     * @return array<string, mixed>
     */
    private function normalizeDownloads(array $downloadsConfig): array
    {
        $defaults = [
            'concurrency' => 32,
            'timeout' => [
                'request' => 30,
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
                'endpoint' => ['host' => '', 'port' => 80],
                'credentials' => ['username' => '', 'password' => ''],
            ],
        ];

        $downloads = array_replace_recursive($defaults, $downloadsConfig);
        $downloads['concurrency'] = max(1, (int) $downloads['concurrency']);
        $downloads['timeout']['request'] = max(1, (int) $downloads['timeout']['request']);
        $downloads['timeout']['connect'] = max(1, (int) $downloads['timeout']['connect']);
        $downloads['retries']['attempts'] = max(1, (int) $downloads['retries']['attempts']);
        $downloads['retries']['delay'] = max(0, (int) $downloads['retries']['delay']);
        $downloads['speed_limit'] = max(0, (int) $downloads['speed_limit']);
        $downloads['proxy']['enabled'] = !empty($downloads['proxy']['enabled']);
        $downloads['proxy']['type'] = ProxyType::fromString((string) $downloads['proxy']['type'])->value;
        $downloads['proxy']['endpoint']['port'] = (int) $downloads['proxy']['endpoint']['port'];

        return $downloads;
    }

    /**
     * @param array<string, mixed> $loggingConfig
     * @return array<string, mixed>
     */
    private function normalizeLogging(array $loggingConfig): array
    {
        $defaults = [
            'root' => 'log',
            'stdout' => ['enabled' => true, 'level' => LogLevel::Debug->value],
            'file' => [
                'enabled' => true,
                'level' => LogLevel::Debug->value,
                'path' => 'nod32ms.log',
                'rotation' => ['enabled' => true, 'max_size' => '100K', 'keep' => 5],
            ],
        ];

        $merged = array_replace_recursive($defaults, $loggingConfig);
        $merged['root'] = $this->normalizePathValue($merged['root'] ?? null, 'log');

        $merged['stdout']['enabled'] = !empty($merged['stdout']['enabled']);
        $merged['stdout']['level'] = LogLevel::fromMixed($merged['stdout']['level'] ?? LogLevel::Debug)->value;

        $merged['file']['enabled'] = !empty($merged['file']['enabled']);
        $merged['file']['level'] = LogLevel::fromMixed($merged['file']['level'] ?? LogLevel::Debug)->value;
        $merged['file']['path'] = $this->normalizePathValue(
            $merged['file']['path'] ?? null,
            'nod32ms.log'
        );
        $merged['file']['rotation']['enabled'] = !empty($merged['file']['rotation']['enabled']);
        $merged['file']['rotation']['keep'] = max(1, (int) ($merged['file']['rotation']['keep'] ?? 5));

        $rotateSize = $merged['file']['rotation']['max_size'] ?? '100K';
        $merged['file']['rotation']['max_size'] = is_numeric($rotateSize)
            ? (int) $rotateSize
            : Tools::human2bytes((string) $rotateSize);

        return $merged;
    }

    /**
     * @param array<string, mixed> $credentialsConfig
     * @return array<string, mixed>
     */
    private function normalizeCredentials(array $credentialsConfig): array
    {
        $credentials = array_replace_recursive(['discovery' => [
            'enabled' => false,
            'automatic' => false,
            'attempts' => 1,
            'required_count' => 1,
            'remove_invalid' => false,
            'patterns' => ['selected' => null, 'fallback' => ''],
            'request' => [
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'headers' => [],
            ],
            'search' => [
                'queries' => [],
                'first_page' => 1,
                'page_count' => 1,
                'recursion_depth' => 1,
                'error_limit' => 5,
            ],
        ]], $credentialsConfig);

        $discovery = &$credentials['discovery'];
        $discovery['enabled'] = !empty($discovery['enabled']);
        $discovery['automatic'] = !empty($discovery['automatic']);
        $discovery['remove_invalid'] = !empty($discovery['remove_invalid']);
        $discovery['attempts'] = max(1, (int) $discovery['attempts']);
        $discovery['required_count'] = max(1, (int) $discovery['required_count']);
        $discovery['search']['first_page'] = max(1, (int) $discovery['search']['first_page']);
        $discovery['search']['page_count'] = max(1, (int) $discovery['search']['page_count']);
        $discovery['search']['recursion_depth'] = max(0, (int) $discovery['search']['recursion_depth']);
        $discovery['search']['error_limit'] = max(1, (int) $discovery['search']['error_limit']);

        if (is_string($discovery['request']['headers'])) {
            $discovery['request']['headers'] = array_filter(array_map(
                'trim',
                preg_split('/[\r\n,]+/', $discovery['request']['headers']) ?: []
            ));
        }
        $discovery['search']['queries'] = $this->normalizeQueryList($discovery['search']['queries'] ?? []);
        unset($discovery);

        return $credentials;
    }

    /**
     * @param array<string, mixed> $versionsConfig
     * @return array<string, mixed>
     */
    private function normalizeVersions(array $versionsConfig): array
    {
        $overrides = $versionsConfig['overrides'] ?? [];

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
                    'enabled' => !empty($settings['enabled']),
                ];
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $mirrorsConfig
     * @return array<string, mixed>
     */
    private function normalizeMirrors(array $mirrorsConfig): array
    {
        $discoveryConfig = is_array($mirrorsConfig['discovery'] ?? null)
            ? $mirrorsConfig['discovery']
            : [];
        $fetchConfig = is_array($discoveryConfig['fetch'] ?? null)
            ? $discoveryConfig['fetch']
            : [];
        $validationConfig = is_array($discoveryConfig['validation'] ?? null)
            ? $discoveryConfig['validation']
            : [];

        $pool = strtolower(trim((string) ($discoveryConfig['pool'] ?? 'merge')));
        if (!in_array($pool, ['merge', 'discovered'], true)) {
            throw new ConfigException('Unsupported mirror discovery pool mode: ' . $pool);
        }

        return [
            'strategy' => $this->normalizeMirrorStrategy($mirrorsConfig['strategy'] ?? 'random'),
            'hosts' => $this->normalizeMirrorList($mirrorsConfig['hosts'] ?? []),
            'discovery' => [
                'enabled' => !empty($discoveryConfig['enabled']),
                'pool' => $pool,
                'fetch' => [
                    'versions' => $this->normalizeList($fetchConfig['versions'] ?? true),
                ],
                'validation' => [
                    'max_hosts' => max(1, (int) ($validationConfig['max_hosts'] ?? 100)),
                    'allow_ports' => !empty($validationConfig['allow_ports']),
                    'allow_private_addresses' => !empty($validationConfig['allow_private_addresses']),
                    'allowed_hosts' => $this->normalizeAllowedHosts($validationConfig['allowed_hosts'] ?? null),
                ],
            ],
        ];
    }

    /**
     * @return string[]|null
     */
    private function normalizeAllowedHosts(mixed $allowedHosts): ?array
    {
        if ($allowedHosts === null || $allowedHosts === []) {
            return null;
        }

        if (!is_array($allowedHosts)) {
            throw new ConfigException('eset.mirrors.discovery.validation.allowed_hosts must be a list or null');
        }

        $normalized = [];
        foreach ($allowedHosts as $allowedHost) {
            if (!is_string($allowedHost) || trim($allowedHost) === '') {
                throw new ConfigException('Mirror allowed_hosts entries must be non-empty strings');
            }

            $normalized[] = strtolower(rtrim(trim($allowedHost), '.'));
        }

        return array_values(array_unique($normalized));
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

        if (empty($this->config['eset']['mirrors']['hosts'])) {
            throw new ConfigException('Mirror list is empty. Please configure eset.mirrors.hosts.');
        }

        $logConfig = $this->config['logging']['file'] ?? [];
        if (!empty($logConfig['rotation']['enabled']) && ($logConfig['rotation']['keep'] ?? 0) < 1) {
            throw new ConfigException('Log rotation quantity must be at least 1');
        }

        $storageHash = $this->getStorageHashAlgorithm();
        if (!in_array($storageHash, hash_algos(), true)) {
            throw new ConfigException('Unsupported storage hash algorithm: ' . $storageHash);
        }
    }

    private function setupEnvironment(): void
    {
        $runtimeConfig = $this->config['runtime'];

        $memoryLimit = trim((string) ($runtimeConfig['php']['memory_limit'] ?? '512M'));
        if ($memoryLimit !== '' && ini_set('memory_limit', $memoryLimit) === false) {
            throw new ConfigException('Failed to set PHP memory_limit to: ' . $memoryLimit);
        }

        if (!empty($runtimeConfig['locale']['timezone'])) {
            @date_default_timezone_set($runtimeConfig['locale']['timezone']);
        } else {
            @date_default_timezone_set(@date_default_timezone_get() ?: 'UTC');
        }

        $this->setupDirectories();
    }

    private function setupDirectories(): void
    {
        $rootDir = (string) ($this->config['runtime']['root'] ?? SELF);
        $webDir = (string) ($this->config['web']['root'] ?? 'www');
        $dataDir = (string) ($this->config['state']['root'] ?? 'data');
        $logDir = (string) ($this->config['logging']['root'] ?? 'log');
        $storageDir = (string) ($this->config['storage']['root'] ?? 'storage');
        $storageIndexPath = (string) ($this->config['state']['database']['file'] ?? 'content-index.sqlite');
        $tmpDir = (string) ($this->config['runtime']['temp'] ?? 'tmp');

        if (!$this->isAbsolutePath($rootDir)) {
            $rootDir = Tools::ds(SELF, $rootDir);
        }
        $rootDir = Tools::cleanPath($rootDir);

        $webDir = $this->resolvePath($webDir, $rootDir);
        $dataDir = $this->resolvePath($dataDir, $rootDir);
        $logDir = $this->resolvePath((string) $logDir, $rootDir);
        $storageDir = $this->resolvePath($storageDir, $rootDir);
        $storageIndexPath = $this->resolvePath($storageIndexPath, $dataDir);
        $tmpDir = $this->resolvePath($tmpDir, $rootDir);

        foreach ($this->config['state']['files'] as $key => $path) {
            $this->config['state']['files'][$key] = $this->resolvePath((string) $path, $dataDir);
        }
        $this->config['state']['directories']['debug'] = $this->resolvePath(
            (string) $this->config['state']['directories']['debug'],
            $dataDir
        );
        $this->config['logging']['file']['path'] = $this->resolvePath(
            (string) $this->config['logging']['file']['path'],
            $logDir
        );
        foreach ($this->config['storage']['directories'] as $key => $path) {
            $this->config['storage']['directories'][$key] = $this->resolvePath((string) $path, $storageDir);
        }

        $this->config['runtime']['root'] = $rootDir;
        $this->config['runtime']['temp'] = Tools::cleanPath($tmpDir);
        $this->config['web']['root'] = Tools::cleanPath($webDir);
        $this->config['state']['root'] = Tools::cleanPath($dataDir);
        $this->config['state']['database']['file'] = Tools::cleanPath($storageIndexPath);
        $this->config['logging']['root'] = Tools::cleanPath($logDir);
        $this->config['storage']['root'] = Tools::cleanPath($storageDir);

        // Create directories
        Tools::ensureDirectory(PATTERN);
        Tools::ensureDirectory($this->config['state']['root']);
        Tools::ensureDirectory($this->config['logging']['root']);
        Tools::ensureDirectory($this->config['web']['root']);
        Tools::ensureDirectory($this->config['storage']['root']);
        Tools::ensureDirectory(dirname($this->config['state']['database']['file']));
        Tools::ensureDirectory($this->config['runtime']['temp']);

        foreach ($this->config['state']['files'] as $path) {
            Tools::ensureDirectory(dirname((string) $path));
        }
        Tools::ensureDirectory(dirname($this->config['logging']['file']['path']));
        foreach ($this->config['storage']['directories'] as $path) {
            Tools::ensureDirectory((string) $path);
        }

        if (!empty($this->config['runtime']['debug']['html'])) {
            Tools::ensureDirectory($this->config['state']['directories']['debug']);
        }
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $path)
            || str_starts_with($path, '\\\\');
    }

    private function resolvePath(string $path, string $rootDir): string
    {
        $path = trim($path);
        if ($path === '') {
            return $rootDir;
        }

        if (!$this->isAbsolutePath($path)) {
            $path = Tools::ds($rootDir, $path);
        }

        return Tools::cleanPath($path);
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
        return $this->config['web']['root'] ?? Tools::ds(SELF, 'www');
    }

    public function getDataDir(): string
    {
        return $this->config['state']['root'] ?? Tools::ds(SELF, 'data');
    }

    public function getStorageDir(): string
    {
        $dir = $this->config['storage']['root'] ?? Tools::ds($this->getBaseDir(), 'storage');
        return is_string($dir) && $dir !== '' ? $dir : Tools::ds($this->getBaseDir(), 'storage');
    }

    public function getTmpDir(): string
    {
        $dir = $this->config['runtime']['temp'] ?? Tools::ds($this->getBaseDir(), 'tmp');
        return is_string($dir) && $dir !== '' ? $dir : Tools::ds($this->getBaseDir(), 'tmp');
    }

    public function getBaseDir(): string
    {
        $dir = $this->config['runtime']['root'] ?? SELF;
        return is_string($dir) && $dir !== '' ? $dir : SELF;
    }

    public function getStorageIndexPath(): string
    {
        $path = $this->config['state']['database']['file'] ?? Tools::ds($this->getDataDir(), 'content-index.sqlite');
        return is_string($path) && $path !== '' ? $path : Tools::ds($this->getDataDir(), 'content-index.sqlite');
    }

    public function getCredentialsFilePath(): string
    {
        return $this->getStateFilePath('credentials', 'keys.json');
    }

    public function getDatabaseSizesFilePath(): string
    {
        return $this->getStateFilePath('database_sizes', 'databases_size.json');
    }

    public function getLastUpdateFilePath(): string
    {
        return $this->getStateFilePath('last_update', 'lastupdate.json');
    }

    public function getGcStateFilePath(): string
    {
        return $this->getStateFilePath('gc_state', 'gc-state.json');
    }

    public function getLockFilePath(): string
    {
        return $this->getStateFilePath('lock', Tools::ds('locks', 'update.lock'));
    }

    private function getStateFilePath(string $key, string $default): string
    {
        $path = $this->config['state']['files'][$key] ?? Tools::ds($this->getDataDir(), $default);
        return is_string($path) && $path !== '' ? $path : Tools::ds($this->getDataDir(), $default);
    }

    public function getDebugDir(): string
    {
        $path = $this->config['state']['directories']['debug'] ?? Tools::ds($this->getDataDir(), 'debug');
        return is_string($path) && $path !== '' ? $path : Tools::ds($this->getDataDir(), 'debug');
    }

    public function getLogFilePath(): string
    {
        $path = $this->config['logging']['file']['path'] ?? Tools::ds(
            (string) ($this->config['logging']['root'] ?? 'log'),
            'nod32ms.log'
        );
        return is_string($path) && $path !== '' ? $path : Tools::ds($this->getBaseDir(), 'log', 'nod32ms.log');
    }

    public function getStorageBlobDir(): string
    {
        $path = $this->config['storage']['directories']['blobs'] ?? Tools::ds($this->getStorageDir(), 'blobs');
        return is_string($path) && $path !== '' ? $path : Tools::ds($this->getStorageDir(), 'blobs');
    }

    public function getStorageQuarantineDir(): string
    {
        $path = $this->config['storage']['directories']['quarantine']
            ?? Tools::ds($this->getStorageDir(), 'quarantine');
        return is_string($path) && $path !== '' ? $path : Tools::ds($this->getStorageDir(), 'quarantine');
    }

    public function getStorageHashAlgorithm(): string
    {
        $algorithm = $this->config['storage']['hash'] ?? 'sha256';
        return is_string($algorithm) && $algorithm !== '' ? strtolower($algorithm) : 'sha256';
    }

    public function getStorageLinkMethod(): StorageLinkMethod
    {
        return StorageLinkMethod::fromString((string) ($this->config['storage']['link_method'] ?? 'hardlink'));
    }

    public function isStorageGcEnabled(): bool
    {
        return !empty($this->config['storage']['gc']['enabled']);
    }

    public function getTimeout(): int
    {
        return (int) ($this->config['downloads']['timeout']['request'] ?? 30);
    }

    public function getConnectTimeout(): int
    {
        return (int) ($this->config['downloads']['timeout']['connect'] ?? 5);
    }

    public function getMaxThreads(): int
    {
        return (int) ($this->config['downloads']['concurrency'] ?? 32);
    }

    public function getMaxRetries(): int
    {
        return (int) ($this->config['downloads']['retries']['attempts'] ?? 3);
    }

    public function getRetryDelay(): int
    {
        // Returns delay in milliseconds (config is in seconds)
        return (int) (($this->config['downloads']['retries']['delay'] ?? 1) * 1000);
    }

    public function isProxyEnabled(): bool
    {
        return !empty($this->config['downloads']['proxy']['enabled']);
    }

    /**
     * @return string[]
     */
    public function getMirrorList(): array
    {
        return $this->config['eset']['mirrors']['hosts'] ?? [];
    }

    public function getMirrorStrategy(): MirrorStrategy
    {
        $strategy = $this->config['eset']['mirrors']['strategy'] ?? 'random';
        return MirrorStrategy::fromString($strategy);
    }

    public function isMirrorDiscoveryEnabled(): bool
    {
        return !empty($this->config['eset']['mirrors']['discovery']['enabled']);
    }

    public function getMirrorDiscoveryPool(): string
    {
        return (string) ($this->config['eset']['mirrors']['discovery']['pool'] ?? 'merge');
    }

    /**
     * @return string[]|true
     */
    public function getMirrorDiscoveryVersions(): array|bool
    {
        return $this->config['eset']['mirrors']['discovery']['fetch']['versions'] ?? true;
    }

    public function getMirrorDiscoveryMaxHosts(): int
    {
        return (int) ($this->config['eset']['mirrors']['discovery']['validation']['max_hosts'] ?? 100);
    }

    public function areMirrorDiscoveryPortsAllowed(): bool
    {
        return !empty($this->config['eset']['mirrors']['discovery']['validation']['allow_ports']);
    }

    public function arePrivateMirrorAddressesAllowed(): bool
    {
        return !empty($this->config['eset']['mirrors']['discovery']['validation']['allow_private_addresses']);
    }

    /**
     * @return string[]|null
     */
    public function getAllowedMirrorHosts(): ?array
    {
        $allowedHosts = $this->config['eset']['mirrors']['discovery']['validation']['allowed_hosts'] ?? null;
        return is_array($allowedHosts) ? $allowedHosts : null;
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

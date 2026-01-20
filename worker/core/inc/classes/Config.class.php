<?php

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Class Config
 */
class Config
{
    /**
     * @var
     */
    static private $CONF;

    /**
     * @var bool
     */
    static private $initialized = false;

    /**
     * @var string|null
     */
    static private $configPath = null;

    /**
     * @var array
     */
    static private $LCID = [
        'bgr' => 1026,
        'chs' => 2052,
        'cht' => 1028,
        'csy' => 1029,
        'dan' => 1030,
        'deu' => 1031,
        'enu' => 1033,
        'esl' => 13322,
        'esn' => 3082,
        'eti' => 1061,
        'fin' => 1035,
        'fra' => 1036,
        'frc' => 3084,
        'hrv' => 1050,
        'hun' => 1038,
        'ita' => 1040,
        'kor' => 1042,
        'lth' => 1063,
        'nld' => 1043,
        'nor' => 1044,
        'plk' => 1045,
        'ptb' => 1046,
        'rom' => 1048,
        'rus' => 1049,
        'sky' => 1051,
        'slv' => 1060,
        'sve' => 1053,
        'tha' => 1054,
        'trk' => 1055,
        'ukr' => 1058
    ];

    /**
     * @throws ConfigException
     * @throws Exception
     */
    static public function init()
    {
        if (static::$initialized) {
            return;
        }

        static::loadConfig();

        if (isset(static::$CONF['connection'])) {
            static::$CONF['connection']['multidownload']['enabled'] = !empty(static::$CONF['connection']['multidownload']['enabled']);
            static::$CONF['connection']['proxy']['enabled'] = !empty(static::$CONF['connection']['proxy']['enabled']);
            static::$CONF['connection']['multidownload']['threads'] = intval(static::$CONF['connection']['multidownload']['threads'] ?? 0);
            static::$CONF['connection']['speed_limit'] = intval(static::$CONF['connection']['speed_limit'] ?? 0);
            static::$CONF['connection']['timeout'] = intval(static::$CONF['connection']['timeout'] ?? 0);
            static::$CONF['connection']['proxy']['port'] = intval(static::$CONF['connection']['proxy']['port'] ?? 0);
        }

        foreach (['auto', 'remove_invalid_keys'] as $opt) {
            if (isset(static::$CONF['find'][$opt])) {
                static::$CONF['find'][$opt] = (bool) static::$CONF['find'][$opt];
            }
        }

        if (empty(static::$CONF['eset']['mirror'])) {
            throw new ConfigException(Language::t('config.mirror_list_missing'));
        }

        static::$CONF['eset']['mirror'] = static::normalizeMirrorList(static::$CONF['eset']['mirror']);

        /*
        if (!in_array("update.eset.com", static::$CONF['eset']['mirror'])) {
            static::$CONF['eset']['mirror'][] = "update.eset.com";
        }
        */

        if (empty(static::$CONF['log']['file']['dir'])) {
            static::$CONF['log']['file']['dir'] = "log";
        }

        if (empty(static::$CONF['data']['dir'])) {
            static::$CONF['data']['dir'] = "data";
        }

        if (preg_match("/^win/i", PHP_OS) == false) {
            if (substr(static::$CONF['script']['web_dir'], 0, 1) != DS) {
                static::$CONF['script']['web_dir'] = Tools::ds(SELF, static::$CONF['script']['web_dir']);
            }
            if (substr(static::$CONF['log']['file']['dir'], 0, 1) != DS) {
                static::$CONF['log']['file']['dir'] = Tools::ds(SELF, static::$CONF['log']['file']['dir']);
            }
            if (substr(static::$CONF['data']['dir'], 0, 1) != DS) {
                static::$CONF['data']['dir'] = Tools::ds(SELF, static::$CONF['data']['dir']);
            }
        }
        static::check_config();
        static::$initialized = true;
    }

    /**
     * Locate config file path (supports container and repo root)
     * @return string
     */
    static private function resolveConfigPath()
    {
        return CONF_FILE;
    }

    /**
     * Parse YAML config and normalize structure
     * @throws ConfigException
     */
    static private function loadConfig()
    {
        if (!empty(static::$CONF)) {
            return;
        }

        $configPath = static::resolveConfigPath();

        if (!file_exists($configPath)) {
            throw new ConfigException(Language::t('config.file_missing'));
        }

        if (!is_readable($configPath)) {
            throw new ConfigException(Language::t('config.cant_read_file'));
        }

        try {
            $parsed = Yaml::parseFile($configPath);
        } catch (ParseException $e) {
            throw new ConfigException(Language::t('config.failed_parse', $e->getMessage()));
        }

        if (empty($parsed) || !is_array($parsed)) {
            throw new ConfigException(Language::t('config.file_empty'));
        }

        static::$CONF = static::normalizeConfig($parsed);
        static::$configPath = $configPath;
    }

    /**
     * Normalize YAML config to internal structure
     * @param array $config
     * @return array
     */
    static private function normalizeConfig(array $config)
    {
        // Convert all keys to lowercase for consistent access
        $config = static::arrayChangeKeyCaseRecursive($config, CASE_LOWER);

        $config['script'] = static::normalizeScript($config['script'] ?? []);
        $config['connection'] = static::normalizeConnection($config['connection'] ?? []);
        $config['log'] = static::normalizeLog(static::normalizeSection($config, 'log'));
        $config['data'] = static::normalizeSection($config, 'data');
        $config['find'] = static::normalizeSection($config, 'find');
        $config['eset'] = static::normalizeSection($config, 'eset');

        if (!isset($config['eset']['mirror'])) {
            $config['eset']['mirror'] = [];
        }

        // Normalize versions block
        $config['eset']['versions'] = static::normalizeVersions($config['eset']['versions'] ?? []);

        return $config;
    }

    /**
     * @param array $config
     * @param string $key
     * @return array
     */
    static private function normalizeSection(array $config, $key)
    {
        return (isset($config[$key]) && is_array($config[$key])) ? $config[$key] : [];
    }

    /**
     * Normalize script configuration
     * @param array $scriptConfig
     * @return array
     */
    static private function normalizeScript(array $scriptConfig)
    {
        $defaults = [
            'language' => 'en',
            'codepage' => 'utf-8',
            'timezone' => null,
            'memory_limit' => '32M',
            'debug_update' => false,
            'link_method' => 'hardlink',
            'debug_html' => false,
            'web_dir' => 'www',
            'generate' => [
                'export_credentials' => false,
                'json' => [
                    'enabled' => true,
                    'filename' => 'index.json',
                ],
                'html' => [
                    'enabled' => true,
                    'filename' => 'index.html',
                    'codepage' => 'utf-8',
                    'only_table' => false,
                ],
            ],
        ];

        $script = array_replace_recursive($defaults, $scriptConfig);

        $script['debug_update'] = !empty($script['debug_update']);
        $script['debug_html'] = !empty($script['debug_html']);

        $script['generate']['export_credentials'] = !empty($script['generate']['export_credentials']);
        $script['generate']['json']['enabled'] = !empty($script['generate']['json']['enabled']);
        $script['generate']['html']['enabled'] = !empty($script['generate']['html']['enabled']);
        $script['generate']['html']['only_table'] = !empty($script['generate']['html']['only_table']);

        if (empty($script['generate']['json']['filename'])) {
            $script['generate']['json']['filename'] = $defaults['generate']['json']['filename'];
        }

        if (empty($script['generate']['html']['filename'])) {
            $script['generate']['html']['filename'] = $defaults['generate']['html']['filename'];
        }

        if (empty($script['generate']['html']['codepage'])) {
            $script['generate']['html']['codepage'] = $defaults['generate']['html']['codepage'];
        }

        if (empty($script['web_dir'])) {
            $script['web_dir'] = $defaults['web_dir'];
        }

        return $script;
    }

    /**
     * Normalize connection settings (nested structure)
     * @param array $connectionConfig
     * @return array
     */
    static private function normalizeConnection(array $connectionConfig)
    {
        $defaults = [
            'multidownload' => [
                'enabled' => false,
                'threads' => 32
            ],
            'speed_limit' => 0,
            'timeout' => 5,
            'proxy' => [
                'enabled' => false,
                'type' => 'http',
                'server' => '',
                'port' => 80,
                'user' => '',
                'password' => ''
            ]
        ];

        $connection = $defaults;

        // Multidownload
        if (isset($connectionConfig['multidownload']) && is_array($connectionConfig['multidownload'])) {
            $connection['multidownload']['enabled'] = !empty($connectionConfig['multidownload']['enabled']);
            $connection['multidownload']['threads'] = intval($connectionConfig['multidownload']['threads'] ?? $defaults['multidownload']['threads']);
        }

        // Speed limit and timeout
        if (isset($connectionConfig['speed_limit'])) {
            $connection['speed_limit'] = intval($connectionConfig['speed_limit']);
        }

        if (isset($connectionConfig['timeout'])) {
            $connection['timeout'] = intval($connectionConfig['timeout']);
        }

        // Proxy (nested only)
        if (isset($connectionConfig['proxy']) && is_array($connectionConfig['proxy'])) {
            $connection['proxy']['enabled'] = !empty($connectionConfig['proxy']['enabled']);
            $connection['proxy']['type'] = $connectionConfig['proxy']['type'] ?? $defaults['proxy']['type'];
            $connection['proxy']['server'] = $connectionConfig['proxy']['server'] ?? $defaults['proxy']['server'];
            $connection['proxy']['port'] = intval($connectionConfig['proxy']['port'] ?? $defaults['proxy']['port']);
            $connection['proxy']['user'] = $connectionConfig['proxy']['user'] ?? $defaults['proxy']['user'];
            $connection['proxy']['password'] = $connectionConfig['proxy']['password'] ?? $defaults['proxy']['password'];
        }

        return $connection;
    }

    /**
     * @param mixed $mirrorList
     * @return array
     */
    static private function normalizeMirrorList($mirrorList)
    {
        if (is_array($mirrorList)) {
            return array_values(array_filter(array_map('trim', $mirrorList), 'strlen'));
        }

        if (is_string($mirrorList)) {
            return Tools::parse_comma_list($mirrorList);
        }

        return [];
    }

    /**
     * Normalize list values into array or "all" marker (true)
     * @param mixed $value
     * @return array|bool
     */
    static private function normalizeList($value)
    {
        if ($value === true) {
            return true;
        }

        if ($value === null || $value === '' || $value === false) {
            return [];
        }

        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value), 'strlen'));
        }

        if (is_string($value)) {
            return Tools::parse_comma_list($value);
        }

        return [];
    }

    /**
     * Normalize log configuration
     * @param array $logConfig
     * @return array
     */
    static private function normalizeLog(array $logConfig)
    {
        $defaults = [
            'stdout' => [
                'enabled' => true,
                'level' => Log::LEVEL_DEBUG
            ],
            'file' => [
                'enabled' => true,
                'level' => Log::LEVEL_DEBUG,
                'dir' => 'log',
                'rotate' => [
                    'enabled' => true,
                    'size' => '100K',
                    'qty' => 5
                ]
            ]
        ];

        // Merge provided config onto defaults
        $merged = array_replace_recursive($defaults, $logConfig);

        // Normalize booleans/ints and rotate sizing
        $merged['stdout']['enabled'] = !empty($merged['stdout']['enabled']);
        $merged['stdout']['level'] = Log::normalizeLevel($merged['stdout']['level'] ?? Log::LEVEL_DEBUG);

        $merged['file']['enabled'] = !empty($merged['file']['enabled']);
        $merged['file']['level'] = Log::normalizeLevel($merged['file']['level'] ?? Log::LEVEL_DEBUG);
        $merged['file']['dir'] = $merged['file']['dir'] ?? 'log';

        $merged['file']['rotate']['enabled'] = !empty($merged['file']['rotate']['enabled']);
        $merged['file']['rotate']['qty'] = intval($merged['file']['rotate']['qty'] ?? 0);
        $rotateSizeRaw = $merged['file']['rotate']['size'] ?? '0';
        if (is_numeric($rotateSizeRaw)) {
            $rotateSize = intval($rotateSizeRaw);
        } else {
            $rotateSize = Tools::human2bytes((string)$rotateSizeRaw);
            $rotateSize = $rotateSize ?? 0;
        }
        $merged['file']['rotate']['size'] = $rotateSize;

        return $merged;
    }

    /**
     * Normalize version configuration structure
     * @param array $versionsConfig
     * @return array
     */
    static private function normalizeVersions(array $versionsConfig)
    {
        // Accept both "version_overrides" (new YAML) and "overrides" (fallback)
        $overrides = [];
        if (!empty($versionsConfig['version_overrides']) && is_array($versionsConfig['version_overrides'])) {
            $overrides = $versionsConfig['version_overrides'];
        } elseif (!empty($versionsConfig['overrides']) && is_array($versionsConfig['overrides'])) {
            $overrides = $versionsConfig['overrides'];
        }

        $normalized = [
            'platforms' => static::normalizeList($versionsConfig['platforms'] ?? []),
            'channels' => static::normalizeList($versionsConfig['channels'] ?? []),
            'overrides' => [],
        ];

        if (!empty($overrides)) {
            foreach ($overrides as $version => $settings) {
                $settings = is_array($settings) ? $settings : [];
                $settings['platforms'] = static::normalizeList($settings['platforms'] ?? []);
                $settings['channels'] = static::normalizeList($settings['channels'] ?? []);
                $settings['mirror'] = isset($settings['mirror']) ? (bool)$settings['mirror'] : false;
                $normalized['overrides'][$version] = $settings;
            }
        }

        // Keep original key for direct access mirroring YAML name
        $normalized['version_overrides'] = $normalized['overrides'];

        return $normalized;
    }

    /**
     * Recursively convert array keys to lower/upper case
     * @param array $input
     * @param int $case
     * @return array
     */
    static private function arrayChangeKeyCaseRecursive(array $input, $case = CASE_LOWER)
    {
        $output = [];
        foreach ($input as $key => $value) {
            $newKey = ($case === CASE_LOWER) ? strtolower($key) : strtoupper($key);
            $output[$newKey] = is_array($value) ? static::arrayChangeKeyCaseRecursive($value, $case) : $value;
        }

        return $output;
    }

    /**
     * @param $nm
     * @return mixed|null
     */
    static function get($nm)
    {
        if (!static::$initialized) {
            static::init();
        }

        if (isset(static::$CONF[$nm])) {
            return static::$CONF[$nm];
        }

        $parts = explode('.', $nm);
        $current = static::$CONF;

        foreach ($parts as $part) {
            $key = static::findArrayKey($current, $part);
            if ($key === null || !isset($current[$key])) {
                return null;
            }
            $current = $current[$key];
        }

        return $current;
    }

    /**
     * @return string
     */
    static public function getDataDir()
    {
        if (!static::$initialized) {
            static::init();
        }

        return static::$CONF['data']['dir'] ?? Tools::ds(SELF, 'data');
    }

    /**
     * @param array $array
     * @param string $needle
     * @return string|null
     */
    static private function findArrayKey(array $array, $needle)
    {
        foreach ($array as $key => $value) {
            if (strcasecmp($key, $needle) === 0) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param $i
     * @return int
     */
    static public function upd_version_is_set($i)
    {
        return (isset(static::$CONF['eset']['version_' . strval($i)]) ? static::$CONF['eset']['version_' . strval($i)] : 0);
    }

    /**
     * @throws ConfigException
     * @throws Exception
     */
    static private function check_config()
    {
        if (array_search(PHP_OS, array("Darwin", "Linux", "FreeBSD", "OpenBSD", "WINNT")) === false)
            throw new ConfigException(Language::t('script.unsupported_os'));

        if (function_exists("date_default_timezone_set") and function_exists("date_default_timezone_get")) {
            if (empty(static::$CONF['script']['timezone'])) {
                date_default_timezone_set(@date_default_timezone_get());
            } else {
                if (@date_default_timezone_set(static::$CONF['script']['timezone']) === false) {
                    static::$CONF['log']['file']['rotate']['enabled'] = false;
                    throw new ConfigException("Error in timezone settings! Please, check your config file!");
                }
            }
        }

        $logConfig = static::$CONF['log'] ?? [];
        $fileConfig = $logConfig['file'] ?? [];
        $stdoutConfig = $logConfig['stdout'] ?? [];

        if (!empty($fileConfig['rotate']['enabled'])) {
            if (intval($fileConfig['rotate']['qty'] ?? 0) < 1) {
                throw new ConfigException("Please, check set up of (log.file.rotate) qty in your config file!");
            } else {
                $fileConfig['rotate']['qty'] = intval($fileConfig['rotate']['qty']);
            }
        }

        $logConfig['file'] = $fileConfig;
        $logConfig['stdout'] = $stdoutConfig;
        static::$CONF['log'] = $logConfig;

        while (substr(static::$CONF['script']['web_dir'], -1) == DS)
            static::$CONF['script']['web_dir'] = substr(static::$CONF['script']['web_dir'], 0, -1);

        while (substr(static::$CONF['data']['dir'], -1) == DS)
            static::$CONF['data']['dir'] = substr(static::$CONF['data']['dir'], 0, -1);

        if (!empty(static::$CONF['log']['file']['dir'])) {
            while (substr(static::$CONF['log']['file']['dir'], -1) == DS)
                static::$CONF['log']['file']['dir'] = substr(static::$CONF['log']['file']['dir'], 0, -1);
        }

        @mkdir(PATTERN, 0755, true);
        @mkdir(static::$CONF['data']['dir'], 0755, true);
        if (!empty(static::$CONF['log']['file']['dir'])) {
            @mkdir(static::$CONF['log']['file']['dir'], 0755, true);
        }
        @mkdir(static::$CONF['script']['web_dir'], 0755, true);
        @mkdir(TMP_PATH, 0755, true);

        if (!empty(static::$CONF['script']['debug_html']))
            @mkdir(Tools::ds(static::$CONF['data']['dir'], DEBUG_DIR), 0755, true);

        if (intval(static::$CONF['find']['errors_quantity'] ?? 0) <= 0) static::$CONF['find']['errors_quantity'] = 1;
        if (empty(static::$CONF['find']['user_agent'])) {
            static::$CONF['find']['user_agent'] = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36";
        }
        if (!isset(static::$CONF['find']['headers'])) {
            static::$CONF['find']['headers'] = [];
        } elseif (is_string(static::$CONF['find']['headers'])) {
            static::$CONF['find']['headers'] = array_filter(array_map('trim', preg_split('/[\r\n,]+/', static::$CONF['find']['headers'])));
        } elseif (is_array(static::$CONF['find']['headers'])) {
            static::$CONF['find']['headers'] = array_filter(array_map('trim', static::$CONF['find']['headers']));
        } else {
            static::$CONF['find']['headers'] = [];
        }

        if (!is_readable(PATTERN)) throw new ConfigException("Pattern directory is not readable. Check your permissions!");

        if (!is_writable(static::$CONF['data']['dir'])) throw new ConfigException("Data directory is not writable. Check your permissions!");

        if (!empty(static::$CONF['log']['file']['enabled'])) {
            if (!is_writable(static::$CONF['log']['file']['dir'])) throw new ConfigException("Log directory is not writable. Check your permissions!");
        }

        if (!is_writable(static::$CONF['script']['web_dir'])) throw new ConfigException("Web directory is not writable. Check your permissions!");
    }

    /**
     * Normalize query config value to array.
     * Supports string or array input.
     * @param mixed $value
     * @return array
     */
    static public function normalizeQueryList($value)
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
     * @return array
     */
    static public function getConnectionInfo()
    {
        if (!static::$initialized) {
            static::init();
        }

        Log::write_log(Language::t('log.running', __METHOD__), Log::LEVEL_TRACE);

        $connection = static::$CONF['connection'];

        $options = [
            CURLOPT_BINARYTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => intval($connection['timeout'] ?? 5),
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];

        if (!empty($connection['speed_limit'])) {
            $options[CURLOPT_MAX_RECV_SPEED_LARGE] = $connection['speed_limit'];
        }

        if (!empty($connection['proxy']['enabled'])) {
            $options[CURLOPT_PROXY] = $connection['proxy']['server'] ?? '';
            $options[CURLOPT_PROXYPORT] = $connection['proxy']['port'] ?? 80;

            $proxyType = $connection['proxy']['type'] ?? 'http';
            switch ($proxyType) {
                case 'socks4':
                    $options[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS4;
                    break;
                case 'socks4a':
                    $options[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS4A;
                    break;
                case 'socks5':
                    $options[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5;
                    break;
                case 'http':
                default:
                    $options[CURLOPT_PROXYTYPE] = CURLPROXY_HTTP;
                    break;
            }

            if (!empty($connection['proxy']['user'])) {
                $options[CURLOPT_PROXYUSERNAME] = $connection['proxy']['user'];
                $options[CURLOPT_PROXYPASSWORD] = $connection['proxy']['password'];
            }
        }

        return $options;
    }
}

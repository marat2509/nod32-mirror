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

        if (!isset(static::$CONF['script']['generate_json'])) {
            static::$CONF['script']['generate_json'] = false;
        }

        // Normalize boolean-like options to real booleans
        foreach (['generate_html', 'generate_json', 'generate_only_table', 'show_login_password', 'debug_update', 'debug_html'] as $opt) {
            if (isset(static::$CONF['script'][$opt])) {
                static::$CONF['script'][$opt] = (bool) static::$CONF['script'][$opt];
            }
        }

        foreach (['proxy', 'use_multidownload'] as $opt) {
            if (isset(static::$CONF['connection'][$opt])) {
                static::$CONF['connection'][$opt] = (bool) static::$CONF['connection'][$opt];
            }
        }

        foreach (['rotate'] as $opt) {
            if (isset(static::$CONF['log'][$opt])) {
                static::$CONF['log'][$opt] = (bool) static::$CONF['log'][$opt];
            }
        }

        foreach (['enabled', 'remove_invalid_keys'] as $opt) {
            if (isset(static::$CONF['find'][$opt])) {
                static::$CONF['find'][$opt] = (bool) static::$CONF['find'][$opt];
            }
        }

        if (empty(static::$CONF['script']['filename_json'])) {
            static::$CONF['script']['filename_json'] = 'index.json';
        }

        if (empty(static::$CONF['eset']['mirror'])) {
            throw new ConfigException(Language::t("ESET mirrors list is not set!"));
        }

        static::$CONF['eset']['mirror'] = static::normalizeMirrorList(static::$CONF['eset']['mirror']);

        /*
        if (!in_array("update.eset.com", static::$CONF['eset']['mirror'])) {
            static::$CONF['eset']['mirror'][] = "update.eset.com";
        }
        */

        if (empty(static::$CONF['script']['web_dir'])) {
            static::$CONF['script']['web_dir'] = "www";
        }

        if (empty(static::$CONF['log']['dir'])) {
            static::$CONF['log']['dir'] = "log";
        }

        if (empty(static::$CONF['data']['dir'])) {
            static::$CONF['data']['dir'] = "data";
        }

        if (preg_match("/^win/i", PHP_OS) == false) {
            if (substr(static::$CONF['script']['web_dir'], 0, 1) != DS) {
                static::$CONF['script']['web_dir'] = Tools::ds(SELF, static::$CONF['script']['web_dir']);
            }
            if (substr(static::$CONF['log']['dir'], 0, 1) != DS) {
                static::$CONF['log']['dir'] = Tools::ds(SELF, static::$CONF['log']['dir']);
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
            throw new ConfigException(Language::t("Config file does not exist!"));
        }

        if (!is_readable($configPath)) {
            throw new ConfigException(Language::t("Can't read config file! Check the file and its permissions!"));
        }

        try {
            $parsed = Yaml::parseFile($configPath);
        } catch (ParseException $e) {
            throw new ConfigException(Language::t("Failed to parse config file: %s", $e->getMessage()));
        }

        if (empty($parsed) || !is_array($parsed)) {
            throw new ConfigException(Language::t("Empty config file!"));
        }

        static::$CONF = static::normalizeConfig($parsed);
        static::$configPath = $configPath;
    }

    /**
     * Normalize YAML config to legacy-friendly structure
     * @param array $config
     * @return array
     */
    static private function normalizeConfig(array $config)
    {
        // Convert all keys to lowercase for consistent access
        $config = static::arrayChangeKeyCaseRecursive($config, CASE_LOWER);

        $config['script'] = static::normalizeSection($config, 'script');
        $config['connection'] = static::normalizeSection($config, 'connection');
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
            throw new ConfigException(Language::t("This script doesn't support your OS. Please, contact developer!"));

        if (function_exists("date_default_timezone_set") and function_exists("date_default_timezone_get")) {
            if (empty(static::$CONF['script']['timezone'])) {
                date_default_timezone_set(@date_default_timezone_get());
            } else {
                if (@date_default_timezone_set(static::$CONF['script']['timezone']) === false) {
                    static::$CONF['log']['rotate'] = 0;
                    throw new ConfigException("Error in timezone settings! Please, check your config file!");
                }
            }
        }

        $logConfig = static::$CONF['log'];
        $rotateEnabled = !empty($logConfig['rotate']);

        if ($rotateEnabled) {
            $logConfig['rotate_size'] = Tools::human2bytes((string)($logConfig['rotate_size'] ?? '0'));

            if (intval($logConfig['rotate_qty'] ?? 0) < 1) {
                throw new ConfigException("Please, check set up of (log) rotate_qty in your config file!");
            } else {
                $logConfig['rotate_qty'] = intval($logConfig['rotate_qty']);
            }

            if (intval($logConfig['type'] ?? -1) < 0 || intval($logConfig['type']) > 3)
                throw new ConfigException("Please, check set up of (log) type in your config file!");
        }
        static::$CONF['log'] = $logConfig;

        while (substr(static::$CONF['script']['web_dir'], -1) == DS)
            static::$CONF['script']['web_dir'] = substr(static::$CONF['script']['web_dir'], 0, -1);

        while (substr(static::$CONF['data']['dir'], -1) == DS)
            static::$CONF['data']['dir'] = substr(static::$CONF['data']['dir'], 0, -1);

        while (substr(static::$CONF['log']['dir'], -1) == DS)
            static::$CONF['log']['dir'] = substr(static::$CONF['log']['dir'], 0, -1);

        @mkdir(PATTERN, 0755, true);
        @mkdir(static::$CONF['data']['dir'], 0755, true);
        @mkdir(static::$CONF['log']['dir'], 0755, true);
        @mkdir(static::$CONF['script']['web_dir'], 0755, true);
        @mkdir(TMP_PATH, 0755, true);

        if (!empty(static::$CONF['script']['debug_html']))
            @mkdir(Tools::ds(static::$CONF['data']['dir'], DEBUG_DIR), 0755, true);

        if (intval(static::$CONF['find']['errors_quantity'] ?? 0) <= 0) static::$CONF['find']['errors_quantity'] = 1;

        if (!is_readable(PATTERN)) throw new ConfigException("Pattern directory is not readable. Check your permissions!");

        if (!is_writable(static::$CONF['data']['dir'])) throw new ConfigException("Data directory is not writable. Check your permissions!");

        if (!is_writable(static::$CONF['log']['dir'])) throw new ConfigException("Log directory is not writable. Check your permissions!");

        if (!is_writable(static::$CONF['script']['web_dir'])) throw new ConfigException("Web directory is not writable. Check your permissions!");
    }

    /**
     * @return array
     */
    static public function getConnectionInfo()
    {
        if (!static::$initialized) {
            static::init();
        }

        Log::write_log(Language::t("Running %s", __METHOD__), 5);

        $connection = static::$CONF['connection'];

        $options = [
            CURLOPT_BINARYTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => intval($connection['timeout'] ?? 5),
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
        ];

        if (!empty($connection['download_speed_limit'])) {
            $options[CURLOPT_MAX_RECV_SPEED_LARGE] = $connection['download_speed_limit'];
        }

        if (!empty($connection['proxy'])) {
            $options[CURLOPT_PROXY] = $connection['server'] ?? '';
            $options[CURLOPT_PROXYPORT] = $connection['port'] ?? 80;

            $proxyType = $connection['type'] ?? 'http';
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

            if (!empty($connection['user'])) {
                $options[CURLOPT_PROXYUSERNAME] = $connection['user'];
                $options[CURLOPT_PROXYPASSWORD] = $connection['password'];
            }
        }

        return $options;
    }
}

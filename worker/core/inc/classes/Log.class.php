<?php

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger as MonologLogger;

/**
 * Logging wrapper backed by Monolog.
 */
class Log
{
    private const MEMORY_LOG_LIMIT = 500;

    // Lower value means higher priority (legacy-compatible scale)
    public const LEVEL_ERROR = 0;
    public const LEVEL_WARNING = 1;
    public const LEVEL_NOTICE = 2;
    public const LEVEL_INFO = 3;
    public const LEVEL_DEBUG = 4;
    public const LEVEL_TRACE = 5;

    private const LEVEL_LABELS = [
        self::LEVEL_ERROR => 'error',
        self::LEVEL_WARNING => 'warning',
        self::LEVEL_NOTICE => 'notice',
        self::LEVEL_INFO => 'info',
        self::LEVEL_DEBUG => 'debug',
        self::LEVEL_TRACE => 'trace',
    ];

    private const LEVEL_NAME_MAP = [
        'error' => self::LEVEL_ERROR,
        'err' => self::LEVEL_ERROR,
        'warning' => self::LEVEL_WARNING,
        'warn' => self::LEVEL_WARNING,
        'notice' => self::LEVEL_NOTICE,
        'info' => self::LEVEL_INFO,
        'information' => self::LEVEL_INFO,
        'debug' => self::LEVEL_DEBUG,
        'verbose' => self::LEVEL_DEBUG,
        'trace' => self::LEVEL_TRACE,
    ];

    /**
     * @var array<string>
     */
    static private $log = array();

    /**
     * @var array
     */
    static private $CONF;

    /**
     * @var bool
     */
    static private $initialized = false;

    /**
     * @var MonologLogger|null
     */
    static private $logger = null;

    /**
     * @var StreamHandler|null
     */
    static private $fileHandler = null;

    /**
     * @var StreamHandler|null
     */
    static private $stdoutHandler = null;

    /**
     * @param $str
     * @param $ver
     * @param int $level
     * @param null|string $channel
     */
    static public function informer($str, $ver, $level = self::LEVEL_NOTICE, $channel = null)
    {
        static::write_log($str, $level, $ver, $channel);
    }

    /**
     * Convenience wrappers for standard levels
     */
    static public function error($text, $version = null, $channel = null)
    {
        static::write_log($text, static::LEVEL_ERROR, $version, $channel);
    }

    static public function warning($text, $version = null, $channel = null)
    {
        static::write_log($text, static::LEVEL_WARNING, $version, $channel);
    }

    static public function notice($text, $version = null, $channel = null)
    {
        static::write_log($text, static::LEVEL_NOTICE, $version, $channel);
    }

    static public function info($text, $version = null, $channel = null)
    {
        static::write_log($text, static::LEVEL_INFO, $version, $channel);
    }

    static public function debug($text, $version = null, $channel = null)
    {
        static::write_log($text, static::LEVEL_DEBUG, $version, $channel);
    }

    static public function trace($text, $version = null, $channel = null)
    {
        static::write_log($text, static::LEVEL_TRACE, $version, $channel);
    }

    /**
     * @param mixed $level
     * @return bool
     */
    static public function isLevelEnabled($level)
    {
        if (!static::$initialized) {
            return false;
        }

        $level = static::normalizeLevel($level);
        $fileConfig = static::$CONF['file'];
        $stdoutConfig = static::$CONF['stdout'];

        return static::isChannelEnabled($fileConfig, $level) || static::isChannelEnabled($stdoutConfig, $level);
    }

    /**
     * Central logging method (delegates to Monolog).
     *
     * @param mixed $text
     * @param mixed $level
     * @param mixed $version
     * @param null|string $channel
     * @param bool $ignore_rotate
     * @return null
     */
    static public function write_log($text, $level, $version = null, $channel = null, $ignore_rotate = false)
    {
        if ($text === null || $text === '') {
            return null;
        }

        $level = static::normalizeLevel($level);
        $message = static::stringifyMessage($text);

        if (!static::$initialized || static::$logger === null) {
            error_log(static::formatFallback($message, $level, $version, $channel));
            return null;
        }

        $fileConfig = static::$CONF['file'];
        $stdoutConfig = static::$CONF['stdout'];

        $logToFile = static::isChannelEnabled($fileConfig, $level);
        $logToStdout = static::isChannelEnabled($stdoutConfig, $level);

        if (!$logToFile && !$logToStdout) {
            return null;
        }

        if ($channel === null && $version !== null && class_exists('Mirror', false) && property_exists('Mirror', 'channel')) {
            $channel = Mirror::$channel;
        }

        $versionLabel = static::formatVersionLabel($version, $channel);
        $monologLevel = static::mapToMonologLevel($level);
        $finalMessage = $versionLabel . (($level === self::LEVEL_TRACE) ? '[trace] ' . $message : $message);

        if ($logToFile && !empty($fileConfig['rotate']['enabled']) && !$ignore_rotate) {
            static::rotateIfNeeded($fileConfig);
        }

        static::$logger->log($monologLevel, $finalMessage);
        static::remember(static::formatRecordForMemory($finalMessage, $level));

        return null;
    }

    /**
     * @throws ConfigException
     * @throws Exception
     */
    static public function init()
    {
        Config::init();

        $logConfig = Config::get('log');
        $scriptConfig = Config::get('script');

        if (empty($logConfig)) {
            throw new ConfigException("Log parameters don't set!");
        }

        static::$CONF = $logConfig;
        static::$CONF['codepage'] = $scriptConfig['codepage'] ?? 'utf-8';

        if (!empty(static::$CONF['file']['enabled']) && !file_exists(static::$CONF['file']['dir'])) {
            mkdir(static::$CONF['file']['dir'], 0755, true);
        }

        static::buildLogger();
        static::$initialized = true;
    }

    /**
     * @return bool
     */
    static public function isInitialized()
    {
        return static::$initialized;
    }

    /**
     * Normalize level to internal constant
     * @param mixed $level
     * @return int
     */
    public static function normalizeLevel($level)
    {
        if (is_string($level)) {
            $normalized = strtolower(trim($level));
            if ($normalized === '') {
                return self::LEVEL_INFO;
            }

            if (isset(self::LEVEL_NAME_MAP[$normalized])) {
                $level = self::LEVEL_NAME_MAP[$normalized];
            } elseif (is_numeric($level)) {
                $level = intval($level);
            } else {
                return self::LEVEL_INFO;
            }
        }

        $level = intval($level);
        return max(self::LEVEL_ERROR, min(self::LEVEL_TRACE, $level));
    }

    /**
     * Create Monolog logger and handlers from config
     */
    private static function buildLogger()
    {
        $logger = new MonologLogger('nod32ms');

        $formatter = new LineFormatter("[%datetime%] [%level_name%] %message%\n", "Y-m-d, H:i:s", true, true);

        static::$fileHandler = null;
        static::$stdoutHandler = null;

        $fileConfig = static::$CONF['file'] ?? [];
        if (!empty($fileConfig['enabled'])) {
            $filePath = Tools::ds($fileConfig['dir'], LOG_FILE);
            $minLevel = static::mapToMonologLevel(static::normalizeLevel($fileConfig['level'] ?? self::LEVEL_DEBUG));
            $fileHandler = new StreamHandler($filePath, $minLevel);
            $fileHandler->setFormatter($formatter);
            static::$fileHandler = $fileHandler;
            $logger->pushHandler($fileHandler);
        }

        $stdoutConfig = static::$CONF['stdout'] ?? [];
        if (!empty($stdoutConfig['enabled'])) {
            $minLevel = static::mapToMonologLevel(static::normalizeLevel($stdoutConfig['level'] ?? self::LEVEL_DEBUG));
            $stdoutHandler = new StreamHandler('php://stdout', $minLevel);
            $stdoutHandler->setFormatter($formatter);
            static::$stdoutHandler = $stdoutHandler;
            $logger->pushHandler($stdoutHandler);
        }

        static::$logger = $logger;
    }

    /**
     * Rotate log file when size threshold reached; rebuild logger to reopen stream.
     * @param array $fileConfig
     */
    private static function rotateIfNeeded(array $fileConfig)
    {
        if (empty($fileConfig['enabled'])) {
            return;
        }

        $limit = intval($fileConfig['rotate']['size'] ?? 0);
        if ($limit <= 0) {
            return;
        }

        $filePath = Tools::ds($fileConfig['dir'], LOG_FILE);
        if (!file_exists($filePath)) {
            return;
        }

        clearstatcache(true, $filePath);
        if (filesize($filePath) < $limit) {
            return;
        }

        $arch_ext = Tools::get_archive_extension();

        for ($i = $fileConfig['rotate']['qty']; $i > 1; $i--) {
            @unlink($filePath . "." . strval($i) . $arch_ext);
            @rename($filePath . "." . strval($i - 1) . $arch_ext, $filePath . "." . strval($i) . $arch_ext);
        }

        @unlink($filePath . ".1" . $arch_ext);
        Tools::archive_file($filePath);
        @unlink($filePath);

        // Close handlers and rebuild with fresh stream
        if (static::$fileHandler && method_exists(static::$fileHandler, 'close')) {
            static::$fileHandler->close();
        }
        static::buildLogger();
    }

    /**
     * Map internal level to Monolog level
     * @param int $level
     * @return int
     */
    private static function mapToMonologLevel($level)
    {
        switch ($level) {
            case self::LEVEL_ERROR:
                return MonologLogger::ERROR;
            case self::LEVEL_WARNING:
                return MonologLogger::WARNING;
            case self::LEVEL_NOTICE:
                return MonologLogger::NOTICE;
            case self::LEVEL_INFO:
                return MonologLogger::INFO;
            case self::LEVEL_DEBUG:
            case self::LEVEL_TRACE:
            default:
                return MonologLogger::DEBUG;
        }
    }

    /**
     * Format version/channel block for output
     * @param mixed $version
     * @param string|null $channel
     * @return string
     */
    private static function formatVersionLabel($version, $channel = null)
    {
        if ($version === null || $version === '') {
            return '';
        }

        $versionLabel = '[' . strval($version);
        if (!empty($channel)) {
            $versionLabel .= ' (' . strval($channel) . ')';
        }
        $versionLabel .= '] ';

        return $versionLabel;
    }

    /**
     * Bootstrap-friendly format for logs emitted before init()
     * @param string $message
     * @param int $level
     * @param mixed $version
     * @param string|null $channel
     * @return string
     */
    private static function formatFallback($message, $level, $version, $channel)
    {
        $versionLabel = ($version !== null) ? static::formatVersionLabel($version, $channel) : '';
        $levelName = strtoupper(static::levelToName($level));

        return sprintf("[bootstrap][%s] %s%s", $levelName, $versionLabel, $message);
    }

    /**
     * Convert numeric level to human label
     * @param int $level
     * @return string
     */
    private static function levelToName($level)
    {
        return static::LEVEL_LABELS[$level] ?? 'level-' . strval($level);
    }

    /**
     * @param array $channelConfig
     * @param int $level
     * @return bool
     */
    private static function isChannelEnabled(array $channelConfig, int $level)
    {
        if (empty($channelConfig['enabled'])) {
            return false;
        }

        $channelLevel = static::normalizeLevel($channelConfig['level'] ?? static::LEVEL_INFO);

        return ($channelLevel >= $level);
    }

    /**
     * Safely convert log payload into string
     * @param mixed $message
     * @return string
     */
    private static function stringifyMessage($message)
    {
        if (is_string($message)) {
            return $message;
        }

        if (is_scalar($message)) {
            return strval($message);
        }

        $json = @json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json !== false ? $json : var_export($message, true);
    }

    /**
     * Keep a small in-memory buffer to aid debugging and rotation
     * @param string $text
     */
    private static function remember($text)
    {
        static::$log[] = $text;
        if (count(static::$log) > static::MEMORY_LOG_LIMIT) {
            array_shift(static::$log);
        }
    }

    /**
     * Format message for in-memory buffer (best-effort mirror of output)
     * @param string $message
     * @param int $level
     * @return string
     */
    private static function formatRecordForMemory($message, $level)
    {
        $levelName = strtoupper(static::levelToName($level));

        return sprintf("[%s] [%s] %s", date("Y-m-d, H:i:s"), $levelName, $message);
    }

}

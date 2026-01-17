<?php

/**
 * Class Log
 */
class Log
{
    /**
     * @var array
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
     * @param $filename
     * @param $text
     */
    static public function write_to_file($filename, $text)
    {
        $dir = dirname($filename);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $result = @file_put_contents($filename, $text, FILE_APPEND | LOCK_EX);

        if ($result === false) {
            error_log(sprintf("Failed to write log file: %s", $filename));
        }
    }

    /**
     * @param $str
     * @param $ver
     * @param int $level
     */
    static public function informer($str, $ver, $level = 0)
    {
        static::write_log($str, $level, $ver);
    }

    /**
     * @param $text
     * @param $level
     * @param null $version
     * @param bool $ignore_rotate
     * @return null
     */
    static public function write_log($text, $level, $version = null, $ignore_rotate = false)
    {
        if (empty($text)) return null;

        if (!static::$initialized) {
            error_log($text);
            return null;
        }

        $fileConfig = static::$CONF['file'];
        $stdoutConfig = static::$CONF['stdout'];

        $logToFile = (!empty($fileConfig['enabled']) && ($fileConfig['level'] >= $level));
        $logToStdout = (!empty($stdoutConfig['enabled']) && ($stdoutConfig['level'] >= $level));

        if (!$logToFile && !$logToStdout) return null;

        $fn = Tools::ds($fileConfig['dir'], LOG_FILE);

        if ($logToFile && !empty($fileConfig['rotate']['enabled'])) {
            if (file_exists($fn) && !$ignore_rotate) {
                $arch_ext = Tools::get_archive_extension();
                if (filesize($fn) >= ($fileConfig['rotate']['size'] ?? 0)) {
                    static::write_log(Language::t('log.rotated'), 0, null, true);
                    array_pop(static::$log);

                    for ($i = $fileConfig['rotate']['qty']; $i > 1; $i--) {
                        @unlink($fn . "." . strval($i) . $arch_ext);
                        @rename($fn . "." . strval($i - 1) . $arch_ext, $fn . "." . strval($i) . $arch_ext);
                    }

                    @unlink($fn . ".1" . $arch_ext);
                    Tools::archive_file($fn);
                    @unlink($fn);
                    static::write_log(Language::t('log.rotated'), 0, null, true);
                    array_pop(static::$log);
                }
            }
        }

        $text = sprintf("[%s] %s%s", date("Y-m-d, H:i:s"), ($version ? '[ver. ' . strval($version) . '] ' : ''), $text);

        if ($logToFile)
            static::write_to_file($fn, Tools::conv($text . "\r\n", static::$CONF['codepage']));

        if ($logToStdout) echo Tools::conv($text, static::$CONF['codepage']) . chr(10);
        static::$log[] = $text;
        return;
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

        static::$initialized = true;
    }

    /**
     * @return bool
     */
    static public function isInitialized()
    {
        return static::$initialized;
    }
}

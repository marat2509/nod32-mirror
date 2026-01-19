<?php

/**
 * Class Tools
 */
class Tools
{
    /**
     * @param array $options
     * @param $headers
     * @return mixed
     */
    static public function download_file($options = array(), &$headers = null)
    {
        Log::write_log(Language::t('log.running', __METHOD__), Log::LEVEL_TRACE, Mirror::$version);
        $out = FALSE;
        $fileTarget = null;

        if (key_exists(CURLOPT_FILE, $options)) {
            $dir = dirname($options[CURLOPT_FILE]);
            if (!@file_exists($dir)) @mkdir($dir, 0755, true);
            $fileTarget = $options[CURLOPT_FILE];
            $out = fopen($fileTarget, "wb");
            if (!is_resource($out)) return false;
            $options[CURLOPT_FILE] = $out;
        }

        $ch = curl_init();
        curl_setopt_array($ch, $options);
        $res = curl_exec($ch);

        $headers = curl_getinfo($ch);
        if ($out) @fclose($out);

        if ($res === false) {
            $errorMessage = sprintf("Curl error (%s): %s", curl_errno($ch), curl_error($ch));
            if ($fileTarget) {
                @unlink($fileTarget);
            }
            if (class_exists('Log')) {
                Log::write_log($errorMessage, Log::LEVEL_ERROR, Mirror::$version);
            }
        }

        curl_close($ch);

        if (key_exists(CURLOPT_RETURNTRANSFER, $options)) {
            if ($options[CURLOPT_RETURNTRANSFER] == 1) return $res;
        }

        return $res !== false;
    }

    /**
     * @return string
     */
    static public function get_archive_extension()
    {
        return ".gz";
    }

    /**
     * @param $file
     * @return string
     */
    static public function get_file_mimetype($file)
    {
        $f = new finfo();
        $info = $f->file($file, FILEINFO_MIME_TYPE);
        return $info;
    }

    /**
     * @param $file
     */
    static public function archive_file($file)
    {
        $fp = gzopen($file . ".1.gz", 'w9');
        gzwrite($fp, file_get_contents($file));
        gzclose($fp);
        unlink($file);
    }

    /**
     * @param array $options
     * @param $hostname
     * @param int $port
     * @param null $file
     * @return bool
     */
    static public function ping(array $options, $hostname, $port = 80, $file = NULL)
    {
        static::download_file(
            ([
                    CURLOPT_URL => "http://" . $hostname . "/" . $file,
                    CURLOPT_PORT => $port,
                    CURLOPT_NOBODY => 1
                ] + $options),
            $headers
        );
        return (is_array($headers)) ? true : false;
    }

    /**
     * @param $bytes
     * @param int $precision
     * @return string
     */
    static public function bytesToSize1024($bytes, $precision = 2)
    {
        $unit = [Language::t('common.bytes'), Language::t('common.kbytes'), Language::t('common.mbytes'), Language::t('common.gbytes'), Language::t('common.tbytes'), Language::t('common.pbytes'), Language::t('common.ebytes')];
        return $bytes > 0 ? @round($bytes / pow(1024, ($i = floor(log($bytes, 1024)))), $precision) . ' ' . $unit[intval($i)] :  '0 ' . $unit[intval(0)];
    }

    /**
     * @param $secs
     * @return false|string
     */
    static public function secondsToHumanReadable($secs)
    {
        return ($secs > 60 * 60 * 24) ? gmdate("H:i:s", $secs) : gmdate("i:s", $secs);
    }

    /**
     * @return mixed
     */
    static public function ds()
    {
        return preg_replace('/[\/\\\\]+/', DIRECTORY_SEPARATOR, implode('/', func_get_args()));
    }

    /**
     * @param $text
     * @param $to_encoding
     * @return mixed|string
     */
    static public function conv($text, $to_encoding)
    {
        if (preg_match("/utf-8/i", $to_encoding))
            return $text;
        elseif (function_exists('mb_convert_encoding'))
            return mb_convert_encoding($text, $to_encoding, 'UTF-8');
        elseif (function_exists('iconv'))
            return iconv('UTF-8', $to_encoding, $text);
        else {
            $conv = array();

            for ($x = 128; $x <= 143; $x++) {
                $conv['u'][] = chr(209) . chr($x);
                $conv['w'][] = chr($x + 112);

            }

            for ($x = 144; $x <= 191; $x++) {
                $conv['u'][] = chr(208) . chr($x);
                $conv['w'][] = chr($x + 48);
            }

            $conv['u'][] = chr(208) . chr(129);
            $conv['w'][] = chr(168);
            $conv['u'][] = chr(209) . chr(145);
            $conv['w'][] = chr(184);
            $conv['u'][] = chr(208) . chr(135);
            $conv['w'][] = chr(175);
            $conv['u'][] = chr(209) . chr(151);
            $conv['w'][] = chr(191);
            $conv['u'][] = chr(208) . chr(134);
            $conv['w'][] = chr(178);
            $conv['u'][] = chr(209) . chr(150);
            $conv['w'][] = chr(179);
            $conv['u'][] = chr(210) . chr(144);
            $conv['w'][] = chr(165);
            $conv['u'][] = chr(210) . chr(145);
            $conv['w'][] = chr(180);
            $conv['u'][] = chr(208) . chr(132);
            $conv['w'][] = chr(170);
            $conv['u'][] = chr(209) . chr(148);
            $conv['w'][] = chr(186);
            $conv['u'][] = chr(226) . chr(132) . chr(150);
            $conv['w'][] = chr(185);
            $win = str_replace($conv['u'], $conv['w'], $text);

            if (preg_match("/1251/i", $to_encoding))
                return $win;
            elseif (preg_match("/koi8/i", $to_encoding))
                return convert_cyr_string($win, 'w', 'k');
            elseif (preg_match("/866/i", $to_encoding))
                return convert_cyr_string($win, 'w', 'a');
            elseif (preg_match("/mac/i", $to_encoding))
                return convert_cyr_string($win, 'w', 'm');
            else
                return $text;
        }
    }

    /**
     * @param $resource
     * @return bool|mixed
     */
    static public function get_resource_id($resource)
    {
        if (!is_resource($resource)) {
            return false;
        }

        $parts = explode('#', (string)$resource);
        return end($parts);
    }

    /**
     * @param $file1
     * @param $file2
     * @return bool
     */
    static public function compare_files($file1, $file2)
    {
        return ($file1['size'] == $file2['size']);
    }

    /**
     * @param $str string
     * @return int
     * @throws Exception
     */
    static public function human2bytes($str)
    {
        $n = null;

        if (preg_match_all("/([0-9]+)([BKMG])/i", $str, $result, PREG_PATTERN_ORDER)) {
            $str = intval(trim($result[1][0]));

            if (count($result) != 3 || $str < 1 || empty($result[1][0]) || empty($result[2][0]))
                throw new Exception("Please, check set up of log.file.rotate.size in your config file!");

            switch (trim($result[2][0])) {
                case "g":
                case "G":
                    $n = $str << 30;
                    break;
                case "m":
                case "M":
                    $n = $str << 20;
                    break;
                case "k":
                case "K":
                    $n = $str << 10;
                    break;
            }
        }
        return $n;
    }

    /**
     * Parse comma-separated string into array with trimmed values
     * @param string $string The comma-separated string to parse
     * @param string $delimiter The delimiter to use (default: ',')
     * @return array Array of trimmed values
     */
    static public function parse_comma_list($string, $delimiter = ',')
    {
        if (empty($string)) {
            return [];
        }

        return array_map('trim', explode($delimiter, $string));
    }

    /**
     * Append text to file (creates directories if missing).
     * @param string $filename
     * @param string $text
     */
    static public function write_to_file($filename, $text)
    {
        $dir = dirname($filename);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $result = @file_put_contents($filename, $text, FILE_APPEND | LOCK_EX);

        if ($result === false) {
            error_log(sprintf("Failed to write file: %s", $filename));
        }
    }
}

<?php

/**
 * Class Mirror
 */
class Mirror
{
    /**
     * @var int
     */
    static public $total_downloads = 0;

    /**
     * @var
     */
    static public $version = null;

    /**
     * @var
     */
    static public $tmp_update_file = null;

    static public $local_update_file = null;

    /**
     * @var null
     */
    static public $source_update_file = null;

    static public $update_variants = array();

    static public $primary_variant = null;

    /**
     * @var null
     */
    static public $primary_channel = null;

    /**
     * @var null
     */
    static public $channel = null;

    /**
     * @var null
     */
    static public $name = null;

    /**
     * @var array
     */
    static public $mirrors = array();

    /**
     * @var array
     */
    static public $key = array();

    /**
     * @var bool
     */
    static public $updated = false;

    /**
     * @var array
     */
    static private $ESET;

    /**
     * @var array
     */
    static public $platforms = array();

    /**
     * @var array
     */
    static public $channels = array();

    /**
     * @var array
     */
    static public $platforms_found = array();


    static public $unAuthorized = false;

    /**
     *
     */
    static private function fix_time_stamp()
    {
        Log::write_log(Language::t('log.running', __METHOD__), Log::LEVEL_TRACE, static::$version);
        $fn = Tools::ds(Config::getDataDir(), SUCCESSFUL_TIMESTAMP);
        $timestamps = [];

        if (file_exists($fn)) {
            $json = json_decode(@file_get_contents($fn), true);

            if (is_array($json)) {
                $timestamps = $json;
            }
        }

        $timestamps[static::$version] = time();

        file_put_contents(
            $fn,
            json_encode($timestamps, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Public wrapper to update lastupdate.json even when database is up to date.
     */
    static public function touch_time_stamp()
    {
        static::fix_time_stamp();
    }

    /**
     * @return bool
     */
    static public function test_key()
    {
        Log::write_log(Language::t('log.running', __METHOD__), Log::LEVEL_TRACE, static::$version);
        Log::write_log(Language::t('mirror.testing_key', static::$key[0], static::$key[1]), Log::LEVEL_DEBUG, static::$version);

        $connection = Config::get('connection');
        $timeout = intval($connection['timeout'] ?? 5);
        $test_mirrors = [];
        foreach (static::$ESET['mirror'] as $mirror) {
            Tools::download_file(
                [
                    CURLOPT_USERPWD => static::$key[0] . ":" . static::$key[1],
                    CURLOPT_URL => "http://" . $mirror . "/" . static::$source_update_file,
                    CURLOPT_NOBODY => 1,
                    CURLOPT_TIMEOUT => $timeout
                ],
                $headers
            );

            if ($headers['http_code'] == 200) {
                $test_mirrors[$mirror] = round($headers['total_time'] * 1000);
            }
        }

        asort($test_mirrors);
        static::$mirrors = [];
        $maxVersion = 0;
        $sameMirrors = [];

        foreach ($test_mirrors as $mirror => $time) {
            $version = static::check_mirror($mirror);
            if ($version) {
                $maxVersion = $version > $maxVersion ? $version : $maxVersion;
                $sameMirrors[] = ['host' => $mirror, 'db_version' => $version];
            } else {
                Log::write_log(Language::t('mirror.skipped_unreadable_update_ver', $mirror), Log::LEVEL_WARNING, static::$version);
                continue;
            }
        }

        if (empty($sameMirrors)) {
            return false;
        }

        static::$mirrors = array_filter($sameMirrors, function ($v, $k) use ($maxVersion) {
            return $v['db_version'] == $maxVersion;
        }, ARRAY_FILTER_USE_BOTH);

        return count(static::$mirrors) > 0;
    }

    /**
     * @throws ToolsException
     */
    static public function find_best_mirrors()
    {
        /*Log::write_log(Language::t('log.running', __METHOD__), Log::LEVEL_TRACE, static::$version);
        $test_mirrors = [];

        foreach (static::$ESET['mirror'] as $mirror) {
            Tools::download_file(
                [
                    CURLOPT_USERPWD => static::$key[0] . ":" . static::$key[1],
                    CURLOPT_URL => "http://" . $mirror . "/" . static::$source_update_file,
                    CURLOPT_NOBODY => 1
                ],
                $headers
            );

            if ($headers['http_code'] == 200) {
                $test_mirrors[$mirror] = round($headers['total_time'] * 1000);
                Log::write_log(Language::t('mirror.active', $mirror), Log::LEVEL_INFO, static::$version);
            } else Log::write_log(Language::t('mirror.inactive', $mirror), Log::LEVEL_INFO, static::$version);
        }
        asort($test_mirrors);

        foreach ($test_mirrors as $mirror => $time)
            static::$mirrors[] = ['host' => $mirror, 'db_version' => static::check_mirror($mirror)];*/
    }

    /**
     * Extract channel name from variant key (e.g., "production:file" -> "production")
     * @param string|null $variantKey
     * @return string|null
     */
    static private function extract_channel_from_variant($variantKey)
    {
        if (empty($variantKey)) {
            return null;
        }

        if (strpos($variantKey, ':') !== false) {
            $parts = explode(':', $variantKey, 2);
            return $parts[0] !== '' ? $parts[0] : null;
        }

        return null;
    }

    /**
     * Update current channel context
     * @param string|null $variantKey
     * @return void
     */
    static private function set_channel_for_variant($variantKey)
    {
        $channel = static::extract_channel_from_variant($variantKey);

        if ($channel === null) {
            $channel = static::$primary_channel;
        }

        static::$channel = $channel;
    }

    /**
     * @param $mirror
     * @return int|null
     * @throws ToolsException
     */
    static public function check_mirror($mirror)
    {
        Log::write_log(Language::t('log.running', __METHOD__), Log::LEVEL_TRACE, static::$version);
        $new_version = null;
        $file = static::$tmp_update_file;
        Log::write_log(Language::t('mirror.checking_with_key', $mirror, static::$key[0], static::$key[1]), Log::LEVEL_DEBUG, static::$version);
        static::download_update_ver($mirror, true);
        $new_version = static::get_DB_version($file);
        @unlink($file);

        return $new_version;
    }

    /**
     * Download and read remote DB version for a specific variant
     * @param string $mirrorHost
     * @param string $variantKey
     * @return int|null
     * @throws ToolsException
     */
    static private function get_remote_variant_version($mirrorHost, $variantKey)
    {
        if (empty(static::$update_variants[$variantKey])) {
            return null;
        }

        $previousChannel = static::$channel;
        static::set_channel_for_variant($variantKey);

        try {
            static::download_update_ver($mirrorHost, false, $variantKey);

            $tmpPath = static::$update_variants[$variantKey]['tmp'] ?? null;
            if (!$tmpPath) {
                return null;
            }

            return static::get_DB_version($tmpPath);
        } finally {
            if (!empty(static::$update_variants[$variantKey]['tmp'])) {
                @unlink(static::$update_variants[$variantKey]['tmp']);
            }
            static::$channel = $previousChannel;
        }
    }

    /**
     * Check if all configured variants/channels are up to date
     * @param string|null $mirrorHost
     * @return bool
     * @throws ToolsException
     */
    static public function all_channels_up_to_date($mirrorHost, $logDetails = false)
    {
        Log::write_log(Language::t('log.running', __METHOD__), Log::LEVEL_TRACE, static::$version);

        if (!$mirrorHost) {
            return false;
        }

        if (empty(static::$update_variants)) {
            return true;
        }

        $allUpToDate = true;
        $details = array();

        foreach (static::$update_variants as $variantKey => $paths) {
            $localVersion = static::get_DB_version($paths['local']);
            $remoteVersion = static::get_remote_variant_version($mirrorHost, $variantKey);

            $upToDate = ($remoteVersion !== null && $localVersion !== null && intval($localVersion) >= intval($remoteVersion));
            $allUpToDate = $allUpToDate && $upToDate;

            if ($logDetails) {
                $details[] = sprintf(
                    '[%s] local=%s remote=%s status=%s',
                    $variantKey,
                    $localVersion !== null ? $localVersion : 'n/a',
                    $remoteVersion !== null ? $remoteVersion : 'n/a',
                    $upToDate ? 'ok' : 'update'
                );
            }
        }

        if ($logDetails && !empty($details)) {
            $detailsStr = implode('; ', $details);
            Log::write_log(Language::t('mirror.channel_status', $detailsStr), Log::LEVEL_TRACE, static::$version, 'all');
        }

        return $allUpToDate;
    }

    /**
     * @param $mirror
     * @throws ToolsException
     */
    static public function download_update_ver($mirror, $downloadRandomFile = false, $variantKey = null)
    {
        $variantKey = $variantKey ?: static::$primary_variant;
        static::set_channel_for_variant($variantKey);
        Log::write_log(Language::t('log.running', __METHOD__), Log::LEVEL_TRACE, static::$version);

        if (empty(static::$update_variants[$variantKey])) {
            return;
        }

        $variant = static::$update_variants[$variantKey];
        $tmp_path = dirname($variant['tmp']);
        $archive = Tools::ds($tmp_path, 'update.rar');
        $extracted = $variant['tmp'];
        $connection = Config::get('connection');
        $connectionOptions = Config::getConnectionInfo();
        $timeout = intval($connection['timeout'] ?? 5);
        $schemes = preg_match('#^https?://#i', $mirror) ? [$mirror] : ["https://{$mirror}", "http://{$mirror}"];
        $downloaded = false;

        foreach ($schemes as $baseUrl) {
            $targetUrl = rtrim($baseUrl, "/") . "/" . ltrim($variant['source'], "/");
            $downloaded = Tools::download_file(
                $connectionOptions + [
                    CURLOPT_USERPWD => static::$key[0] . ":" . static::$key[1],
                    CURLOPT_URL => $targetUrl,
                    CURLOPT_FILE => $archive,
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                ],
                $headers
            );

            if (is_array($headers) && $headers['http_code'] == 200 && $downloaded) {
                break;
            }
        }

        if (is_array($headers) and $headers['http_code'] == 200 and $downloaded) {
            if (file_exists($archive) && filesize($archive) === 0) {
                Log::write_log(Language::t('mirror.downloaded_empty_update_ver', $mirror), Log::LEVEL_WARNING, static::$version);
                @unlink($archive);
                return;
            }

            rename($archive, $extracted);

            if (!$downloadRandomFile) {
                return;
            }

            $content = @file_get_contents($extracted);
            if (preg_match_all('#\\[\\w+\\][^\\[]+#', $content, $matches))
            {
                list($new_files, $total_size, $new_content) = static::parse_update_file($matches[0]);
                $new_files = array_filter($new_files, function($v, $k) {
                    return $v['size'] <= 1024 * 1024;
                }, ARRAY_FILTER_USE_BOTH);
                shuffle($new_files);
                $file = array_shift($new_files);
                static::download([$file], true, $mirror);
            }
        } else {
            Log::write_log(Language::t('mirror.failed_download_update_ver', $mirror, $headers['http_code'] ?? 'n/a'), Log::LEVEL_WARNING, static::$version);
            @unlink($archive);
        }
    }

    /**
     * @return array
     * @throws Exception
     * @throws ToolsException
     */
    static public function download_signature()
    {
        Log::write_log(Language::t('log.running', __METHOD__), Log::LEVEL_TRACE, static::$version);

        if (empty(static::$update_variants)) {
            return array(null, static::$total_downloads, null);
        }

        $scriptConfig = Config::get('script');
        $web_dir = $scriptConfig['web_dir'] ?? SELF . 'www';
        $mirror = !empty(static::$mirrors) ? current(static::$mirrors) : null;
        $mirrorHost = $mirror ? $mirror['host'] : null;
        $total_size = null;
        $total_duration = 0;
        $total_downloaded = 0;
        $all_needed_files = array();
        $processed = false;

        foreach (static::$update_variants as $variantKey => $paths) {
            $result = static::process_update_variant($variantKey, $paths, $web_dir, $mirrorHost);

            if (empty($result['processed'])) {
                continue;
            }

            $processed = true;

            if ($result['size'] !== null) {
                if ($total_size === null) {
                    $total_size = 0;
                }
                $total_size += $result['size'];
            }

            if (!empty($result['needed_files'])) {
                $all_needed_files = array_merge($all_needed_files, $result['needed_files']);
            }

            $total_duration += $result['duration'];
            $total_downloaded += $result['downloaded'];
        }

        if ($processed) {
            $all_needed_files = array_values(array_unique($all_needed_files));
            $version = static::$version == 'v5' ? 'ep5' : static::$version;

            foreach (glob(Tools::ds($web_dir, $version . "-*"), GLOB_ONLYDIR) as $file) {
                $del_files = static::del_files($file, $all_needed_files);
                if ($del_files > 0) {
                    static::$updated = true;
                    Log::write_log(Language::t('mirror.deleted_files', $del_files) . " [" . basename($file) . "]", Log::LEVEL_INFO, static::$version);
                }
            }

            foreach (glob(Tools::ds($web_dir, $version . "-*"), GLOB_ONLYDIR) as $folder) {
                $del_folders = static::del_folders($folder);
                if ($del_folders > 0) {
                    static::$updated = true;
                    Log::write_log(Language::t('mirror.deleted_folders', $del_folders) . " [" . basename($folder) . "]", Log::LEVEL_INFO, static::$version);
                }
            }

            if (static::$updated) {
                static::fix_time_stamp();
            }
        } else {
            $logHost = $mirrorHost ?: 'unknown';
            Log::write_log(Language::t('mirror.update_ver_parse_error', $logHost), Log::LEVEL_WARNING, static::$version);
        }

        $average_speed = ($total_downloaded > 0 && $total_duration > 0)
            ? round($total_downloaded / $total_duration)
            : null;

        return array($total_size, static::$total_downloads, $average_speed);
    }

    static protected function process_update_variant($variantKey, $paths, $web_dir, $mirrorHost)
    {
        $result = array(
            'processed' => false,
            'size' => null,
            'needed_files' => array(),
            'duration' => 0,
            'downloaded' => 0
        );

        $previousChannel = static::$channel;
        static::set_channel_for_variant($variantKey);

        try {
            if (!$mirrorHost) {
                return $result;
            }

            static::download_update_ver($mirrorHost, false, $variantKey);

            $tmp_update_ver = $paths['tmp'];
            $local_update_ver = $paths['local'];
            $content = @file_get_contents($tmp_update_ver);

            if ($content === false) {
                Log::write_log(Language::t('mirror.update_ver_parse_error', $mirrorHost) . " ({$variantKey})", Log::LEVEL_WARNING, static::$version);
                @unlink($tmp_update_ver);
                return $result;
            }

            preg_match_all('#\\[\w+\][^\[]+#', $content, $matches);

            if (empty($matches[0])) {
                Log::write_log(Language::t('mirror.update_ver_parse_error', $mirrorHost) . " ({$variantKey})", Log::LEVEL_WARNING, static::$version);
                @unlink($tmp_update_ver);
                return $result;
            }

            list($new_files, $total_size, $new_content) = static::parse_update_file($matches[0]);
            list($download_files, $needed_files) = static::create_links($web_dir, $new_files);

            $before_download = static::$total_downloads;
            $start_time = microtime(true);

            $downloadSuccess = true;
            if (!empty($download_files)) {
                $downloadSuccess = static::download_files($download_files);
                if ($downloadSuccess && !static::$unAuthorized) {
                    static::$updated = true;
                }
            }

            $duration = (!empty($download_files)) ? (microtime(true) - $start_time) : 0;
            $downloaded = static::$total_downloads - $before_download;

            if (!$downloadSuccess) {
                Log::write_log(
                    Language::t('mirror.required_files_not_downloaded'),
                    Log::LEVEL_WARNING,
                    static::$version
                );
                @unlink($tmp_update_ver);
                return $result;
            }

            @file_put_contents($local_update_ver, $new_content);
            @unlink($tmp_update_ver);

            Log::write_log(Language::t('mirror.total_size', Tools::bytesToSize1024($total_size)) . " ({$variantKey})", Log::LEVEL_INFO, static::$version);

            if ($downloaded > 0 && $duration > 0) {
                $speed = round($downloaded / $duration);
                Log::write_log(Language::t('mirror.total_downloaded', Tools::bytesToSize1024($downloaded)) . " ({$variantKey})", Log::LEVEL_INFO, static::$version);
                Log::write_log(Language::t('mirror.average_speed', Tools::bytesToSize1024($speed)) . " ({$variantKey})", Log::LEVEL_INFO, static::$version);
            }

            $result['processed'] = true;
            $result['size'] = $total_size;
            $result['needed_files'] = $needed_files;
            $result['duration'] = $duration;
            $result['downloaded'] = max($downloaded, 0);
        } finally {
            static::$channel = $previousChannel;
        }

        return $result;
    }

    static protected function multiple_download($download_files, $onlyCheck = false, $checkedMirror = null)
    {
        Log::write_log(Language::t('log.running', __METHOD__), Log::LEVEL_TRACE, static::$version);
        $scriptConfig = Config::get('script');
        $web_dir = $onlyCheck ? Tools::ds(TMP_PATH) : ($scriptConfig['web_dir'] ?? SELF . 'www');
        $connection = Config::get('connection');
        $options = Config::getConnectionInfo();
        $timeout = intval($connection['timeout'] ?? 5);

        $mirrorList = static::$mirrors;
        if ($onlyCheck && $checkedMirror) $mirrorList = [['host' => $checkedMirror]];
        $mirrorCount = count($mirrorList);
        if ($mirrorCount === 0) {
            return;
        }
        $curlOpt = $options + [
            CURLOPT_USERPWD => static::$key[0] . ":" . static::$key[1]
        ];
        $mh = curl_multi_init();

        $max_threads = !empty($connection['multidownload']['threads']) ? $connection['multidownload']['threads'] : count($mirrorList);

        $chunks = array_chunk($download_files, $max_threads);
        $mirrorIndex = 0;

        foreach ($chunks as $key => $files)
        {
            $handlers = [];
            foreach ($files as $idx => $file)
            {
                $out = Tools::ds($web_dir, $file['file']);
                $dir = dirname($out);

                if (!@file_exists($dir)) @mkdir($dir, 0755, true);
                $fh = fopen($out, "wb");
                $ch = curl_init();
                $mirror = $mirrorList[$mirrorIndex % $mirrorCount];
                $mirrorIndex++;
                $options = $curlOpt + [
                    CURLOPT_URL => static::buildMirrorUrl($mirror['host'], $file['file']),
                    CURLOPT_FILE => $fh,
                    CURLOPT_TIMEOUT => $timeout
                ];

                curl_setopt_array($ch, $options);
                curl_multi_add_handle($mh,$ch);

                $handlers[$idx] = [
                    'curlH' => $ch,
                    'fileH' => $fh,
                    'file' => $file,
                    'mirror' => $mirror,
                    'out' => $out
                ];
            }

            do {
                $status = curl_multi_exec($mh, $active);
                if ($active) {
                    curl_multi_select($mh);
                }
            } while ($active && $status == CURLM_OK);

            foreach ($handlers as $tmp1) {
                @fclose($tmp1['fileH']);
            }

            foreach ($handlers as $tmp2)
            {

                $header = curl_getinfo($tmp2['curlH']);

                if (is_array($header) and $header['http_code'] == 200 and $header['size_download'] == $tmp2['file']['size']) {

                    Log::write_log(Language::t('mirror.downloaded_file', $tmp2['mirror']['host'], basename($tmp2['file']['file']),
                        Tools::bytesToSize1024($header['size_download']),
                        Tools::bytesToSize1024($header['size_download'] / $header['total_time'])),
                        Log::LEVEL_INFO,
                        static::$version
                    );
                    static::$total_downloads += $header['size_download'];
                } else {
                    @unlink($tmp2['out']);
                }
                curl_multi_remove_handle($mh, $tmp2['curlH']);
            }

        }

        curl_multi_close($mh);
    }

    /**
     * @param $download_files
     * @param false $onlyCheck
     * @param null $checkedMirror
     */
    static protected function single_download($download_files, $onlyCheck = false, $checkedMirror = null)
    {
        Log::write_log(Language::t('log.running', __METHOD__), Log::LEVEL_TRACE, static::$version);
        $scriptConfig = Config::get('script');
        $web_dir = $onlyCheck ? Tools::ds(TMP_PATH) : ($scriptConfig['web_dir'] ?? SELF . 'www');
        $connection = Config::get('connection');
        $timeout = intval($connection['timeout'] ?? 5);
        $baseOptions = Config::getConnectionInfo() + [
            CURLOPT_USERPWD => static::$key[0] . ":" . static::$key[1],
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];
        $mirrorList = static::$mirrors;
        if ($onlyCheck && $checkedMirror) $mirrorList = [['host' => $checkedMirror]];

        foreach ($download_files as $file) {
            foreach ($mirrorList as $id => $mirror) {

                $time = microtime(true);
                Log::write_log(Language::t('mirror.downloading_file', $file['file'], $mirror['host']), Log::LEVEL_INFO, static::$version);
                $out = Tools::ds($web_dir, $file['file']);
                $dir = dirname($out);

                if (!@file_exists($dir)) @mkdir($dir, 0755, true);

                $downloaded = false;
                $schemes = preg_match('#^https?://#i', $mirror['host'])
                    ? [$mirror['host']]
                    : ["https://{$mirror['host']}", "http://{$mirror['host']}"];

                foreach ($schemes as $baseHost) {
                    Tools::download_file(
                        $baseOptions + [
                            CURLOPT_URL => static::buildMirrorUrl($baseHost, $file['file']),
                            CURLOPT_FILE => $out,
                        ],
                        $header
                    );

                    if (is_array($header) && $header['http_code'] == 200 && $header['size_download'] == $file['size']) {
                        $downloaded = true;
                        break;
                    }

                    @unlink($out);
                }

                if ($downloaded) {
                    if ($onlyCheck) {
                        @unlink($out);
                        return;
                    }
                    static::$total_downloads += $header['size_download'];
                    Log::write_log(
                        Language::t(
                            'mirror.downloaded_file',
                            $mirror['host'],
                            basename($file['file']),
                            Tools::bytesToSize1024($header['size_download']),
                            Tools::bytesToSize1024($header['size_download'] / (microtime(true) - $time))
                        ),
                        Log::LEVEL_INFO,
                        static::$version
                    );
                    break;
                } else {
                    if ($onlyCheck) {
                        @unlink(static::$tmp_update_file);
                    }
                }
            }
        }
    }

    /**
     * @param $download_files
     * @param false $onlyCheck
     * @param null $checkedMirror
     */
    static protected function download($download_files, $onlyCheck = false, $checkedMirror = null)
    {
        Log::write_log(Language::t('log.running', __METHOD__), Log::LEVEL_TRACE, static::$version);

        $connection = Config::get('connection');
        $useMulti = function_exists('curl_multi_init') && !empty($connection['multidownload']['enabled']) && !$onlyCheck;

        switch ($useMulti) {
            case true:
                static::multiple_download($download_files, $onlyCheck, $checkedMirror);
                break;
            default:
                static::single_download($download_files, $onlyCheck, $checkedMirror);
                break;
        }
    }

    /**
     * @param $matches
     * @return array
     */
    static protected function parse_update_file($matches)
    {
        Log::write_log(Language::t('log.running', __METHOD__), Log::LEVEL_TRACE, static::$version);
        $new_content = '';
        $new_files = array();
        $total_size = 0;

        foreach ($matches as $container) {

            $parsed_container = parse_ini_string(
                preg_replace(
                    "/version=(.*?)\n/i",
                    "version=\"\${1}\"\n",
                    str_replace(
                        "\r\n",
                        "\n",
                        $container
                    )
                ),
                true);
            $output = array_shift($parsed_container);

            if (empty($output['file']) or empty($output['size'])) continue;

            // Apply platform filtering
            if (static::matches_platform($output)) {
                $new_files[] = $output;
                $total_size += $output['size'];
                $new_content .= $container;

                // Collect discovered platforms for this version
                if (isset($output['platform']) && !in_array($output['platform'], static::$platforms_found)) {
                    static::$platforms_found[] = $output['platform'];
                }
            }
        }

        return array($new_files, $total_size, $new_content);
    }

    /**
     * @param $download_files
     * @return bool True when all requested files are present and match expected size
     * @throws ToolsException
     */
    static protected function download_files($download_files)
    {
        Log::write_log(Language::t('log.running', __METHOD__), Log::LEVEL_TRACE, static::$version);
        shuffle($download_files);
        Log::write_log(Language::t('mirror.downloading_files', count($download_files)), Log::LEVEL_INFO, static::$version);

        static::download($download_files);

        $scriptConfig = Config::get('script');
        $web_dir = $scriptConfig['web_dir'] ?? SELF . 'www';
        $allOk = true;

        foreach ($download_files as $file) {
            $path = Tools::ds($web_dir, $file['file']);
            $expectedSize = isset($file['size']) ? intval($file['size']) : null;

            if (!file_exists($path)) {
                $allOk = false;
                Log::write_log(
                    sprintf('Download missing: %s', $file['file']),
                    Log::LEVEL_WARNING,
                    static::$version
                );
                continue;
            }

            if ($expectedSize !== null) {
                clearstatcache(true, $path);
                $actual = filesize($path);
                if ($actual !== $expectedSize) {
                    $allOk = false;
                    @unlink($path);
                    Log::write_log(
                        sprintf('Download size mismatch for %s (%s of %s bytes)', $file['file'], $actual, $expectedSize),
                        Log::LEVEL_WARNING,
                        static::$version
                    );
                }
            }
        }

        return $allOk;
    }

    /**
     * @param $version
     * @param $dir
     */
    static public function init($version, $dir)
    {
        Log::write_log(Language::t('log.running', __METHOD__), Log::LEVEL_TRACE, $version);
        register_shutdown_function(array('Mirror', 'destruct'));
        static::$total_downloads = 0;
        static::$version = $version;
        static::$name = $dir['name'];
        static::$updated = false;
        static::$ESET = Config::get('eset');
        $scriptConfig = Config::get('script');
        $webDir = $scriptConfig['web_dir'] ?? SELF . 'www';

        // Initialize platforms from config using VersionConfig
        static::$platforms = VersionConfig::get_version_platforms($version);

        // Initialize channels from config using VersionConfig
        static::$channels = VersionConfig::get_version_channels($version);

        // Initialize discovered platforms array
        static::$platforms_found = array();

        // Set update variants from directory config
        static::$update_variants = array();
        static::$primary_variant = null;
        static::$source_update_file = null;
        static::$tmp_update_file = null;
        static::$local_update_file = null;
        static::$primary_channel = null;
        static::$channel = null;

        // Check if we are using the new channel structure
        if (isset($dir['channels'])) {
            foreach ($dir['channels'] as $channelName => $variants) {
                // Check if channel is enabled
                if (is_array(static::$channels) && !in_array($channelName, static::$channels)) {
                    continue;
                }

                foreach (array('file', 'dll') as $variantType) {
                    if (empty($variants[$variantType])) {
                        continue;
                    }

                    $sourcePath = $variants[$variantType];
                    // Construct local path: eset_upd/{ver}/{channel}/[dll/]update.ver
                    $verFolder = $version;
                    if (preg_match('#eset_upd/([^/]+)#', $sourcePath, $m) && !empty($m[1]) && strtolower($m[1]) !== 'update.ver') {
                        $verFolder = $m[1];
                    }

                    $localSuffix = ($variantType === 'dll' ? Tools::ds('dll', 'update.ver') : 'update.ver');
                    $localPathRel = Tools::ds('eset_upd', $verFolder, $channelName, $localSuffix);

                    $tmpPath = Tools::ds(TMP_PATH, $localPathRel);
                    $localPath = Tools::ds($webDir, $localPathRel);

                    $variantKey = $channelName . ':' . $variantType;

                    static::$update_variants[$variantKey] = array(
                        'source' => $sourcePath,
                        'fixed' => $localPathRel,
                        'tmp' => $tmpPath,
                        'local' => $localPath
                    );

                    @mkdir(dirname($tmpPath), 0755, true);
                    @mkdir(dirname($localPath), 0755, true);
                }
            }
        } else {
            // Fallback for old structure (if any)
            foreach (array('file', 'dll') as $variantKey) {
                if (empty($dir[$variantKey])) {
                    continue;
                }

                if (preg_match('#^eset_upd/update\.ver$#i', $dir[$variantKey])) {
                    $fixed_update_file = Tools::ds('eset_upd', $version, 'update.ver');
                } else {
                    $fixed_update_file = preg_replace('/eset_upd\/update\.ver/is', Tools::ds('eset_upd', 'v3', 'update.ver'), $dir[$variantKey]);
                }
                $tmpPath = Tools::ds(TMP_PATH, $fixed_update_file);
                $localPath = Tools::ds($webDir, $fixed_update_file);

                static::$update_variants[$variantKey] = array(
                    'source' => $dir[$variantKey],
                    'fixed' => $fixed_update_file,
                    'tmp' => $tmpPath,
                    'local' => $localPath
                );

                @mkdir(dirname($tmpPath), 0755, true);
                @mkdir(dirname($localPath), 0755, true);
            }
        }

        if (isset(static::$update_variants['production:file'])) {
            static::$primary_variant = 'production:file';
        } elseif (isset(static::$update_variants['file'])) {
            static::$primary_variant = 'file';
        } elseif (!empty(static::$update_variants)) {
            $variantKeys = array_keys(static::$update_variants);
            static::$primary_variant = reset($variantKeys);
        }

        if (static::$primary_variant) {
            $primary = static::$update_variants[static::$primary_variant];
            static::$source_update_file = $primary['source'];
            static::$tmp_update_file = $primary['tmp'];
            static::$local_update_file = $primary['local'];
            static::$primary_channel = static::extract_channel_from_variant(static::$primary_variant);
            static::$channel = static::$primary_channel;
        }

        Log::write_log(Language::t('mirror.initialized', static::$name), Log::LEVEL_TRACE, static::$version);
    }

    /**
     * @param $key
     */
    static public function set_key($key)
    {
        Log::write_log(Language::t('log.running', __METHOD__), Log::LEVEL_TRACE, static::$version);
        static::$key = $key;
    }

    /**
     *
     */
    static public function destruct()
    {
        Log::write_log(Language::t('log.running', __METHOD__), Log::LEVEL_TRACE, static::$version);

        static::$total_downloads = 0;
        static::$version = null;
        static::$source_update_file = null;
        static::$tmp_update_file = null;
        static::$local_update_file = null;
        static::$update_variants = array();
        static::$primary_variant = null;
        static::$primary_channel = null;
        static::$channel = null;
        static::$name = null;
        static::$mirrors = array();
        static::$key = array();
        static::$updated = false;
        static::$unAuthorized = false;
        static::$platforms = array();
        static::$channels = array();
        static::$platforms_found = array();
    }


    /**
     * @param $folder
     * @return int
     */
    static public function del_folders($folder)
    {
        Log::write_log(Language::t('log.running', __METHOD__), Log::LEVEL_TRACE, static::$version);
        $del_folders_count = 0;
        $directory = new RecursiveDirectoryIterator($folder);

        foreach ($directory as $fileObject) {
            $test_folder = $fileObject->getPathname();

            if (count(glob(Tools::ds($test_folder, '*'))) === 0) {
                @rmdir($test_folder);
                $del_folders_count++;
            }
        }

        if (count(glob(Tools::ds($folder, '*'))) === 0) {
            @rmdir($folder);
            $del_folders_count++;
        }

        return $del_folders_count;
    }

    /**
     * @param $file
     * @param $needed_files
     * @return int
     */
    static public function del_files($file, $needed_files)
    {
        Log::write_log(Language::t('log.running', __METHOD__), Log::LEVEL_TRACE, static::$version);
        $del_files_count = 0;
        $directory = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($file), RecursiveIteratorIterator::SELF_FIRST);

        foreach ($directory as $fileObject) {
            if (!$fileObject->isDir()) {
                $test_file = $fileObject->getPathname();

                if (!in_array($test_file, $needed_files)) {
                    @unlink($test_file);
                    $del_files_count++;
                }
            }
        }

        return $del_files_count;
    }

    /**
     * @param $dir
     * @param $new_files
     * @return array
     */
    static public function create_links($dir, $new_files)
    {
        Log::write_log(Language::t('log.running', __METHOD__), Log::LEVEL_TRACE, static::$version);
        $old_files = [];
        $needed_files = [];
        $download_files = [];
        $preg_pattern = '/([v|ep]+)(\d+)/is';
        $iterator = new RegexIterator(
            new RecursiveIteratorIterator(
                new RecursiveRegexIterator(
                    new RecursiveDirectoryIterator($dir),
                    '/[v|ep]+\d+[-]+/i'
                )
            ),
            '/\.nup$/i'
        );

        $version = false;
        preg_match($preg_pattern, static::$version,$version);
        $scriptConfig = Config::get('script');
        $linkMethod = $scriptConfig['link_method'] ?? 'copy';
        /** @var RegexIterator $file */
        foreach ($iterator as $file) {
            $pathVersion = false;
            $filepath = $file->getPathname();
            if (is_link($filepath)) continue;
            preg_match($preg_pattern, $filepath, $pathVersion);
            if ($version && is_array($pathVersion) && /*$version[1] == $pathVersion[1] &&*/ (int)$version[2] > (int)$pathVersion[2]) $old_files[] = $filepath;
        }

        foreach ($new_files as $array) {
            $path = Tools::ds($dir, $array['file']);
            $needed_files[] = $path;

            if (file_exists($path) && !Tools::compare_files(@stat($path), $array)) unlink($path);

            if (!file_exists($path)) {
                $results = preg_grep('/' . basename($array['file']) . '$/', $old_files);

                if (!empty($results)) {
                    foreach ($results as $result) {
                        if (Tools::compare_files(@stat($result), $array)) {
                            $res = dirname($path);

                            if (!file_exists($res)) mkdir($res, 0755, true);

                            switch ($linkMethod) {
                                case 'hardlink':
                                    link($result, $path);
                                    Log::write_log(Language::t('mirror.created_hardlink', basename($array['file'])), Log::LEVEL_INFO, static::$version);
                                    break;
                                case 'symlink':
                                    symlink($result, $path);
                                    Log::write_log(Language::t('mirror.created_symlink', basename($array['file'])), Log::LEVEL_INFO, static::$version);
                                    break;
                                case 'copy':
                                default:
                                    copy($result, $path);
                                    Log::write_log(Language::t('mirror.copied_file', basename($array['file'])), Log::LEVEL_INFO, static::$version);
                                    break;
                            }

                            static::$updated = true;

                            break;
                        }
                    }
                    if (!file_exists($path) && !in_array($array['file'], array_column($download_files, 'file'), true)) {
                        $download_files[] = $array;
                    }
                } else $download_files[] = $array;
            }
        }
        return [$download_files, $needed_files];
    }

    /**
     * @param $file
     * @return int|null
     */
    static public function get_DB_version($file)
    {
        Log::write_log(Language::t('log.running', __METHOD__), Log::LEVEL_TRACE, static::$version);

        if (!file_exists($file)) return null;

        $content = file_get_contents($file);
        $upd = Parser::parse_line($content, "versionid");
        $max = 0;

        if (isset($upd) && count($upd) > 0)
            foreach ($upd as $key) $max = $max < intval($key) ? $key : $max;

        return $max;
    }

    /**
     * Build mirror URL respecting provided scheme (http/https) and avoiding duplicate slashes
     * @param string $mirrorHost
     * @param string $path
     * @return string
     */
    static private function buildMirrorUrl($mirrorHost, $path)
    {
        $base = preg_match('#^https?://#i', $mirrorHost)
            ? rtrim($mirrorHost, '/')
            : 'https://' . ltrim($mirrorHost, '/');

        return $base . '/' . ltrim($path, '/');
    }

    /**
     * Check if file matches current platform filter
     * @param array $file_info
     * @return bool
     */
    static public function matches_platform($file_info)
    {
        if (!isset($file_info['platform'])) {
            return true; // If platform is not specified, include the file
        }

        // If platforms is true, null, or empty array, include all platforms
        if (static::$platforms === true || static::$platforms === null || empty(static::$platforms)) {
            return true;
        }

        // If platforms is not an array, include all platforms
        if (!is_array(static::$platforms)) {
            return true;
        }

        return in_array($file_info['platform'], static::$platforms);
    }



    /**
     * Filter files based on platform, file type and other criteria
     * @param array $files
     * @return array
     */
    static public function filter_files($files)
    {
        Log::write_log(Language::t('log.running', __METHOD__), Log::LEVEL_TRACE, static::$version);

        $filtered_files = array();

        foreach ($files as $file) {
            if (static::matches_platform($file)) {
                $filtered_files[] = $file;
            }
        }

        Log::write_log(Language::t('mirror.filtered_files', count($filtered_files), count($files)), Log::LEVEL_DEBUG, static::$version);

        return $filtered_files;
    }

}

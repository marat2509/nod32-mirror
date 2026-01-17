<?php

/**
 * Class Nod32ms
 */
class Nod32ms
{
    /**
     * @var
     */
    static private $start_time;

    /**
     * @var
     */
    static private $key_valid_file;

    /**
     * @var
     */
    static private $key_invalid_file;

    static private $foundValidKey = false;

    /**
     * @var array
     */
    static private $platforms_found = array();

    /**
     * Nod32ms constructor.
     * @throws Exception
     * @throws ToolsException
     */
    public function __construct()
    {
        global $VERSION;
        Log::write_log(Language::t("Running %s", __METHOD__), 5, null);
        static::$start_time = time();
        static::$key_valid_file = Tools::ds(Config::get('log')['dir'], KEY_FILE_VALID);
        static::$key_invalid_file = Tools::ds(Config::get('log')['dir'], KEY_FILE_INVALID);
        Log::write_log(Language::t("Run script %s", $VERSION), 0);
        $this->run_script();
    }

    /**
     */
    public function __destruct()
    {
        Log::write_log(Language::t("Running %s", __METHOD__), 5, null);
        Log::write_log(Language::t("Total working time: %s", Tools::secondsToHumanReadable(time() - static::$start_time)), 0);
        Log::destruct();
        Log::write_log(Language::t("Stopping script."), 0);
    }

    /**
     * @param $version
     * @param bool $return_time_stamp
     * @return mixed|null
     */
    private function check_time_stamp($version, $return_time_stamp = false)
    {
        Log::write_log(Language::t("Running %s", __METHOD__), 5, $version);
        $fn = Tools::ds(Config::get('log')['dir'], SUCCESSFUL_TIMESTAMP);
        $timestamps = array();

        if (file_exists($fn)) {
            $handle = file_get_contents($fn);
            $content = Parser::parse_line($handle, false, "/(.+:.+)\n/");

            if (isset($content) && count($content)) {
                foreach ($content as $value) {
                    $result = explode(":", $value);
                    $timestamps[$result[0]] = $result[1];
                }
            }

            if (isset($timestamps[$version])) {
                if ($return_time_stamp) {
                    return $timestamps[$version];
                }
            }
        }
        return null;
    }

    /**
     * @param $size
     */
    private function set_database_size($size)
    {
        Log::write_log(Language::t("Running %s", __METHOD__), 5, Mirror::$version);
        $fn = Tools::ds(Config::get('log')['dir'], DATABASES_SIZE);
        $sizes = [];

        if (file_exists($fn)) {
            $handle = file_get_contents($fn);
            $content = Parser::parse_line($handle, false, "/(.+:.+)\n/");

            if (isset($content) && count($content)) {
                foreach ($content as $value) {
                    $result = explode(":", $value);
                    $sizes[$result[0]] = $result[1];
                }
            }
        }

        $sizes[Mirror::$version] = $size;
        @unlink($fn);

        foreach ($sizes as $key => $name)
            Log::write_to_file($fn, "$key:$name\r\n");
    }

    /**
     * @return array|null
     */
    private function get_databases_size()
    {
        Log::write_log(Language::t("Running %s", __METHOD__), 5, Mirror::$version);
        $fn = Tools::ds(Config::get('log')['dir'], DATABASES_SIZE);
        $sizes = [];

        if (file_exists($fn)) {
            $handle = file_get_contents($fn);
            $content = Parser::parse_line($handle, false, "/(.+:.+)\n/");

            if (isset($content) && count($content)) {
                foreach ($content as $value) {
                    $result = explode(":", $value);
                    $sizes[$result[0]] = $result[1];
                }
            }
        }

        return (!empty($sizes)) ? $sizes : null;
    }

    /**
     * @param string $directory
     * @return array
     */
    private function get_all_patterns($directory = PATTERN)
    {
        Log::write_log(Language::t("Running %s", __METHOD__), 5, null);

        $ar_patterns = [];

        $iterator = new RecursiveDirectoryIterator($directory);
        $recursiveIterator = new RecursiveIteratorIterator($iterator);

        foreach ($recursiveIterator as $file) {
            if ($file->isFile()) {
                $ar_patterns[] = $file->getPathname();
            }
        }

        return $ar_patterns;
    }


    /**
     * @param $key
     * @return bool
     */
    private function validate_key($key)
    {
        Log::write_log(Language::t("Running %s", __METHOD__), 5, Mirror::$version);
        $result = explode(":", $key);

        if ($this->key_exists_in_file($result[0], $result[1], static::$key_invalid_file)) return false;
        Log::write_log(Language::t("Validating key [%s:%s] for version %s", $result[0], $result[1], Mirror::$version), 4, Mirror::$version);

        Mirror::set_key(array($result[0], $result[1]));
        $ret = Mirror::test_key();

        if (is_bool($ret)) {
            if ($ret) {
                static::$foundValidKey = true;
                $this->write_key($result[0], $result[1]);
                return true;
            } else {
                $this->delete_key($result[0], $result[1]);
            }
        } else {
            Log::write_log(Language::t("Unhandled exception [%s]", $ret), 4);
        }
        return false;
    }

    /**
     * @return array|null
     */
    private function read_keys()
    {
        Log::write_log(Language::t("Running %s", __METHOD__), 5, Mirror::$version);

        if (!file_exists(static::$key_valid_file)) {
            $h = fopen(static::$key_valid_file, 'x');
            fclose($h);
        }

        $keys = Parser::parse_keys(static::$key_valid_file);

        if (!isset($keys) || !count($keys)) {
            Log::write_log(Language::t("Keys file is empty!"), 4, Mirror::$version);
        }

        foreach ($keys as $value) {
            if ($this->validate_key($value)) return explode(":", $value);
        }

        Log::write_log(Language::t("No working keys were found!"), 4, Mirror::$version);
        return null;
    }

    /**
     * @param string $login
     * @param string $password
     */
    private function write_key($login, $password)
    {
        Log::write_log(Language::t("Running %s", __METHOD__), 5, Mirror::$version);
        Log::write_log(Language::t("Found valid key [%s:%s]", $login, $password), 4, Mirror::$version);
        ($this->key_exists_in_file($login, $password, static::$key_valid_file) == false) ?
            Log::write_to_file(static::$key_valid_file, "$login:$password:" . Mirror::$version . "\r\n") :
            Log::write_log(Language::t("Key [%s:%s:%s] already exists", $login, $password, Mirror::$version), 4, Mirror::$version);
    }

    /**
     * @param string $login
     * @param string $password
     */
    private function delete_key($login, $password)
    {
        Log::write_log(Language::t("Running %s", __METHOD__), 5, Mirror::$version);
        Log::write_log(Language::t("Invalid key [%s:%s]", $login, $password), 4, Mirror::$version);
        //$log_dir = Config::get('LOG')['dir'];

        ($this->key_exists_in_file($login, $password, static::$key_invalid_file) == false) ?
            Log::write_to_file(static::$key_invalid_file, "$login:$password:" . Mirror::$version . "\r\n") :
            Log::write_log(Language::t("Key [%s:%s] already exists", $login, $password), 4, Mirror::$version);

        $findConfig = Config::get('find');
        if (!empty($findConfig['remove_invalid_keys']))
            Parser::delete_parse_line_in_file($login . ':' . $password . ':' . Mirror::$version, static::$key_valid_file);
    }

    /**
     * @param string $login
     * @param string $password
     * @param $file
     * @return bool
     */
    private function key_exists_in_file($login, $password, $file)
    {
        Log::write_log(Language::t("Running %s", __METHOD__), 5, Mirror::$version);
        $keys = Parser::parse_keys($file);

        if (isset($keys) && count($keys)) {
            foreach ($keys as $value) {
                $result = explode(":", $value);

                if ($result[0] == $login && $result[1] == $password && $result[2] == Mirror::$version)
                    return true;
            }
        }

        return false;
    }

    /**
     * @param string $search
     * @return string
     */
    private function strip_tags_and_css($search)
    {
        Log::write_log(Language::t("Running %s", __METHOD__), 5, null);
        $document = array(
            "'<script[^>]*?>.*?<\/script>'si",
            "'<[\/\!]*?[^<>]*?>'si",
            "'([\r\n])[\s]+'",
            "'&(quot|#34);'i",
            "'&(amp|#38);'i",
            "'&(lt|#60);'i",
            "'&(gt|#62);'i",
            "'&(nbsp|#160);'i",
            "'&(iexcl|#161);'i",
            "'&(cent|#162);'i",
            "'&(pound|#163);'i",
            "'&(copy|#169);'i",
            "'&#(\d+);'e"
        );
        $replace = array(
            "",
            "",
            "\\1",
            "\"",
            "&",
            "<",
            ">",
            " ",
            chr(161),
            chr(162),
            chr(163),
            chr(169),
            "chr(\\1)"
        );
        return trim(preg_replace($document, $replace, $search));
    }

    /**
     * @param string $this_link
     * @param integer $level
     * @param array $pattern
     * @return bool
     */
    private function parse_www_page($this_link, $level, $pattern)
    {
        Log::write_log(Language::t("Running %s", __METHOD__), 5, Mirror::$version);
        static::$foundValidKey = false;
        $search = Tools::download_file(
            ([
                    CURLOPT_URL => $this_link,
                    CURLOPT_RETURNTRANSFER => 1,
                    CURLOPT_NOBODY => 0,
                    CURLOPT_USERAGENT => "Mozilla/5.0 (Windows; U; Windows NT 6.1; rv:2.2) Gecko/20110201"
                ] + Config::getConnectionInfo()),
            $headers
        );

        if ($search === false) {
            Log::write_log(Language::t("Link wasn't found [%s]", $this_link), 4, Mirror::$version);
            return false;
        }

        Log::write_log(Language::t("Link was found [%s]", $this_link), 4, Mirror::$version);
        $login = array();
        $password = array();

        $scriptConfig = Config::get('script');
        if (!empty($scriptConfig['debug_html'])) {
            $path_info = pathinfo($this_link);
            $dir = Tools::ds(Config::get('log')['dir'], DEBUG_DIR, $path_info['basename']);
            @mkdir($dir, 0755, true);
            $filename = Tools::ds($dir, $path_info['filename'] . ".log");
            file_put_contents($filename, $this->strip_tags_and_css($search));
        }

        foreach ($pattern as $key)
            Parser::parse_template($search, $key, $login, $password);
        $logins = count($login);

        if ($logins > 0) {
            Log::write_log(Language::t("Found keys: %s", $logins), 4, Mirror::$version);

            for ($b = 0; $b < $logins; $b++) {
                if (preg_match("/script|googleuser/i", $password[$b]) and
                    $this->key_exists_in_file($login[$b], $password[$b], static::$key_valid_file)
                )
                    continue;

                if ($this->validate_key($login[$b] . ':' . $password[$b])) {
                    static::$foundValidKey = true;
                    return true;
                }
            }
        }

        if ($level > 1) {
            $links = array();
            preg_match_all('/href *= *"([^\s"]+)/', $search, $results);

            foreach ($results[1] as $result) {
                $result = str_replace('webcache.googleusercontent.com/search?q=cache:', '', $result);

                if (!preg_match("/youtube.com|ocialcomments.org/", $result)) {
                    preg_match('/https?:\/\/(?(?!\&amp).)*/', $result, $res);

                    if (!empty($res[0]))
                        $links[] = $res[0];
                }
            }
            Log::write_log(Language::t("Found links: %s", count($links)), 4, Mirror::$version);

            foreach ($links as $url) {
                $this->parse_www_page($url, $level - 1, $pattern);

                if (static::$foundValidKey)
                    return true;

            }
        }

        return false;
    }

    /**
     * @return null
     */
    private function find_keys()
    {
        Log::write_log(Language::t("Running %s", __METHOD__), 5, Mirror::$version);
        $FIND = Config::get('find');

        $attempts = 0;
        $maxAttempts = isset($FIND['number_attempts']) ? intval($FIND['number_attempts']) : 0;
        if ($maxAttempts <= 0) {
            $maxAttempts = 1;
        }

        if (empty($FIND['auto']))
            return null;

        if (empty($FIND['system'])) {
            $patterns = $this->get_all_patterns();
            shuffle($patterns);
        } else {
            $patterns = [PATTERN . $FIND['system'] . '.pattern'];
        }

        while ($elem = array_shift($patterns)) {
            if ($attempts >= $maxAttempts) {
                break;
            }
            Log::write_log(Language::t("Begining search at %s", str_replace(realpath(PATTERN) . DIRECTORY_SEPARATOR, '', $elem)), 4, Mirror::$version);
            $find = @file_get_contents($elem);

            if (!$find) {
                Log::write_log(Language::t("File %s doesn't exist!", str_replace(realpath(PATTERN) . DIRECTORY_SEPARATOR, '', $elem)), 4, Mirror::$version);
                continue;
            }

            $link = Parser::parse_line($find, "link");
            $pageindex = Parser::parse_line($find, "pageindex");
            $pattern = Parser::parse_line($find, "pattern");
            $page_qty = Parser::parse_line($find, "page_qty");
            $recursion_level = Parser::parse_line($find, "recursion_level");

            if (empty($link)) {
                Log::write_log(Language::t("[link] doesn't set up in %s file!", $elem), 4, Mirror::$version);
                continue;
            }

            if (empty($pageindex)) $pageindex[] = $FIND['pageindex'];

            if (empty($pattern)) $pattern[] = $FIND['pattern'];

            if (empty($page_qty)) $page_qty[] = $FIND['page_qty'];

            if (empty($recursion_level)) $recursion_level[] = $FIND['recursion_level'];

            $queries = Tools::parse_comma_list($FIND['query'], ', ');

            foreach ($queries as $query) {
                $pages = substr_count($link[0], "#PAGE#") ? $page_qty[0] : 1;

                for ($i = 0; $i < $pages; $i++) {
                    if ($attempts >= $maxAttempts) {
                        break 3;
                    }

                    $this_link = str_replace("#QUERY#", str_replace(" ", "+", trim($query)), $link[0]);
                    $this_link = str_replace("#PAGE#", ($i * $pageindex[0]), $this_link);

                    $attempts++;

                    if ($this->parse_www_page($this_link, $recursion_level[0], $pattern) == true) break(3);

                    // simple linear backoff to avoid hammering sources
                    usleep(min($attempts, 5) * 200000);
                }
            }
        }

        return null;
    }

    /**
     * Build path to the local update.ver file for mirror output.
     * With channel support.
     */
    private function get_update_file_path($dir, $web_dir, $channel = null, $type = 'file')
    {
        $source_file = null;

        if (isset($dir['channels'])) {
            // New structure with channels
            $targetChannel = $channel ?: 'production';

            // Try to find in specific channel
            if (isset($dir['channels'][$targetChannel][$type]) && $dir['channels'][$targetChannel][$type] !== false) {
                $source_file = $dir['channels'][$targetChannel][$type];
            }
            // Fallback to first available channel if not found and no specific channel requested
            elseif (!$channel) {
                foreach ($dir['channels'] as $chName => $chData) {
                    if (isset($chData[$type]) && $chData[$type] !== false) {
                        $source_file = $chData[$type];
                        $targetChannel = $chName;
                        break;
                    }
                }
            }
        } else {
            // Old structure fallback
            if ($type === 'file' && isset($dir['file']) && $dir['file'] !== false) {
                $source_file = $dir['file'];
            } elseif ($type === 'dll' && isset($dir['dll']) && $dir['dll'] !== false) {
                $source_file = $dir['dll'];
            }
            // For old structure, we treat it as default/legacy path, but we still need to fix it
        }

        if (!$source_file) {
            return null;
        }

        // We need to construct the LOCAL path based on how Mirror class does it.
        // Mirror class logic:
        // $localSuffix = ($variantType === 'dll' ? Tools::ds('dll', 'update.ver') : 'update.ver');
        // $localPathRel = Tools::ds('eset_upd', $verFolder, $channelName, $localSuffix);

        // Extract version folder from source path
        $verFolder = 'v3';
        if (preg_match('#eset_upd/([^/]+)#', $source_file, $m)) {
            $verFolder = $m[1];
        }

        if (isset($dir['channels'])) {
             // If we have channels, we know the structure
             $targetChannel = $channel ?: $targetChannel; // Use identified channel
             $localSuffix = ($type === 'dll' ? Tools::ds('dll', 'update.ver') : 'update.ver');
             $fixed_file = Tools::ds('eset_upd', $verFolder, $targetChannel, $localSuffix);
        } else {
             // Legacy fallback
             $fixed_file = preg_replace('/eset_upd\/update\.ver/is', Tools::ds('eset_upd', 'v3', 'update.ver'), $source_file);
        }

        return Tools::ds($web_dir, $fixed_file);
    }

    /**
     * Normalize absolute update.ver path to a web-facing relative path.
     */
    private function get_public_update_path($fullPath, $web_dir)
    {
        if (!$fullPath) {
            return false;
        }

        $normalizedBase = rtrim(str_replace(['/', '\\'], DS, $web_dir), DS);
        $normalizedPath = str_replace(['/', '\\'], DS, $fullPath);

        if ($normalizedBase !== '' && strpos($normalizedPath, $normalizedBase) === 0) {
            $relative = ltrim(substr($normalizedPath, strlen($normalizedBase)), DS);
        } else {
            $relative = $normalizedPath;
        }

        return str_replace(DS, '/', $relative);
    }

    /**
     * Helper to find best available update path for metadata extraction
     */
    private function find_best_update_path($dir, $web_dir) {
        // Priority list
        $priorities = [
            ['channel' => 'production', 'type' => 'file'],
            ['channel' => 'production', 'type' => 'dll'],
        ];

        // Add other channels as fallback
        if (isset($dir['channels'])) {
            foreach ($dir['channels'] as $chName => $chData) {
                if ($chName === 'production') continue;
                $priorities[] = ['channel' => $chName, 'type' => 'file'];
                $priorities[] = ['channel' => $chName, 'type' => 'dll'];
            }
        } else {
            // Legacy
            $priorities[] = ['channel' => null, 'type' => 'file'];
            $priorities[] = ['channel' => null, 'type' => 'dll'];
        }

        foreach ($priorities as $p) {
            $path = $this->get_update_file_path($dir, $web_dir, $p['channel'], $p['type']);
            if ($path && file_exists($path)) {
                return $path;
            }
        }

        // If no file exists physically, try to return at least path string for production file
        return $this->get_update_file_path($dir, $web_dir, 'production', 'file');
    }

    /**
     * Parse update.ver file and return actually downloaded platforms.
     */
    private function parse_platforms_from_update_file($update_file)
    {
        if (!$update_file || !file_exists($update_file)) {
            return array();
        }

        $content = @file_get_contents($update_file);

        if ($content === false || !preg_match_all('#\[[^\]]+\][^\[]+#', $content, $matches)) {
            return array();
        }

        $found = array();

        foreach ($matches[0] as $container) {
            $parsed_ini = @parse_ini_string(
                preg_replace('/version=(.*?)\n/i', 'version="${1}"' . "\n", str_replace("\r\n", "\n", $container)),
                true
            );

            if (!$parsed_ini || !is_array($parsed_ini)) {
                continue;
            }

            $data = array_shift($parsed_ini);

            if (!empty($data['platform'])) {
                $found[] = trim($data['platform']);
            }
        }

        if (!empty($found)) {
            $found = array_values(array_unique($found));
            natcasesort($found);
            $found = array_values($found);
        }

        return $found;
    }

    /**
     * Return cached or parsed platform list for the provided version.
     */
    private function get_platforms_for_version($version, $dir, $web_dir, $update_file = null)
    {
        // Cache key needs to be unique per update file now, but let's keep simple cache for main version
        if (!empty(static::$platforms_found[$version])) {
            return static::$platforms_found[$version];
        }

        $update_path = $update_file ?: $this->find_best_update_path($dir, $web_dir);
        $platforms = $this->parse_platforms_from_update_file($update_path);

        if (!empty($platforms)) {
            static::$platforms_found[$version] = $platforms;
        }

        return $platforms;
    }

    private function format_size_decimal($bytes, $decimal_places = 2)
    {
        if ($bytes === null) {
            return null;
        }

        $units = [
            Language::t("Bytes"),
            Language::t("KBytes"),
            Language::t("MBytes"),
            Language::t("GBytes"),
            Language::t("TBytes"),
            Language::t("PBytes"),
            Language::t("EBytes")
        ];
        $value = (float) $bytes;
        $index = 0;

        while ($value >= 1000 && $index < count($units) - 1) {
            $value /= 1000;
            $index++;
        }

        if ($decimal_places === false) {
            $formatted = number_format($value, 6, '.', '');
        } else {
            $formatted = number_format($value, $decimal_places, '.', '');
        }

        $formatted = rtrim(rtrim($formatted, '0'), '.');

        if ($formatted === '') {
            $formatted = '0';
        }

        return $formatted . ' ' . $units[$index];
    }

    /**
     * Build unified metadata for HTML/JSON generation
     * @return array
     */
    private function build_metadata()
    {
        global $DIRECTORIES;

        $scriptConfig = Config::get('script');
        $web_dir = $scriptConfig['web_dir'] ?? SELF . 'www';
        $enabled_versions = VersionConfig::get_enabled_versions();
        $total_sizes = $this->get_databases_size();

        if (!is_array($total_sizes)) {
            $total_sizes = [];
        } else {
            $total_sizes = array_map('intval', $total_sizes);
        }

        $versions = [];
        $latest_update = null;

        foreach ($enabled_versions as $version) {
            if (!isset($DIRECTORIES[$version])) {
                continue;
            }

            $dir = $DIRECTORIES[$version];

            $channelsInfo = [];
            if (isset($dir['channels'])) {
                foreach ($dir['channels'] as $channelName => $channelData) {
                    $filePath = (isset($channelData['file']) && $channelData['file'] !== false)
                        ? $this->get_update_file_path($dir, $web_dir, $channelName, 'file')
                        : null;
                    $dllPath = (isset($channelData['dll']) && $channelData['dll'] !== false)
                        ? $this->get_update_file_path($dir, $web_dir, $channelName, 'dll')
                        : null;

                    $channelUpdatePath = $filePath ?: $dllPath;
                    $channelDbVersion = null;

                    if ($channelUpdatePath) {
                        $channelDbVersion = Mirror::get_DB_version($channelUpdatePath);
                        if ($channelDbVersion !== null) $channelDbVersion = (int) $channelDbVersion;
                    }

                    $channelsInfo[$channelName] = [
                        'database_version' => $channelDbVersion,
                        'files' => [
                             'file' => $this->get_public_update_path($filePath, $web_dir),
                             'dll' => $this->get_public_update_path($dllPath, $web_dir),
                         ]
                    ];
                }
            } else {
                 $filePath = (isset($dir['file']) && $dir['file'] !== false)
                     ? $this->get_update_file_path($dir, $web_dir, null, 'file')
                     : null;
                 $dllPath = (isset($dir['dll']) && $dir['dll'] !== false)
                     ? $this->get_update_file_path($dir, $web_dir, null, 'dll')
                     : null;
                 $channelsInfo['default'] = [
                     'database_version' => null,
                     'files' => [
                        'file' => $this->get_public_update_path($filePath, $web_dir),
                        'dll' => $this->get_public_update_path($dllPath, $web_dir),
                     ]
                 ];
            }

            $update_path = $this->find_best_update_path($dir, $web_dir);
            $found_platforms = $this->get_platforms_for_version($version, $dir, $web_dir, $update_path);
            $display_platforms = $found_platforms;

            $version_platforms = VersionConfig::get_version_platforms($version);
            if ($version_platforms !== true && $version_platforms !== null) {
                $allowed_platforms = is_array($version_platforms) ? $version_platforms : Tools::parse_comma_list($version_platforms);

                if (!empty($allowed_platforms)) {
                    $filtered_platforms = array_values(array_intersect($found_platforms, $allowed_platforms));

                    if (!empty($filtered_platforms)) {
                        $display_platforms = $filtered_platforms;
                    }
                }
            }

            if (!empty($display_platforms)) {
                natcasesort($display_platforms);
                $display_platforms = array_values(array_unique($display_platforms));
            } else {
                $display_platforms = [];
            }

            $db_version = null;
            if ($update_path) {
                $db_version = Mirror::get_DB_version($update_path);
                if ($db_version !== null) {
                    $db_version = (int) $db_version;
                }
            }

            $size_bytes = isset($total_sizes[$version]) ? (int) $total_sizes[$version] : null;
            $timestamp = $this->check_time_stamp($version, true);
            $last_update = $timestamp ? date('c', $timestamp) : null;

            if ($timestamp && ($latest_update === null || $timestamp > $latest_update)) {
                $latest_update = $timestamp;
            }

            $versions[$version] = [
                'name' => $dir['name'],
                'platforms' => $display_platforms,
                'channels' => $channelsInfo,
                'database' => [
                    'version' => $db_version,
                    'size' => [
                        'bytes' => $size_bytes,
                        'pretty' => $size_bytes !== null ? $this->format_size_decimal($size_bytes) : null,
                    ],
                    'last_update' => $last_update,
                    'last_update_ts' => $timestamp,
                ]
            ];

            if (!empty($scriptConfig['show_login_password'])) {
                if (file_exists(static::$key_valid_file)) {
                    $keys = Parser::parse_keys(static::$key_valid_file);
                    $credentials = [];
                    foreach ($keys as $k) {
                        $key = explode(":", $k);
                        if (!isset($key[2]) || $key[2] == $version) {
                            $credentials[] = [
                                "login" => $key[0],
                                "password" => $key[1],
                                "version" => $key[2]
                            ];
                        }
                    }
                    $versions[$version]['credentials'] = $credentials;
                }
            }
        }

        $total_bytes = 0;

        if (!empty($total_sizes) && !empty($enabled_versions)) {
            $total_bytes = array_sum(array_intersect_key($total_sizes, array_flip($enabled_versions)));
        }

        return [
            'title' => Language::t("ESET NOD32 update server"),
            'last_update' => $latest_update ? date('c', $latest_update) : date('c', static::$start_time ?: time()),
            'last_update_ts' => $latest_update ?: (static::$start_time ?: time()),
            'total_size' => [
                'bytes' => $total_bytes,
                'pretty' => $this->format_size_decimal($total_bytes),
            ],
            'versions' => $versions,
        ];
    }

    private function generate_html()
    {
        Log::write_log(Language::t("Running %s", __METHOD__), 5, null);
        Log::write_log(Language::t("Generating html..."), 0);
        $scriptConfig = Config::get('script');
        $web_dir = $scriptConfig['web_dir'] ?? SELF . 'www';
        $generateOnlyTable = !empty($scriptConfig['generate_only_table']);
        $html_page = '';
        $metadata = $this->build_metadata();
        $versionsMeta = $metadata['versions'];

        if (!$generateOnlyTable) {
            $html_page .= '<!DOCTYPE HTML>';
            $html_page .= '<html>';
            $html_page .= '<head>';
            $html_page .= '<title>' . Language::t("ESET NOD32 update server") . '</title>';
            $html_page .= '<meta http-equiv="Content-Type" content="text/html; charset=' . ($scriptConfig['html_codepage'] ?? 'utf-8') . '">';
            $html_page .= '<style type="text/css">html,body{height:100%;margin:0;padding:0;width:100%}table#center{border:0;height:100%;width:100%}table td table td{text-align:center;vertical-align:middle;font-weight:bold;padding:10px 15px;border:0}table tr:nth-child(odd){background:#eee}table tr:nth-child(even){background:#fc0}</style>';
            $html_page .= '</head>';
            $html_page .= '<body>';
            $html_page .= '<table id="center">';
            $html_page .= '<tr>';
            $html_page .= '<td align="center">';
        }

        $html_page .= '<table>';
        $html_page .= '<tr><td colspan="5">' . Language::t("ESET NOD32 update server") . '</td></tr>';
        $html_page .= '<tr>';
        $html_page .= '<td>' . Language::t("Version") . '</td>';
        $html_page .= '<td>' . Language::t("Platforms") . '</td>';
        $html_page .= '<td>' . Language::t("Database version") . '</td>';
        $html_page .= '<td>' . Language::t("Database size") . '</td>';
        $html_page .= '<td>' . Language::t("Last update") . '</td>';
        $html_page .= '</tr>';

        global $DIRECTORIES;

        foreach ($versionsMeta as $ver => $info) {
            if (!isset($DIRECTORIES[$ver])) continue;

            $dir = $DIRECTORIES[$ver];

            $platforms_display = !empty($info['platforms']) ? implode(', ', $info['platforms']) : Language::t("n/a");
            $version = $info['database']['version'];
            $timestamp = $info['database']['last_update_ts'];
            $size_bytes = $info['database']['size']['bytes'];

            $html_page .= '<tr>';
            $html_page .= '<td>' . Language::t($dir['name']) . '</td>';
            $html_page .= '<td>' . $platforms_display . '</td>';
            $html_page .= '<td>' . $version . '</td>';
            $html_page .= '<td>' . ($size_bytes !== null ? Tools::bytesToSize1024($size_bytes) : Language::t("n/a")) . '</td>';
            $html_page .= '<td>' . ($timestamp ? date("Y-m-d, H:i:s", $timestamp) : Language::t("n/a")) . '</td>';
            $html_page .= '</tr>';
        }

        $html_page .= '<tr>';
        $html_page .= '<td colspan="2">' . Language::t("Last execution of the script") . '</td>';
        $html_page .= '<td colspan="3">' . (static::$start_time ? date("Y-m-d, H:i:s", static::$start_time) : Language::t("n/a")) . '</td>';
        $html_page .= '</tr>';

        if (!empty($scriptConfig['show_login_password'])) {
            if (file_exists(static::$key_valid_file)) {
                $keys = Parser::parse_keys(static::$key_valid_file);


                $html_page .= '<tr>';
                $html_page .= '<td>' . Language::t("Version") . '</td>';
                $html_page .= '<td>' . Language::t("Used login") . '</td>';
                $html_page .= '<td>' . Language::t("Used password") . '</td>';
                $html_page .= '</tr>';

                foreach ($keys as $k) {
                    $key = explode(":", $k);
                    $html_page .= '<tr>';
                    $html_page .= '<td>' . $key[2] . '</td>';
                    $html_page .= '<td>' . $key[0] . '</td>';
                    $html_page .= '<td>' . $key[1] . '</td>';
                    $html_page .= '</tr>';
                }
            }
        }
        $html_page .= '</table>';
        $html_page .= (!$generateOnlyTable) ? '</td></tr></table></body></html>' : '';

        $file = Tools::ds($web_dir, $scriptConfig['filename_html'] ?? 'index.html');

        if (!is_dir (dirname($file))) {
            @mkdir(dirname($file), 0755, true);
        }

        if (file_exists($file)) @unlink($file);

        Log::write_to_file($file, Tools::conv($html_page, $scriptConfig['html_codepage'] ?? 'utf-8'));
    }

    private function generate_json()
    {
        Log::write_log(Language::t("Running %s", __METHOD__), 5, null);
        $scriptConfig = Config::get('script');
        $web_dir = $scriptConfig['web_dir'] ?? SELF . 'www';
        $summary = $this->build_metadata();

        $json = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            Log::write_log('JSON encoding failed: ' . json_last_error_msg(), 1, null);
            return;
        }

        $file = Tools::ds($web_dir, $scriptConfig['filename_json'] ?? 'index.json');

        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0755, true);
        }

        file_put_contents($file, $json . PHP_EOL);
    }


    /**
     * @throws Exception
     * @throws ToolsException
     */
    private function run_script()
    {
        Log::write_log(Language::t("Running %s", __METHOD__), 5, null);
        global $DIRECTORIES;
        $total_size = array();
        $total_downloads = array();
        $average_speed = array();

        $stored_sizes = $this->get_databases_size();

        // Get enabled versions from new config structure
        $enabled_versions = VersionConfig::get_enabled_versions();

        if (!empty($stored_sizes) && !empty($enabled_versions)) {
            $stored_sizes = array_map('intval', $stored_sizes);
            $total_size = array_intersect_key($stored_sizes, array_flip($enabled_versions));
        }

        foreach ($enabled_versions as $version) {
            if (!isset($DIRECTORIES[$version])) {
                Log::write_log(Language::t("Version %s not found in directories configuration", $version), 1, $version);
                continue;
            }

            $dir = $DIRECTORIES[$version];

            Log::write_log(Language::t("Init Mirror for version %s in %s", $version, $dir['name']), 5, $version);
            Mirror::init($version, $dir);

            static::$foundValidKey = false;
            $this->read_keys();

            if (static::$foundValidKey == false) {
                $this->find_keys();

                if (static::$foundValidKey == false) {
                    Log::write_log(Language::t("The script has been stopped!"), 1, Mirror::$version);
                    continue;
                }
            }

            $old_version = Mirror::get_DB_version(Mirror::$local_update_file);

            if (!empty(Mirror::$mirrors)) {
                if (count(Mirror::$mirrors) > 1) {
                    $mirror = array_shift(Mirror::$mirrors);
                } else {
                    $mirror = Mirror::$mirrors[0];
                }

                if ($old_version && $this->compare_versions($old_version, $mirror['db_version'])) {
                    Log::informer(Language::t("Your version of database is relevant %s", $old_version), Mirror::$version, 2);
                } else {
                    list($size, $downloads, $speed) = Mirror::download_signature();
                    $this->set_database_size($size);

                    // Save discovered platforms for this version
                    if (!empty(Mirror::$platforms_found)) {
                        static::$platforms_found[Mirror::$version] = Mirror::$platforms_found;
                    }

                    if (!Mirror::$updated && $old_version != 0 && !$this->compare_versions($old_version, $mirror['db_version'])) {
                        Log::informer(Language::t("Your database has not been updated!"), Mirror::$version, 1);
                    } else {
                        $total_size[Mirror::$version] = $size;
                        $total_downloads[Mirror::$version] = $downloads;
                        if (!empty($speed)) {
                            $average_speed[Mirror::$version] = $speed;
                        }

                        if ($old_version && !$this->compare_versions($old_version, $mirror['db_version'])) {
                            Log::informer(Language::t("Your database was successfully updated from %s to %s", $old_version, $mirror['db_version']), Mirror::$version, 2);
                        } else {
                            Log::informer(Language::t("Your database was successfully updated to %s", $mirror['db_version']), Mirror::$version, 2);
                        }
                    }
                }
            } else {
                Log::write_log(Language::t("All mirrors is down!"), 1, Mirror::$version);
            }

            Mirror::destruct();
        }

        foreach (glob(Tools::ds(TMP_PATH, '*')) as $folder) {
            static::clear_tmp($folder);
            @rmdir($folder);
        }

        Log::write_log(Language::t("Total size for all databases: %s", Tools::bytesToSize1024(array_sum($total_size))), 3);

        if (array_sum($total_downloads) > 0)
            Log::write_log(Language::t("Total downloaded for all databases: %s", Tools::bytesToSize1024(array_sum($total_downloads))), 3);

        if (array_sum($average_speed) > 0)
            Log::write_log(Language::t("Average speed for all databases: %s/s", Tools::bytesToSize1024(array_sum($average_speed) / count($average_speed))), 3);

        $scriptConfig = Config::get('script');
        if (!empty($scriptConfig['generate_html'])) {
            $this->generate_html();
        }

        if (!empty($scriptConfig['generate_json'])) {
            $this->generate_json();
        }
    }

    private function clear_tmp($path)
    {
        try {
            $iterator = new DirectoryIterator($path);
            foreach ( $iterator as $fileinfo) {
                if($fileinfo->isDot())continue;
                if($fileinfo->isDir()){
                    if(static::clear_tmp($fileinfo->getPathname()))
                        @rmdir($fileinfo->getPathname());
                }
                if($fileinfo->isFile()){
                    @unlink($fileinfo->getPathname());
                }
            }
        } catch ( Exception $e ){
            // write log
            return false;
        }
        return true;
    }

    /**
     * @param $old_version
     * @param $new_version
     * @return bool
     */
    private function compare_versions($old_version, $new_version)
    {
        Log::write_log(Language::t("Running %s", __METHOD__), 5, Mirror::$version);
        Log::write_log(Language::t("Compare %s >= %s", $old_version, $new_version), 5, Mirror::$version);
        return (intval($old_version) >= intval($new_version));
    }
}

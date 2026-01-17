<?php

/**
 * Class Parser
 */
class Parser
{
    /**
     * @param $handle
     * @param $tag
     * @param bool $pattern
     * @return array
     */
    static public function parse_line($handle, $tag, $pattern = false)
    {
        Log::write_log(Language::t('log.running', __METHOD__), 5, Mirror::$version);
        $arr = [];

        if (preg_match_all(($pattern ? $pattern : "/$tag *=(.+)/"), $handle, $result, PREG_PATTERN_ORDER)) {
            foreach ($result[1] as $key) {
                $arr[] = trim($key);
            }
        }
        return $arr;
    }

    /**
     * @param $file
     * @return array
     */
    static public function parse_keys($file, $bucket = 'valid', $versionFilter = null)
    {
        Log::write_log(Language::t('log.running', __METHOD__), 5, Mirror::$version);
        $content = @file_get_contents($file);

        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);

        if (is_array($data) && (isset($data['valid']) || isset($data['invalid']))) {
            $bucketData = (isset($data[$bucket]) && is_array($data[$bucket])) ? $data[$bucket] : [];
            $keys = [];

            foreach ($bucketData as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                if (!isset($entry['login']) || !isset($entry['password'])) {
                    continue;
                }

                $versions = $entry['versions'] ?? [null];

                if (!is_array($versions)) {
                    $versions = [$versions];
                }

                if (empty($versions)) {
                    $versions = [null];
                }

                foreach ($versions as $version) {
                    if ($versionFilter !== null && $version !== $versionFilter) {
                        continue;
                    }
                    $keys[] = $entry['login'] . ':' . $entry['password'] . ':' . $version;
                }
            }

            return $keys;
        }

        $legacy = static::parse_line($content, false, "/(.+:.+:.+)\n/");

        if ($versionFilter !== null) {
            $legacy = array_values(array_filter($legacy, function ($item) use ($versionFilter) {
                $parts = explode(':', $item, 3);
                return !isset($parts[2]) || $parts[2] === $versionFilter;
            }));
        }

        return $legacy;
    }

    /**
     * @param $str_line
     * @param $filename
     */
    static public function delete_parse_line_in_file($str_line, $filename, $bucket = 'valid')
    {
        Log::write_log(Language::t('log.running', __METHOD__), 5, Mirror::$version);
        $content = @file_get_contents($filename);
        $data = json_decode($content, true);

        if (is_array($data) && (isset($data['valid']) || isset($data['invalid']))) {
            $parts = explode(':', $str_line, 3);
            $login = $parts[0] ?? null;
            $password = $parts[1] ?? null;
            $version = $parts[2] ?? null;

            $bucketData = (isset($data[$bucket]) && is_array($data[$bucket])) ? $data[$bucket] : [];

            foreach ($bucketData as $idx => &$entry) {
                if (!is_array($entry)) {
                    continue;
                }

                if (($entry['login'] ?? null) !== $login || ($entry['password'] ?? null) !== $password) {
                    continue;
                }

                $versions = $entry['versions'] ?? [];

                if (!is_array($versions)) {
                    $versions = [$versions];
                }

                if ($version !== null && $version !== '') {
                    $versions = array_values(array_filter($versions, function ($v) use ($version) {
                        return $v !== $version;
                    }));
                } else {
                    $versions = [];
                }

                if (empty($versions)) {
                    unset($bucketData[$idx]);
                } else {
                    $entry['versions'] = array_values(array_unique($versions));
                }
            }

            unset($entry);
            $data[$bucket] = array_values($bucketData);
            file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return;
        }

        if ($content === false) {
            return;
        }

        $lines = explode("\n", $content);
        $result = [];

        foreach ($lines as $line) {
            if (strpos($line, $str_line) !== false) {
                continue;
            }
            if ($line !== '') {
                $result[] = $line;
            }
        }

        $newContent = implode("\n", $result);
        file_put_contents($filename, $newContent);
    }

    /**
     * @param $handle
     * @param $template
     * @param $logins
     * @param $passwds
     */
    static public function parse_template($handle, $template, &$logins, &$passwds)
    {
        Log::write_log(Language::t('log.running', __METHOD__), 5, Mirror::$version);

        if (preg_match_all("/$template/s", $handle, $result, PREG_PATTERN_ORDER)) {
            $count = count($result[1]);

            for ($i = 0; $i < $count; $i++) {
                if (!empty($result[1][$i]) && !empty($result[3][$i])) {
                    $logins[] = $result[1][$i];
                    $passwds[] = $result[3][$i];
                }
            }
        }
    }

    /**
     * @param $http_response_header
     * @return array
     */
    static public function parse_header($http_response_header)
    {
        Log::write_log(Language::t('log.running', __METHOD__), 5, Mirror::$version);
        $header = [];

        foreach ($http_response_header as $line) {
            if (preg_match("/\:/", $line)) {
                $parse = array_map("trim", explode(":", $line, 2));
                $header = array_merge_recursive($header, array($parse[0] => $parse[1]));
            } else {
                $header = array_merge_recursive($header, array($line));
            }
        }
        return $header;
    }
}

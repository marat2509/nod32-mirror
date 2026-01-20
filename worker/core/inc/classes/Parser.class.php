<?php

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

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
        Log::trace(Language::t('log.running', __METHOD__), Mirror::$version);
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
        Log::trace(Language::t('log.running', __METHOD__), Mirror::$version);
        $content = @file_get_contents($file);

        if ($content === false) {
            Log::debug(Language::t('common.file_not_found', $file), Mirror::$version);
            return [];
        }

        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning(Language::t('parser.invalid_json', $file), Mirror::$version);
            return [];
        }

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

            Log::debug(Language::t('parser.keys_loaded', count($keys)), Mirror::$version);
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
     * Parse pattern file (YAML preferred, legacy key=value fallback).
     * All find.* parameters from config can be overridden locally in pattern files.
     * @param string $file
     * @param array $defaults
     * @return array|null
     */
    static public function parse_pattern_file($file, array $defaults = [])
    {
        Log::trace(Language::t('log.running', __METHOD__), Mirror::$version);

        if (!file_exists($file) || !is_readable($file)) {
            Log::debug(Language::t('parser.failed_load_pattern', $file), Mirror::$version);
            return null;
        }

        $content = @file_get_contents($file);
        if ($content === false) {
            Log::warning(Language::t('parser.failed_load_pattern', $file), Mirror::$version);
            return null;
        }

        Log::trace(Language::t('parser.pattern_loaded', basename($file)), Mirror::$version);

        $normalizeList = function ($value, $fallback = []) {
            if (is_array($value)) {
                $normalized = [];
                foreach ($value as $v) {
                    if (is_array($v) || is_object($v)) {
                        continue;
                    }
                    $normalized[] = trim((string)$v);
                }
                return array_values(array_filter($normalized, 'strlen'));
            }
            if (is_string($value)) {
                return [trim($value)];
            }
            return $fallback;
        };

        try {
            $parsed = Yaml::parse($content);
            if (is_array($parsed)) {
                $patterns = $normalizeList($parsed['pattern'] ?? null, $normalizeList($defaults['pattern'] ?? []));
                $headers = $normalizeList($parsed['header'] ?? $parsed['headers'] ?? null, $normalizeList($defaults['headers'] ?? []));
                $queries = $normalizeList($parsed['query'] ?? null, $normalizeList($defaults['query'] ?? []));
                return [
                    'link' => $parsed['link'] ?? null,
                    'pageindex' => intval($parsed['pageindex'] ?? ($defaults['pageindex'] ?? 1)),
                    'pattern' => $patterns,
                    'page_qty' => intval($parsed['page_qty'] ?? ($defaults['page_qty'] ?? 1)),
                    'recursion_level' => intval($parsed['recursion_level'] ?? ($defaults['recursion_level'] ?? 1)),
                    'user_agent' => $parsed['user_agent'] ?? ($defaults['user_agent'] ?? null),
                    'headers' => $headers,
                    'query' => $queries,
                    'number_attempts' => isset($parsed['number_attempts']) ? intval($parsed['number_attempts']) : ($defaults['number_attempts'] ?? null),
                    'count_keys' => isset($parsed['count_keys']) ? intval($parsed['count_keys']) : ($defaults['count_keys'] ?? null),
                    'errors_quantity' => isset($parsed['errors_quantity']) ? intval($parsed['errors_quantity']) : ($defaults['errors_quantity'] ?? null),
                ];
            }
        } catch (ParseException $e) {
            // Fallback to legacy format parsing
        }

        $link = static::parse_line($content, "link");
        $pageindex = static::parse_line($content, "pageindex");
        $pattern = static::parse_line($content, "pattern");
        $page_qty = static::parse_line($content, "page_qty");
        $recursion_level = static::parse_line($content, "recursion_level");
        $pattern_useragent = static::parse_line($content, "user_agent");
        $pattern_headers = static::parse_line($content, "header");
        $pattern_query = static::parse_line($content, "query");
        $number_attempts = static::parse_line($content, "number_attempts");
        $count_keys = static::parse_line($content, "count_keys");
        $errors_quantity = static::parse_line($content, "errors_quantity");

        return [
            'link' => $link[0] ?? null,
            'pageindex' => isset($pageindex[0]) ? intval($pageindex[0]) : intval($defaults['pageindex'] ?? 1),
            'pattern' => !empty($pattern) ? $pattern : $normalizeList($defaults['pattern'] ?? []),
            'page_qty' => isset($page_qty[0]) ? intval($page_qty[0]) : intval($defaults['page_qty'] ?? 1),
            'recursion_level' => isset($recursion_level[0]) ? intval($recursion_level[0]) : intval($defaults['recursion_level'] ?? 1),
            'user_agent' => $pattern_useragent[0] ?? ($defaults['user_agent'] ?? null),
            'headers' => $normalizeList($pattern_headers ?? [], $normalizeList($defaults['headers'] ?? [])),
            'query' => !empty($pattern_query) ? $pattern_query : $normalizeList($defaults['query'] ?? []),
            'number_attempts' => isset($number_attempts[0]) ? intval($number_attempts[0]) : ($defaults['number_attempts'] ?? null),
            'count_keys' => isset($count_keys[0]) ? intval($count_keys[0]) : ($defaults['count_keys'] ?? null),
            'errors_quantity' => isset($errors_quantity[0]) ? intval($errors_quantity[0]) : ($defaults['errors_quantity'] ?? null),
        ];
    }

    /**
     * @param $str_line
     * @param $filename
     */
    static public function delete_parse_line_in_file($str_line, $filename, $bucket = 'valid')
    {
        Log::trace(Language::t('log.running', __METHOD__), Mirror::$version);
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
        Log::trace(Language::t('log.running', __METHOD__), Mirror::$version);

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
        Log::trace(Language::t('log.running', __METHOD__), Mirror::$version);
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

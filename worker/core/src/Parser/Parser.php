<?php

declare(strict_types=1);

namespace Nod32Mirror\Parser;

use Nod32Mirror\Log\Log;
use Nod32Mirror\Log\Language;
use Nod32Mirror\Tools;
use Nod32Mirror\ValueObject\Credential;
use Nod32Mirror\ValueObject\DownloadableFile;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class Parser
{
    public function __construct(
        private readonly Log $log,
        private readonly Language $language
    ) {
    }

    /**
     * Parse line for a specific tag
     *
     * @return string[]
     */
    public function parseLine(string $content, string $tag, ?string $pattern = null): array
    {
        $this->log->trace($this->language->t('log.running', __METHOD__));

        $result = [];
        $regex = $pattern ?? '/' . preg_quote($tag, '/') . ' *=(.+)/';

        if (preg_match_all($regex, $content, $matches, PREG_PATTERN_ORDER)) {
            foreach ($matches[1] as $value) {
                $result[] = trim($value);
            }
        }

        return $result;
    }

    /**
     * Parse keys file
     *
     * @return Credential[]
     */
    public function parseKeysFile(string $filePath, string $bucket = 'valid', ?string $versionFilter = null): array
    {
        $this->log->trace($this->language->t('log.running', __METHOD__));

        $content = @file_get_contents($filePath);

        if ($content === false) {
            $this->log->debug($this->language->t('common.file_not_found', $filePath));
            return [];
        }

        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log->warning($this->language->t('parser.invalid_json', $filePath));
            return [];
        }

        if (!is_array($data) || (!isset($data['valid']) && !isset($data['invalid']))) {
            return $this->parseLegacyKeys($content, $versionFilter);
        }

        $bucketData = $data[$bucket] ?? [];
        $credentials = [];

        foreach ($bucketData as $entry) {
            if (!is_array($entry) || !isset($entry['login'], $entry['password'])) {
                continue;
            }

            $versions = $entry['versions'] ?? [];
            if (!is_array($versions)) {
                $versions = [$versions];
            }

            if (empty($versions)) {
                $versions = [];
            }

            if ($versionFilter !== null) {
                if (!empty($versions) && !in_array($versionFilter, $versions, true)) {
                    continue;
                }
            }

            $credentials[] = new Credential(
                login: $entry['login'],
                password: $entry['password'],
                versions: $versions
            );
        }

        if ($bucket === 'valid' && count($credentials) > 0) {
            $this->log->debug($this->language->t('parser.keys_loaded', count($credentials)));
        }

        return $credentials;
    }

    /**
     * @return Credential[]
     */
    private function parseLegacyKeys(string $content, ?string $versionFilter): array
    {
        $lines = $this->parseLine($content, '', '/(.+:.+:.+)\n/');
        $credentials = [];

        foreach ($lines as $line) {
            $parts = explode(':', $line, 3);

            if (count($parts) < 2) {
                continue;
            }

            $version = $parts[2] ?? null;

            if ($versionFilter !== null && $version !== null && $version !== $versionFilter) {
                continue;
            }

            $credentials[] = new Credential(
                login: $parts[0],
                password: $parts[1],
                versions: $version !== null ? [$version] : []
            );
        }

        return $credentials;
    }

    /**
     * Parse update.ver file content into downloadable files
     *
     * @param string[] $containers
     * @return array{files: DownloadableFile[], totalSize: int, content: string}
     */
    public function parseUpdateFile(array $containers, callable $platformFilter = null): array
    {
        $this->log->trace($this->language->t('log.running', __METHOD__));

        $files = [];
        $totalSize = 0;
        $newContent = '';
        $platformsFound = [];

        foreach ($containers as $container) {
            $normalized = preg_replace(
                '/version=(.*?)\n/i',
                'version="${1}"' . "\n",
                str_replace("\r\n", "\n", $container)
            );

            $parsed = @parse_ini_string($normalized ?? '', true);

            if (!$parsed || !is_array($parsed)) {
                continue;
            }

            $output = array_shift($parsed);

            if (empty($output['file']) || empty($output['size'])) {
                continue;
            }

            $file = DownloadableFile::fromArray($output);

            if ($platformFilter !== null && !$platformFilter($file)) {
                continue;
            }

            $files[] = $file;
            $totalSize += $file->size;
            $newContent .= $container;

            if ($file->platform !== null && !in_array($file->platform, $platformsFound, true)) {
                $platformsFound[] = $file->platform;
            }
        }

        return [
            'files' => $files,
            'totalSize' => $totalSize,
            'content' => $newContent,
            'platforms' => $platformsFound,
        ];
    }

    /**
     * Parse pattern file (YAML or legacy format)
     *
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>|null
     */
    public function parsePatternFile(string $filePath, array $defaults = []): ?array
    {
        $this->log->trace($this->language->t('log.running', __METHOD__));

        if (!file_exists($filePath) || !is_readable($filePath)) {
            $this->log->debug($this->language->t('parser.failed_load_pattern', $filePath));
            return null;
        }

        $content = @file_get_contents($filePath);

        if ($content === false) {
            $this->log->warning($this->language->t('parser.failed_load_pattern', $filePath));
            return null;
        }

        $this->log->trace($this->language->t('parser.pattern_loaded', basename($filePath)));

        try {
            $parsed = Yaml::parse($content);

            if (is_array($parsed)) {
                return $this->normalizePatternData($parsed, $defaults);
            }
        } catch (ParseException) {
            // Fall back to legacy format
        }

        return $this->parseLegacyPattern($content, $defaults);
    }

    /**
     * @param array<string, mixed> $parsed
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    private function normalizePatternData(array $parsed, array $defaults): array
    {
        return [
            'link' => $parsed['link'] ?? null,
            'pageindex' => (int) ($parsed['pageindex'] ?? $defaults['pageindex'] ?? 1),
            'pattern' => $this->normalizeList($parsed['pattern'] ?? null, $defaults['pattern'] ?? []),
            'page_qty' => (int) ($parsed['page_qty'] ?? $defaults['page_qty'] ?? 1),
            'recursion_level' => (int) ($parsed['recursion_level'] ?? $defaults['recursion_level'] ?? 1),
            'user_agent' => $parsed['user_agent'] ?? $defaults['user_agent'] ?? null,
            'headers' => $this->normalizeList($parsed['header'] ?? $parsed['headers'] ?? null, $defaults['headers'] ?? []),
            'query' => $this->normalizeList($parsed['query'] ?? null, $defaults['query'] ?? []),
            'number_attempts' => isset($parsed['number_attempts']) ? (int) $parsed['number_attempts'] : ($defaults['number_attempts'] ?? null),
            'count_keys' => isset($parsed['count_keys']) ? (int) $parsed['count_keys'] : ($defaults['count_keys'] ?? null),
            'errors_quantity' => isset($parsed['errors_quantity']) ? (int) $parsed['errors_quantity'] : ($defaults['errors_quantity'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    private function parseLegacyPattern(string $content, array $defaults): array
    {
        $link = $this->parseLine($content, 'link');
        $pageindex = $this->parseLine($content, 'pageindex');
        $pattern = $this->parseLine($content, 'pattern');
        $pageQty = $this->parseLine($content, 'page_qty');
        $recursionLevel = $this->parseLine($content, 'recursion_level');
        $userAgent = $this->parseLine($content, 'user_agent');
        $headers = $this->parseLine($content, 'header');
        $query = $this->parseLine($content, 'query');
        $numberAttempts = $this->parseLine($content, 'number_attempts');
        $countKeys = $this->parseLine($content, 'count_keys');
        $errorsQuantity = $this->parseLine($content, 'errors_quantity');

        return [
            'link' => $link[0] ?? null,
            'pageindex' => isset($pageindex[0]) ? (int) $pageindex[0] : (int) ($defaults['pageindex'] ?? 1),
            'pattern' => !empty($pattern) ? $pattern : $this->normalizeList($defaults['pattern'] ?? []),
            'page_qty' => isset($pageQty[0]) ? (int) $pageQty[0] : (int) ($defaults['page_qty'] ?? 1),
            'recursion_level' => isset($recursionLevel[0]) ? (int) $recursionLevel[0] : (int) ($defaults['recursion_level'] ?? 1),
            'user_agent' => $userAgent[0] ?? $defaults['user_agent'] ?? null,
            'headers' => $this->normalizeList($headers, $defaults['headers'] ?? []),
            'query' => !empty($query) ? $query : $this->normalizeList($defaults['query'] ?? []),
            'number_attempts' => isset($numberAttempts[0]) ? (int) $numberAttempts[0] : ($defaults['number_attempts'] ?? null),
            'count_keys' => isset($countKeys[0]) ? (int) $countKeys[0] : ($defaults['count_keys'] ?? null),
            'errors_quantity' => isset($errorsQuantity[0]) ? (int) $errorsQuantity[0] : ($defaults['errors_quantity'] ?? null),
        ];
    }

    /**
     * Parse HTML/text for credentials using template
     *
     * @param string[] $logins Reference to logins array
     * @param string[] $passwords Reference to passwords array
     */
    public function parseTemplate(string $content, string $template, array &$logins, array &$passwords): void
    {
        $this->log->trace($this->language->t('log.running', __METHOD__));

        if (!preg_match_all('/' . $template . '/s', $content, $result, PREG_PATTERN_ORDER)) {
            return;
        }

        $count = count($result[1]);

        for ($i = 0; $i < $count; $i++) {
            if (!empty($result[1][$i]) && !empty($result[3][$i])) {
                $logins[] = $result[1][$i];
                $passwords[] = $result[3][$i];
            }
        }
    }

    /**
     * Get database version from update.ver file
     */
    public function getDbVersion(string $filePath): ?int
    {
        $this->log->trace($this->language->t('log.running', __METHOD__));

        if (!file_exists($filePath)) {
            return null;
        }

        $content = @file_get_contents($filePath);

        if ($content === false) {
            return null;
        }

        $versions = $this->parseLine($content, 'versionid');
        $max = 0;

        foreach ($versions as $version) {
            $intVersion = (int) $version;
            if ($intVersion > $max) {
                $max = $intVersion;
            }
        }

        return $max > 0 ? $max : null;
    }

    /**
     * @return string[]
     */
    private function normalizeList(mixed $value, mixed $fallback = []): array
    {
        if (is_array($value)) {
            $normalized = [];

            foreach ($value as $v) {
                if (is_scalar($v)) {
                    $normalized[] = trim((string) $v);
                }
            }

            return array_values(array_filter($normalized, 'strlen'));
        }

        if (is_string($value)) {
            return [trim($value)];
        }

        if (is_array($fallback)) {
            return array_values(array_filter(array_map('trim', $fallback), 'strlen'));
        }

        return [];
    }
}

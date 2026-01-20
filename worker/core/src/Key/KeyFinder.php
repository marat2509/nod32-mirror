<?php

declare(strict_types=1);

namespace Nod32Mirror\Key;

use Nod32Mirror\Config\Config;
use Nod32Mirror\Contract\DownloaderInterface;
use Nod32Mirror\Log\Log;
use Nod32Mirror\Log\Language;
use Nod32Mirror\Parser\Parser;
use Nod32Mirror\Tools;
use Nod32Mirror\ValueObject\Credential;
use Nod32Mirror\ValueObject\MirrorInfo;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class KeyFinder
{
    private bool $foundValidKey = false;

    public function __construct(
        private readonly KeyManager $keyManager,
        private readonly DownloaderInterface $downloader,
        private readonly Parser $parser,
        private readonly Config $config,
        private readonly Log $log,
        private readonly Language $language
    ) {
    }

    /**
     * Search for keys using pattern files
     *
     * @param string[] $mirrors
     * @return array{credential: Credential, mirrors: MirrorInfo[]}|null
     */
    public function findKeys(string $version, string $updateFilePath, array $mirrors): ?array
    {
        $this->log->trace($this->language->t('log.running', __METHOD__), $version);
        $this->foundValidKey = false;

        $findConfig = $this->config->getOrDefault('find', []);

        if (empty($findConfig['enabled'])) {
            return null;
        }

        $globalMaxAttempts = max(1, (int) ($findConfig['number_attempts'] ?? 1));
        $globalQueries = $this->normalizeQueryList($findConfig['query'] ?? []);

        $patterns = $this->getPatternFiles($findConfig['system'] ?? null);

        foreach ($patterns as $patternFile) {
            $this->log->debug(
                $this->language->t('mirror.begin_search', str_replace(realpath(PATTERN) . DIRECTORY_SEPARATOR, '', $patternFile)),
                $version
            );

            $patternData = $this->parser->parsePatternFile($patternFile, [
                'pageindex' => $findConfig['pageindex'] ?? 1,
                'pattern' => [$findConfig['pattern'] ?? ''],
                'page_qty' => $findConfig['page_qty'] ?? 1,
                'recursion_level' => $findConfig['recursion_level'] ?? 1,
                'user_agent' => $findConfig['user_agent'] ?? null,
                'headers' => $findConfig['headers'] ?? [],
                'query' => $globalQueries,
                'number_attempts' => $globalMaxAttempts,
                'count_keys' => $findConfig['count_keys'] ?? 1,
                'errors_quantity' => $findConfig['errors_quantity'] ?? 5,
            ]);

            if (empty($patternData) || empty($patternData['link'])) {
                $this->log->debug($this->language->t('mirror.link_not_set', $patternFile), $version);
                continue;
            }

            $maxAttempts = $patternData['number_attempts'] ?? $globalMaxAttempts;
            $queries = !empty($patternData['query']) ? $patternData['query'] : $globalQueries;

            if (empty($queries)) {
                $this->log->debug($this->language->t('mirror.query_not_set', $patternFile), $version);
                continue;
            }

            $result = $this->searchWithPattern($version, $updateFilePath, $mirrors, $patternData, $queries, $maxAttempts);

            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @param string[] $mirrors
     * @param array<string, mixed> $patternData
     * @param string[] $queries
     * @return array{credential: Credential, mirrors: MirrorInfo[]}|null
     */
    private function searchWithPattern(
        string $version,
        string $updateFilePath,
        array $mirrors,
        array $patternData,
        array $queries,
        int $maxAttempts
    ): ?array {
        $attempts = 0;

        foreach ($queries as $query) {
            $pages = str_contains($patternData['link'], '#PAGE#') ? (int) $patternData['page_qty'] : 1;

            for ($i = 0; $i < $pages; $i++) {
                if ($attempts >= $maxAttempts) {
                    return null;
                }

                $url = str_replace('#QUERY#', str_replace(' ', '+', trim($query)), $patternData['link']);
                $url = str_replace('#PAGE#', (string) ($i * (int) $patternData['pageindex']), $url);

                $attempts++;

                $result = $this->parseWebPage(
                    $version,
                    $updateFilePath,
                    $mirrors,
                    $url,
                    (int) $patternData['recursion_level'],
                    $patternData['pattern'] ?? [],
                    [
                        'user_agent' => $patternData['user_agent'] ?? null,
                        'headers' => $patternData['headers'] ?? [],
                    ]
                );

                if ($result !== null) {
                    return $result;
                }

                // Simple linear backoff
                usleep(min($attempts, 5) * 200000);
            }
        }

        return null;
    }

    /**
     * @param string[] $mirrors
     * @param string[] $patterns
     * @param array{user_agent?: ?string, headers?: string[]} $requestOptions
     * @return array{credential: Credential, mirrors: MirrorInfo[]}|null
     */
    private function parseWebPage(
        string $version,
        string $updateFilePath,
        array $mirrors,
        string $url,
        int $level,
        array $patterns,
        array $requestOptions = []
    ): ?array {
        $this->log->trace($this->language->t('log.running', __METHOD__), $version);

        $findConfig = $this->config->getOrDefault('find', []);
        $defaultUa = $findConfig['user_agent'] ?? 'Mozilla/5.0 (Windows; U; Windows NT 6.1; rv:2.2) Gecko/20110201';
        $ua = !empty($requestOptions['user_agent']) ? $requestOptions['user_agent'] : $defaultUa;

        $result = $this->downloader->get($url);

        if (!$result->isSuccessful() || $result->body === null) {
            $this->log->debug($this->language->t('mirror.link_not_found', $url), $version);
            return null;
        }

        $this->log->debug($this->language->t('mirror.link_found', $url), $version);

        $content = $result->body;
        $logins = [];
        $passwords = [];

        $scriptConfig = $this->config->getOrDefault('script', []);
        if (!empty($scriptConfig['debug_html'])) {
            $this->saveDebugHtml($content, $url);
        }

        foreach ($patterns as $pattern) {
            $this->parser->parseTemplate($content, $pattern, $logins, $passwords);
        }

        $loginCount = count($logins);

        if ($loginCount > 0) {
            $this->log->debug($this->language->t('mirror.found_keys', $loginCount), $version);

            for ($i = 0; $i < $loginCount; $i++) {
                if (preg_match('/script|googleuser/i', $passwords[$i])) {
                    continue;
                }

                if ($this->keyManager->isValidKey($logins[$i], $passwords[$i], $version)) {
                    continue;
                }

                $credential = new Credential($logins[$i], $passwords[$i], [$version]);
                $testResult = $this->keyManager->testKey($credential, $version, $updateFilePath, $mirrors);

                if ($testResult !== null) {
                    $this->keyManager->addKey($logins[$i], $passwords[$i], $version);
                    $this->foundValidKey = true;
                    return $testResult;
                }
            }
        }

        if ($level > 1) {
            $links = $this->extractLinks($content);
            $this->log->debug($this->language->t('mirror.found_links', count($links)), $version);

            foreach ($links as $link) {
                $result = $this->parseWebPage($version, $updateFilePath, $mirrors, $link, $level - 1, $patterns, $requestOptions);

                if ($result !== null) {
                    return $result;
                }
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function extractLinks(string $content): array
    {
        $links = [];

        if (!preg_match_all('/href *= *"([^\s"]+)/', $content, $results)) {
            return $links;
        }

        foreach ($results[1] as $result) {
            $result = str_replace('webcache.googleusercontent.com/search?q=cache:', '', $result);

            if (preg_match('/youtube\.com|socialcomments\.org/', $result)) {
                continue;
            }

            if (preg_match('/https?:\/\/(?(?!\&amp).)*/', $result, $match)) {
                if (!empty($match[0])) {
                    $links[] = $match[0];
                }
            }
        }

        return array_unique($links);
    }

    /**
     * @return string[]
     */
    private function getPatternFiles(?string $system): array
    {
        if (!empty($system)) {
            $file = PATTERN . $system . '.pattern';
            return file_exists($file) ? [$file] : [];
        }

        $patterns = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(PATTERN)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'pattern') {
                $patterns[] = $file->getPathname();
            }
        }

        shuffle($patterns);

        return $patterns;
    }

    private function saveDebugHtml(string $content, string $url): void
    {
        $pathInfo = pathinfo($url);
        $dir = Tools::ds($this->config->getDataDir(), DEBUG_DIR, $pathInfo['basename'] ?? 'unknown');
        Tools::ensureDirectory($dir);

        $filename = Tools::ds($dir, ($pathInfo['filename'] ?? 'page') . '.log');
        $cleaned = $this->stripTagsAndCss($content);

        file_put_contents($filename, $cleaned);
    }

    private function stripTagsAndCss(string $content): string
    {
        $patterns = [
            "'<script[^>]*?>.*?</script>'si",
            "'<[/!]*?[^<>]*?>'si",
            "'([\r\n])[\s]+'",
            "'&(quot|#34);'i",
            "'&(amp|#38);'i",
            "'&(lt|#60);'i",
            "'&(gt|#62);'i",
            "'&(nbsp|#160);'i",
        ];

        $replacements = ['', '', "\\1", '"', '&', '<', '>', ' '];

        $clean = preg_replace($patterns, $replacements, $content) ?? $content;
        $clean = preg_replace_callback('/&#(\d+);/', static function (array $matches): string {
            $code = (int) $matches[1];
            return ($code >= 0 && $code <= 255) ? chr($code) : '';
        }, $clean);

        return trim($clean ?? '');
    }

    /**
     * @return string[]
     */
    private function normalizeQueryList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value), 'strlen'));
        }

        if (is_string($value) && strlen(trim($value)) > 0) {
            return [trim($value)];
        }

        return [];
    }

    public function wasKeyFound(): bool
    {
        return $this->foundValidKey;
    }
}

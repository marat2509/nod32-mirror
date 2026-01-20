<?php

declare(strict_types=1);

namespace Nod32Mirror\Download;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Nod32Mirror\Config\Config;
use Nod32Mirror\Contract\DownloaderInterface;
use Nod32Mirror\Enum\ProxyType;
use Nod32Mirror\Log\Log;
use Nod32Mirror\Log\Language;
use Nod32Mirror\Tools;
use Nod32Mirror\ValueObject\Credential;
use Nod32Mirror\ValueObject\DownloadableFile;
use Nod32Mirror\ValueObject\DownloadResult;

final class GuzzleDownloader implements DownloaderInterface
{
    private Client $client;
    private int $concurrency = 32;
    private int $timeout = 5;
    private int $connectTimeout = 5;
    private int $speedLimit = 0;
    private int $maxRetries = 3;
    private int $retryDelay = 1000; // milliseconds

    /** @var array<string, mixed> */
    private array $defaultOptions = [];

    public function __construct(
        private readonly Config $config,
        private readonly Log $log,
        private readonly Language $language
    ) {
        $this->initClient();
    }

    private function initClient(): void
    {
        $this->timeout = $this->config->getTimeout();
        $this->connectTimeout = $this->config->getConnectTimeout();
        $this->concurrency = $this->config->getMaxThreads();
        $this->maxRetries = $this->config->getMaxRetries();
        $this->retryDelay = $this->config->getRetryDelay();

        $options = [
            RequestOptions::TIMEOUT => $this->timeout,
            RequestOptions::CONNECT_TIMEOUT => $this->connectTimeout,
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::ALLOW_REDIRECTS => [
                'max' => 5,
                'strict' => false,
                'referer' => true,
                'protocols' => ['http', 'https'],
            ],
            RequestOptions::VERIFY => false,
        ];

        if ($this->config->isProxyEnabled()) {
            $proxy = $this->buildProxyString();
            if ($proxy !== null) {
                $options[RequestOptions::PROXY] = $proxy;
            }
        }

        $speedLimit = (int) $this->config->getOrDefault('connection.speed_limit', 0);
        if ($speedLimit > 0) {
            $this->speedLimit = $speedLimit;
        }

        $this->defaultOptions = $options;
        $this->client = new Client($options);
    }

    private function buildProxyString(): ?string
    {
        try {
            $proxyConfig = $this->config->get('connection.proxy');
        } catch (\Exception) {
            return null;
        }

        if (!is_array($proxyConfig) || empty($proxyConfig['server'])) {
            return null;
        }

        $type = ProxyType::fromString($proxyConfig['type'] ?? 'http');
        $server = $proxyConfig['server'];
        $port = (int) ($proxyConfig['port'] ?? 80);
        $user = $proxyConfig['user'] ?? '';
        $password = $proxyConfig['password'] ?? '';

        $scheme = match ($type) {
            ProxyType::Socks4 => 'socks4',
            ProxyType::Socks4a => 'socks4a',
            ProxyType::Socks5 => 'socks5',
            default => 'http',
        };

        $auth = '';
        if (!empty($user)) {
            $auth = urlencode($user);
            if (!empty($password)) {
                $auth .= ':' . urlencode($password);
            }
            $auth .= '@';
        }

        return sprintf('%s://%s%s:%d', $scheme, $auth, $server, $port);
    }

    public function get(string $url, ?Credential $auth = null): DownloadResult
    {
        $this->log->trace($this->language->t('log.running', __METHOD__));

        $options = $this->buildRequestOptions($auth);

        $startTime = microtime(true);

        try {
            $response = $this->client->get($url, $options);
            $totalTime = microtime(true) - $startTime;

            return $this->buildResult($response, $totalTime);
        } catch (GuzzleException $e) {
            $totalTime = microtime(true) - $startTime;
            return $this->buildErrorResult($e, $totalTime);
        }
    }

    public function downloadToFile(string $url, string $targetPath, ?Credential $auth = null): DownloadResult
    {
        $this->log->trace($this->language->t('log.running', __METHOD__));

        $dir = dirname($targetPath);
        Tools::ensureDirectory($dir);

        $options = $this->buildRequestOptions($auth);
        $options[RequestOptions::SINK] = $targetPath;

        $startTime = microtime(true);

        try {
            $response = $this->client->get($url, $options);
            $totalTime = microtime(true) - $startTime;

            $httpCode = $response->getStatusCode();

            if ($httpCode === 200 && file_exists($targetPath)) {
                clearstatcache(true, $targetPath);
                $downloadedBytes = (int) filesize($targetPath);

                return new DownloadResult(
                    success: true,
                    httpCode: $httpCode,
                    downloadedBytes: $downloadedBytes,
                    totalTime: $totalTime,
                    contentType: $response->getHeaderLine('Content-Type')
                );
            }

            @unlink($targetPath);

            return DownloadResult::failure(
                httpCode: $httpCode,
                error: 'Download failed with HTTP ' . $httpCode,
                totalTime: $totalTime
            );
        } catch (GuzzleException $e) {
            @unlink($targetPath);
            $totalTime = microtime(true) - $startTime;
            return $this->buildErrorResult($e, $totalTime);
        }
    }

    public function checkUrl(string $url, ?Credential $auth = null): DownloadResult
    {
        $this->log->trace($this->language->t('log.running', __METHOD__));

        $options = $this->buildRequestOptions($auth);

        $startTime = microtime(true);

        try {
            $response = $this->client->head($url, $options);
            $totalTime = microtime(true) - $startTime;

            $httpCode = $response->getStatusCode();

            return new DownloadResult(
                success: $httpCode === 200,
                httpCode: $httpCode,
                downloadedBytes: 0,
                totalTime: $totalTime,
                contentType: $response->getHeaderLine('Content-Type')
            );
        } catch (GuzzleException $e) {
            $totalTime = microtime(true) - $startTime;
            return $this->buildErrorResult($e, $totalTime);
        }
    }

    /**
     * @param DownloadableFile[] $files
     * @return array<string, DownloadResult>
     */
    public function downloadMultiple(
        array $files,
        string $baseUrl,
        string $targetDir,
        ?Credential $auth = null
    ): array {
        $this->log->trace($this->language->t('log.running', __METHOD__));

        if (empty($files)) {
            return [];
        }

        $results = [];
        $filesToDownload = $files;
        $requestOptions = $this->buildRequestOptions($auth);

        // Create file size map for validation
        $fileSizeMap = [];
        $fileMap = [];
        foreach ($files as $file) {
            $fileSizeMap[$file->path] = $file->size;
            $fileMap[$file->path] = $file;
        }

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            if (empty($filesToDownload)) {
                break;
            }

            if ($attempt > 1) {
                $delay = $this->retryDelay * pow(2, $attempt - 2); // exponential backoff
                $this->log->debug(
                    $this->language->t('mirror.retry_batch_download', count($filesToDownload), $attempt, $this->maxRetries)
                );
                usleep($delay * 1000);
            }

            $currentResults = $this->executeDownloadBatch(
                $filesToDownload,
                $baseUrl,
                $targetDir,
                $requestOptions,
                $fileSizeMap
            );

            // Merge results and find failed downloads for retry
            $failedFiles = [];
            foreach ($currentResults as $filePath => $result) {
                if ($result->isSuccessful()) {
                    $results[$filePath] = $result;
                } else {
                    // Only retry on timeout or network errors, not on 4xx/5xx HTTP errors
                    if ($this->isRetryableError($result)) {
                        if (isset($fileMap[$filePath])) {
                            $failedFiles[] = $fileMap[$filePath];
                        }
                    } else {
                        $results[$filePath] = $result;
                    }
                }
            }

            $filesToDownload = $failedFiles;
        }

        // Add remaining failed files to results
        foreach ($filesToDownload as $file) {
            if (!isset($results[$file->path])) {
                $results[$file->path] = DownloadResult::failure(
                    httpCode: 0,
                    error: $this->language->t('mirror.retries_exhausted', $this->maxRetries),
                    totalTime: 0.0
                );
            }
        }

        return $results;
    }

    /**
     * Check if the error is retryable (timeout, network error, etc.)
     */
    private function isRetryableError(DownloadResult $result): bool
    {
        // Retry on network errors (HTTP 0) or server errors (5xx)
        if ($result->httpCode === 0) {
            return true;
        }

        if ($result->httpCode >= 500 && $result->httpCode < 600) {
            return true;
        }

        // Check for timeout or connection errors in error message
        if ($result->error !== null) {
            $retryablePatterns = [
                'timed out',
                'timeout',
                'connection reset',
                'connection refused',
                'could not resolve',
                'network is unreachable',
            ];

            $errorLower = strtolower($result->error);
            foreach ($retryablePatterns as $pattern) {
                if (str_contains($errorLower, $pattern)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param DownloadableFile[] $files
     * @param array<string, mixed> $requestOptions
     * @param array<string, int> $fileSizeMap
     * @return array<string, DownloadResult>
     */
    private function executeDownloadBatch(
        array $files,
        string $baseUrl,
        string $targetDir,
        array $requestOptions,
        array $fileSizeMap
    ): array {
        $results = [];

        // Create requests generator
        $requests = function () use ($files, $baseUrl, $targetDir, $requestOptions): \Generator {
            foreach ($files as $file) {
                $url = rtrim($baseUrl, '/') . '/' . ltrim($file->path, '/');
                $targetPath = Tools::ds($targetDir, $file->path);

                Tools::ensureDirectory(dirname($targetPath));

                $options = $requestOptions;
                $options[RequestOptions::SINK] = $targetPath;

                yield $file->path => function () use ($url, $options): \GuzzleHttp\Promise\PromiseInterface {
                    return $this->client->getAsync($url, $options);
                };
            }
        };

        $startTimes = [];
        foreach ($files as $file) {
            $startTimes[$file->path] = microtime(true);
        }

        $pool = new Pool($this->client, $requests(), [
            'concurrency' => $this->concurrency,
            'fulfilled' => function (Response $response, string $filePath) use (&$results, $targetDir, $fileSizeMap, $startTimes): void {
                $totalTime = microtime(true) - ($startTimes[$filePath] ?? microtime(true));
                $targetPath = Tools::ds($targetDir, $filePath);
                $httpCode = $response->getStatusCode();
                $expectedSize = $fileSizeMap[$filePath] ?? null;

                if ($httpCode === 200 && file_exists($targetPath)) {
                    clearstatcache(true, $targetPath);
                    $actualSize = (int) filesize($targetPath);

                    if ($expectedSize !== null && $actualSize !== $expectedSize) {
                        @unlink($targetPath);
                        $results[$filePath] = DownloadResult::failure(
                            httpCode: $httpCode,
                            error: sprintf('Size mismatch: expected %d, got %d', $expectedSize, $actualSize),
                            totalTime: $totalTime
                        );
                        return;
                    }

                    $results[$filePath] = new DownloadResult(
                        success: true,
                        httpCode: $httpCode,
                        downloadedBytes: $actualSize,
                        totalTime: $totalTime,
                        contentType: $response->getHeaderLine('Content-Type')
                    );
                } else {
                    @unlink($targetPath);
                    $results[$filePath] = DownloadResult::failure(
                        httpCode: $httpCode,
                        error: 'Download failed with HTTP ' . $httpCode,
                        totalTime: $totalTime
                    );
                }
            },
            'rejected' => function (GuzzleException $e, string $filePath) use (&$results, $targetDir, $startTimes): void {
                $totalTime = microtime(true) - ($startTimes[$filePath] ?? microtime(true));
                $targetPath = Tools::ds($targetDir, $filePath);
                @unlink($targetPath);

                $httpCode = 0;
                if ($e instanceof RequestException && $e->hasResponse()) {
                    $httpCode = $e->getResponse()->getStatusCode();
                }

                $results[$filePath] = DownloadResult::failure(
                    httpCode: $httpCode,
                    error: $e->getMessage(),
                    totalTime: $totalTime
                );
            },
        ]);

        $promise = $pool->promise();
        $promise->wait();

        return $results;
    }

    public function setConcurrency(int $max): void
    {
        $this->concurrency = max(1, $max);
    }

    public function setTimeout(int $seconds): void
    {
        $this->timeout = max(1, $seconds);
        $this->defaultOptions[RequestOptions::TIMEOUT] = $this->timeout;
        $this->defaultOptions[RequestOptions::CONNECT_TIMEOUT] = $this->timeout;
        $this->client = new Client($this->defaultOptions);
    }

    public function setSpeedLimit(int $bytesPerSecond): void
    {
        $this->speedLimit = max(0, $bytesPerSecond);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRequestOptions(?Credential $auth = null): array
    {
        $options = [];

        if ($auth !== null) {
            $options[RequestOptions::AUTH] = [$auth->login, $auth->password];
        }

        return $options;
    }

    private function buildResult(Response $response, float $totalTime): DownloadResult
    {
        $httpCode = $response->getStatusCode();
        $body = (string) $response->getBody();

        return new DownloadResult(
            success: $httpCode === 200,
            httpCode: $httpCode,
            downloadedBytes: strlen($body),
            totalTime: $totalTime,
            contentType: $response->getHeaderLine('Content-Type'),
            body: $body
        );
    }

    private function buildErrorResult(GuzzleException $e, float $totalTime): DownloadResult
    {
        $httpCode = 0;

        if ($e instanceof RequestException && $e->hasResponse()) {
            $httpCode = $e->getResponse()->getStatusCode();
        }

        $this->log->error($this->language->t('tools.curl_error', 0, $e->getMessage()));

        return DownloadResult::failure(
            httpCode: $httpCode,
            error: $e->getMessage(),
            totalTime: $totalTime
        );
    }

    public function getClient(): Client
    {
        return $this->client;
    }
}

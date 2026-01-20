<?php

declare(strict_types=1);

namespace Nod32Mirror\Contract;

use Nod32Mirror\ValueObject\Credential;
use Nod32Mirror\ValueObject\DownloadableFile;
use Nod32Mirror\ValueObject\DownloadResult;

interface DownloaderInterface
{
    /**
     * Download content and return as string
     */
    public function get(string $url, ?Credential $auth = null): DownloadResult;

    /**
     * Download file to disk
     */
    public function downloadToFile(string $url, string $targetPath, ?Credential $auth = null): DownloadResult;

    /**
     * Check if URL is accessible (HEAD request)
     */
    public function checkUrl(string $url, ?Credential $auth = null): DownloadResult;

    /**
     * Download multiple files concurrently
     *
     * @param DownloadableFile[] $files
     * @param string $baseUrl
     * @param string $targetDir
     * @param Credential|null $auth
     * @return array<string, DownloadResult> Keyed by file path
     */
    public function downloadMultiple(
        array $files,
        string $baseUrl,
        string $targetDir,
        ?Credential $auth = null
    ): array;

    /**
     * Set maximum concurrent connections
     */
    public function setConcurrency(int $max): void;

    /**
     * Set connection timeout in seconds
     */
    public function setTimeout(int $seconds): void;

    /**
     * Set speed limit in bytes per second (0 = unlimited)
     */
    public function setSpeedLimit(int $bytesPerSecond): void;
}

<?php

declare(strict_types=1);

namespace Nod32Mirror\ValueObject;

final readonly class MirrorInfo
{
    public function __construct(
        public string $host,
        public ?int $dbVersion = null,
        public ?int $responseTime = null
    ) {
    }

    public function getBaseUrl(bool $https = false): string
    {
        $scheme = $https ? 'https' : 'http';

        if (preg_match('#^https?://#i', $this->host)) {
            return rtrim($this->host, '/');
        }

        return $scheme . '://' . ltrim($this->host, '/');
    }

    public function buildUrl(string $path, bool $https = false): string
    {
        return $this->getBaseUrl($https) . '/' . ltrim($path, '/');
    }

    public function withDbVersion(int $version): self
    {
        return new self($this->host, $version, $this->responseTime);
    }

    public function withResponseTime(int $timeMs): self
    {
        return new self($this->host, $this->dbVersion, $timeMs);
    }
}

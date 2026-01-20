<?php

declare(strict_types=1);

namespace Nod32Mirror\ValueObject;

final readonly class DownloadResult
{
    public function __construct(
        public bool $success,
        public int $httpCode,
        public int $downloadedBytes,
        public float $totalTime,
        public ?string $contentType = null,
        public ?string $body = null,
        public ?string $error = null
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->success && $this->httpCode === 200;
    }

    public function getSpeed(): float
    {
        if ($this->totalTime <= 0) {
            return 0.0;
        }

        return $this->downloadedBytes / $this->totalTime;
    }

    public static function success(
        int $downloadedBytes,
        float $totalTime,
        ?string $contentType = null,
        ?string $body = null
    ): self {
        return new self(
            success: true,
            httpCode: 200,
            downloadedBytes: $downloadedBytes,
            totalTime: $totalTime,
            contentType: $contentType,
            body: $body
        );
    }

    public static function failure(
        int $httpCode,
        string $error,
        float $totalTime = 0.0
    ): self {
        return new self(
            success: false,
            httpCode: $httpCode,
            downloadedBytes: 0,
            totalTime: $totalTime,
            error: $error
        );
    }
}

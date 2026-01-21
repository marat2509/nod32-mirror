<?php

declare(strict_types=1);

namespace Nod32Mirror\Exception;

use Exception;

/**
 * Exception for download-related errors
 */
final class DownloadException extends Nod32MirrorException
{
    public function __construct(
        string $message,
        public readonly string $url = '',
        public readonly int $httpCode = 0,
        ?Exception $previous = null
    ) {
        parent::__construct($message, $httpCode, $previous);
    }

    public static function failed(string $url, int $httpCode, ?string $error = null): self
    {
        $message = "Download failed with HTTP $httpCode";
        if ($error !== null) {
            $message .= ": $error";
        }
        return new self($message, $url, $httpCode);
    }

    public static function sizeMismatch(string $url, int $expected, int $actual): self
    {
        return new self(
            "Size mismatch: expected $expected bytes, got $actual bytes",
            $url,
            200
        );
    }

    public static function emptyResponse(string $url): self
    {
        return new self("Empty response received", $url, 200);
    }
}

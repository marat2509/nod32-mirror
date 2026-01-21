<?php

declare(strict_types=1);

namespace Nod32Mirror\Exception;

/**
 * Exception for network-related errors (timeouts, connection failures, etc.)
 */
final class NetworkException extends Nod32MirrorException
{
    public static function connectionFailed(string $url, ?string $reason = null): self
    {
        $message = "Connection failed to: $url";
        if ($reason !== null) {
            $message .= " ($reason)";
        }
        return new self($message);
    }

    public static function timeout(string $url, int $timeoutSeconds): self
    {
        return new self("Request timed out after {$timeoutSeconds}s: $url");
    }

    public static function downloadFailed(string $url, int $httpCode, ?string $error = null): self
    {
        $message = "Download failed with HTTP $httpCode: $url";
        if ($error !== null) {
            $message .= " ($error)";
        }
        return new self($message, $httpCode);
    }
}

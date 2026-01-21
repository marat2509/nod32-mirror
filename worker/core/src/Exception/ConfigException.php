<?php

declare(strict_types=1);

namespace Nod32Mirror\Exception;

/**
 * Exception for configuration-related errors
 */
final class ConfigException extends Nod32MirrorException
{
    public static function fileNotFound(string $path): self
    {
        return new self("Configuration file not found: $path");
    }

    public static function fileNotReadable(string $path): self
    {
        return new self("Configuration file is not readable: $path");
    }

    public static function parseError(string $message): self
    {
        return new self("Failed to parse configuration: $message");
    }

    public static function invalidConfig(string $reason): self
    {
        return new self("Invalid configuration: $reason");
    }

    public static function missingRequired(string $key): self
    {
        return new self("Missing required configuration key: $key");
    }
}

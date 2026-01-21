<?php

declare(strict_types=1);

namespace Nod32Mirror\Exception;

/**
 * Exception for key/credential-related errors
 */
final class KeyException extends Nod32MirrorException
{
    public static function invalidFormat(string $reason): self
    {
        return new self("Invalid key format: $reason");
    }

    public static function storageError(string $reason): self
    {
        return new self("Key storage error: $reason");
    }

    public static function notFound(string $login): self
    {
        return new self("Key not found: $login");
    }
}

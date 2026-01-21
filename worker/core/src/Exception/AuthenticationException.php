<?php

declare(strict_types=1);

namespace Nod32Mirror\Exception;

/**
 * Exception for authentication-related errors
 */
final class AuthenticationException extends Nod32MirrorException
{
    public static function invalidCredentials(string $login): self
    {
        return new self("Invalid credentials for user: $login");
    }

    public static function credentialsExpired(string $login): self
    {
        return new self("Credentials expired for user: $login");
    }

    public static function noValidCredentials(string $version): self
    {
        return new self("No valid credentials found for version: $version");
    }

    public static function unauthorized(string $url): self
    {
        return new self("Unauthorized access to: $url", 401);
    }
}

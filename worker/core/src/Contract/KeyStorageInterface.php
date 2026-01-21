<?php

declare(strict_types=1);

namespace Nod32Mirror\Contract;

use Nod32Mirror\ValueObject\Credential;

interface KeyStorageInterface
{
    /**
     * Get all valid credentials for a version
     *
     * @return Credential[]
     */
    public function getValidKeys(?string $version = null): array;

    /**
     * Add or update a valid credential
     */
    public function addValidKey(Credential $credential): void;

    /**
     * Mark a credential as invalid for a specific version
     */
    public function markKeyInvalid(string $login, string $password, string $version): void;

    /**
     * Remove a version from valid keys (optionally remove entire key if no versions left)
     */
    public function removeVersionFromValidKey(string $login, string $password, string $version): void;

    /**
     * Check if credential exists in valid keys
     */
    public function isValidKey(string $login, string $password, ?string $version = null): bool;

    /**
     * Check if credential is marked as invalid
     */
    public function isInvalidKey(string $login, string $password, ?string $version = null): bool;

    /**
     * Persist changes to storage
     */
    public function save(): void;
}

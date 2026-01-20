<?php

declare(strict_types=1);

namespace Nod32Mirror\Key;

use Nod32Mirror\Contract\KeyStorageInterface;
use Nod32Mirror\Log\Log;
use Nod32Mirror\Log\Language;
use Nod32Mirror\Tools;
use Nod32Mirror\ValueObject\Credential;

final class JsonKeyStorage implements KeyStorageInterface
{
    /** @var Credential[] */
    private array $validKeys = [];

    /** @var Credential[] */
    private array $invalidKeys = [];

    private bool $loaded = false;
    private bool $dirty = false;

    public function __construct(
        private readonly string $filePath,
        private readonly Log $log,
        private readonly Language $language
    ) {
    }

    private function ensureLoaded(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->load();
    }

    private function load(): void
    {
        $this->log->trace($this->language->t('log.running', __METHOD__));

        $this->validKeys = [];
        $this->invalidKeys = [];

        if (!file_exists($this->filePath)) {
            $this->loaded = true;
            return;
        }

        $content = @file_get_contents($this->filePath);

        if ($content === false) {
            $this->loaded = true;
            return;
        }

        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            $this->loaded = true;
            return;
        }

        foreach (($data['valid'] ?? []) as $entry) {
            if (!is_array($entry) || !isset($entry['login'], $entry['password'])) {
                continue;
            }

            $this->validKeys[] = Credential::fromArray($entry);
        }

        foreach (($data['invalid'] ?? []) as $entry) {
            if (!is_array($entry) || !isset($entry['login'], $entry['password'])) {
                continue;
            }

            $this->invalidKeys[] = Credential::fromArray($entry);
        }

        $this->loaded = true;
    }

    /**
     * @return Credential[]
     */
    public function getValidKeys(?string $version = null): array
    {
        $this->ensureLoaded();

        if ($version === null) {
            return $this->validKeys;
        }

        return array_filter(
            $this->validKeys,
            static fn(Credential $c): bool => $c->hasVersion($version)
        );
    }

    /**
     * @return Credential[]
     */
    public function getInvalidKeys(?string $version = null): array
    {
        $this->ensureLoaded();

        if ($version === null) {
            return $this->invalidKeys;
        }

        return array_filter(
            $this->invalidKeys,
            static fn(Credential $c): bool => $c->hasVersion($version)
        );
    }

    public function addValidKey(Credential $credential): void
    {
        $this->ensureLoaded();
        $this->log->trace($this->language->t('log.running', __METHOD__));

        $existingIndex = $this->findCredentialIndex($this->validKeys, $credential->login, $credential->password);

        if ($existingIndex !== null) {
            $existing = $this->validKeys[$existingIndex];
            $updated = $existing;

            foreach ($credential->versions as $version) {
                $updated = $updated->withVersion($version);
            }

            $this->validKeys[$existingIndex] = $updated;
        } else {
            $this->validKeys[] = $credential;
        }

        $this->dirty = true;
    }

    public function markKeyInvalid(string $login, string $password, string $version): void
    {
        $this->ensureLoaded();
        $this->log->trace($this->language->t('log.running', __METHOD__));

        $invalidIndex = $this->findCredentialIndex($this->invalidKeys, $login, $password);

        if ($invalidIndex !== null) {
            $this->invalidKeys[$invalidIndex] = $this->invalidKeys[$invalidIndex]->withVersion($version);
        } else {
            $this->invalidKeys[] = new Credential($login, $password, [$version]);
        }

        $this->dirty = true;
    }

    public function removeVersionFromValidKey(string $login, string $password, string $version): void
    {
        $this->ensureLoaded();
        $this->log->trace($this->language->t('log.running', __METHOD__));

        $index = $this->findCredentialIndex($this->validKeys, $login, $password);

        if ($index === null) {
            return;
        }

        $updated = $this->validKeys[$index]->withoutVersion($version);

        if (empty($updated->versions)) {
            unset($this->validKeys[$index]);
            $this->validKeys = array_values($this->validKeys);
        } else {
            $this->validKeys[$index] = $updated;
        }

        $this->dirty = true;
    }

    public function isValidKey(string $login, string $password, ?string $version = null): bool
    {
        $this->ensureLoaded();

        foreach ($this->validKeys as $credential) {
            if ($credential->login === $login && $credential->password === $password) {
                if ($version === null) {
                    return true;
                }

                return $credential->hasVersion($version);
            }
        }

        return false;
    }

    public function isInvalidKey(string $login, string $password, ?string $version = null): bool
    {
        $this->ensureLoaded();

        foreach ($this->invalidKeys as $credential) {
            if ($credential->login === $login && $credential->password === $password) {
                if ($version === null) {
                    return true;
                }

                return $credential->hasVersion($version);
            }
        }

        return false;
    }

    public function save(): void
    {
        if (!$this->dirty) {
            return;
        }

        $this->log->trace($this->language->t('log.running', __METHOD__));

        $data = [
            'valid' => array_map(
                static fn(Credential $c): array => $c->toArray(),
                array_values($this->validKeys)
            ),
            'invalid' => array_map(
                static fn(Credential $c): array => $c->toArray(),
                array_values($this->invalidKeys)
            ),
        ];

        $dir = dirname($this->filePath);
        Tools::ensureDirectory($dir);

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json !== false) {
            file_put_contents($this->filePath, $json);
        }

        $this->dirty = false;
    }

    /**
     * @param Credential[] $credentials
     */
    private function findCredentialIndex(array $credentials, string $login, string $password): ?int
    {
        foreach ($credentials as $index => $credential) {
            if ($credential->login === $login && $credential->password === $password) {
                return $index;
            }
        }

        return null;
    }
}

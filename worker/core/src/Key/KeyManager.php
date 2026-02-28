<?php

declare(strict_types=1);

namespace Nod32Mirror\Key;

use Nod32Mirror\Config\Config;
use Nod32Mirror\Contract\DownloaderInterface;
use Nod32Mirror\Contract\KeyStorageInterface;
use Nod32Mirror\Log\Log;
use Nod32Mirror\Log\Language;
use Nod32Mirror\ValueObject\Credential;
use Nod32Mirror\ValueObject\MirrorInfo;

final class KeyManager
{
    public function __construct(
        private readonly KeyStorageInterface $storage,
        private readonly DownloaderInterface $downloader,
        private readonly Config $config,
        private readonly Log $log,
        private readonly Language $language
    ) {
    }

    /**
     * Find a working key for the given version
     *
     * @param string[] $mirrors
     * @return array{credential: Credential, mirrors: MirrorInfo[]}|null
     */
    public function findWorkingKey(string $version, string $updateFilePath, array $mirrors): ?array
    {
        $this->log->trace($this->language->t('log.running', __METHOD__), $version);

        $validKeys = $this->storage->getValidKeys($version);

        if (empty($validKeys)) {
            $this->log->debug($this->language->t('mirror.keys_file_empty'), $version);
            return null;
        }

        foreach ($validKeys as $credential) {
            if ($this->storage->isInvalidKey($credential->login, $credential->password, $version)) {
                continue;
            }

            $this->log->debug(
                $this->language->t('mirror.validating_key_version', $credential->login, $credential->password, $version),
                $version
            );

            $result = $this->testKey($credential, $version, $updateFilePath, $mirrors);

            if ($result !== null) {
                $this->storage->addValidKey($credential->withVersion($version));
                $this->storage->save();

                $this->log->debug(
                    $this->language->t('mirror.found_valid_key', $credential->login, $credential->password),
                    $version
                );

                return $result;
            }

            $this->markKeyInvalid($credential, $version);
        }

        $this->log->debug($this->language->t('mirror.no_working_keys'), $version);
        return null;
    }

    /**
     * Test if a key works against mirrors
     *
     * @param string[] $mirrors
     * @return array{credential: Credential, mirrors: MirrorInfo[]}|null
     */
    public function testKey(Credential $credential, string $version, string $updateFilePath, array $mirrors): ?array
    {
        $this->log->trace($this->language->t('log.running', __METHOD__), $version);
        $this->log->debug(
            $this->language->t('mirror.testing_key', $credential->login, $credential->password),
            $version
        );

        $workingMirrors = [];

        foreach ($mirrors as $mirror) {
            $mirrorInfo = new MirrorInfo($mirror);
            $url = $mirrorInfo->buildUrl($updateFilePath);

            $result = $this->downloader->checkUrl($url, $credential);

            if ($result->isSuccessful()) {
                $workingMirrors[$mirror] = $mirrorInfo->withResponseTime((int) ($result->totalTime * 1000));
            }
        }

        if (empty($workingMirrors)) {
            return null;
        }

        // Sort by response time
        uasort($workingMirrors, static fn(MirrorInfo $a, MirrorInfo $b): int =>
            ($a->responseTime ?? PHP_INT_MAX) <=> ($b->responseTime ?? PHP_INT_MAX)
        );

        return [
            'credential' => $credential,
            'mirrors' => array_values($workingMirrors),
        ];
    }

    /**
     * Validate and add a new key
     */
    public function addKey(string $login, string $password, string $version): void
    {
        $this->log->trace($this->language->t('log.running', __METHOD__), $version);

        $credential = new Credential($login, $password, [$version]);
        $this->storage->addValidKey($credential);
        $this->storage->save();

        $this->log->debug(
            $this->language->t('mirror.found_valid_key', $login, $password),
            $version
        );
    }

    /**
     * Mark key as invalid for version
     */
    public function markKeyInvalid(Credential $credential, string $version): void
    {
        $this->log->trace($this->language->t('log.running', __METHOD__), $version);
        $this->log->debug(
            $this->language->t('mirror.invalid_key', $credential->login, $credential->password),
            $version
        );

        $this->storage->markKeyInvalid($credential->login, $credential->password, $version);

        $findConfig = $this->config->getOrDefault('find', []);
        if (!empty($findConfig['remove_invalid_keys'])) {
            $this->storage->removeVersionFromValidKey($credential->login, $credential->password, $version);
        }

        $this->storage->save();
    }

    /**
     * Check if key exists and is valid
     */
    public function isValidKey(string $login, string $password, string $version): bool
    {
        if ($this->storage->isInvalidKey($login, $password, $version)) {
            return false;
        }

        return $this->storage->isValidKey($login, $password, $version);
    }
}

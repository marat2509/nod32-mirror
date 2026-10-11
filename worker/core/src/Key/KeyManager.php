<?php

declare(strict_types=1);

namespace Nod32Mirror\Key;

use Nod32Mirror\Config\Config;
use Nod32Mirror\Contract\DownloaderInterface;
use Nod32Mirror\Contract\KeyStorageInterface;
use Nod32Mirror\Enum\StatusAction;
use Nod32Mirror\Enum\StatusPhase;
use Nod32Mirror\Log\Log;
use Nod32Mirror\Log\Language;
use Nod32Mirror\Parser\Parser;
use Nod32Mirror\Status\StatusReporter;
use Nod32Mirror\Tools;
use Nod32Mirror\ValueObject\Credential;
use Nod32Mirror\ValueObject\DownloadableFile;
use Nod32Mirror\ValueObject\MirrorInfo;

final class KeyManager
{
    public function __construct(
        private readonly KeyStorageInterface $storage,
        private readonly DownloaderInterface $downloader,
        private readonly Config $config,
        private readonly Log $log,
        private readonly Language $language,
        private readonly StatusReporter $statusReporter,
        private readonly Parser $parser
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
        $this->statusReporter->updateVersionAction(
            $version,
            StatusPhase::CheckingKey,
            StatusAction::TestStoredKey,
            $this->language->t('status.message.testing_stored_keys', $version)
        );

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
            $this->statusReporter->updateVersionAction(
                $version,
                StatusPhase::CheckingKey,
                StatusAction::TestStoredKey,
                $this->language->t('status.message.testing_key_mirror', $version),
                mirror: $mirror
            );

            $mirrorInfo = new MirrorInfo($mirror);
            $responseTime = $this->testMirror($mirrorInfo, $credential, $version, $updateFilePath);

            if ($responseTime !== null) {
                $workingMirrors[$mirror] = $mirrorInfo->withResponseTime($responseTime);
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

    private function testMirror(
        MirrorInfo $mirror,
        Credential $credential,
        string $version,
        string $updateFilePath
    ): ?int {
        $indexPath = $this->createTempPath('key_check_index', $version, 'ver');
        $indexResult = $this->downloader->downloadToFile(
            $mirror->buildUrl($updateFilePath),
            $indexPath,
            $credential
        );

        if (!$indexResult->isSuccessful()) {
            @unlink($indexPath);
            return null;
        }

        $files = $this->parseDownloadableFiles($indexPath);
        @unlink($indexPath);

        $file = $this->pickRandomFile($files);
        if ($file === null) {
            return null;
        }

        $samplePath = $this->createTempPath('key_check_file', $version, 'tmp');
        $fileResult = $this->downloader->downloadToFile(
            $mirror->buildUrl($file->path),
            $samplePath,
            $credential
        );
        @unlink($samplePath);

        if (!$fileResult->isSuccessful()) {
            return null;
        }

        if ($fileResult->downloadedBytes !== $file->size) {
            return null;
        }

        return (int) round(($indexResult->totalTime + $fileResult->totalTime) * 1000);
    }

    /**
     * @return DownloadableFile[]
     */
    private function parseDownloadableFiles(string $indexPath): array
    {
        $content = @file_get_contents($indexPath);

        if ($content === false || !preg_match_all('#\[\w+\][^\[]+#', $content, $matches)) {
            return [];
        }

        $parsed = $this->parser->parseUpdateFile($matches[0]);

        return $parsed['files'];
    }

    /**
     * @param DownloadableFile[] $files
     */
    private function pickRandomFile(array $files): ?DownloadableFile
    {
        $files = array_values(array_filter(
            $files,
            static fn(DownloadableFile $file): bool => $file->path !== '' && $file->size >= 0
        ));

        if (empty($files)) {
            return null;
        }

        return $files[array_rand($files)];
    }

    private function createTempPath(string $prefix, string $version, string $extension): string
    {
        return Tools::ds(
            $this->config->getTmpDir(),
            sprintf('%s_%s_%s.%s', $prefix, md5($version), bin2hex(random_bytes(6)), $extension)
        );
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

        if (!empty($this->config->getOrDefault('credentials.discovery.remove_invalid', false))) {
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

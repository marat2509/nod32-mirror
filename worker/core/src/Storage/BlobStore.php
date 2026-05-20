<?php

declare(strict_types=1);

namespace Nod32Mirror\Storage;

use Nod32Mirror\FileSystem\SafeFileOperations;
use Nod32Mirror\Log\Log;
use Nod32Mirror\Tools;

final class BlobStore
{
    private string $runId;

    public function __construct(
        private readonly StorageConfig $storageConfig,
        private readonly SafeFileOperations $fileOps,
        private readonly Log $log
    ) {
        $this->runId = gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
    }

    public function getRunId(): string
    {
        return $this->runId;
    }

    public function getDownloadTmpRoot(): string
    {
        return Tools::ds($this->storageConfig->getTmpDir(), $this->runId, 'downloads');
    }

    public function getPublishTmpRoot(): string
    {
        return Tools::ds($this->storageConfig->getTmpDir(), $this->runId, 'publish');
    }

    public function getHashAlgorithm(): string
    {
        return $this->storageConfig->getHashAlgorithm();
    }

    public function isHashAlgorithmAvailable(): bool
    {
        return in_array($this->getHashAlgorithm(), hash_algos(), true);
    }

    public function hashFile(string $path): ?string
    {
        if (!$this->isHashAlgorithmAvailable() || !is_file($path)) {
            return null;
        }

        $hash = hash_file($this->getHashAlgorithm(), $path);
        if ($hash === false) {
            return null;
        }

        return strtolower(trim((string) $hash));
    }

    public function getBlobPath(string $hash): string
    {
        $hash = strtolower(trim($hash));
        $prefix1 = substr($hash, 0, 2);
        $prefix2 = substr($hash, 2, 2);

        return Tools::ds(
            $this->storageConfig->getBlobDir(),
            $this->getHashAlgorithm(),
            $prefix1,
            $prefix2,
            $hash
        );
    }

    public function ensureBlob(string $sourcePath, string $hash, int $size): ?string
    {
        $hash = strtolower(trim($hash));
        if ($hash === '' || !is_file($sourcePath)) {
            return null;
        }

        $blobPath = $this->getBlobPath($hash);

        if (is_file($blobPath)) {
            if (!$this->verifyBlob($blobPath, $hash, $size)) {
                $this->quarantine($blobPath, 'corrupt-blob');
                return null;
            }

            return $blobPath;
        }

        $actualSize = (int) (filesize($sourcePath) ?: 0);
        if ($actualSize !== $size) {
            return null;
        }

        $actualHash = $this->hashFile($sourcePath);
        if ($actualHash !== $hash) {
            return null;
        }

        $this->fileOps->createDirectory(dirname($blobPath));
        $stagingPath = $blobPath . '.stage-' . bin2hex(random_bytes(6));

        if (!@rename($sourcePath, $stagingPath)) {
            if (!@copy($sourcePath, $stagingPath)) {
                return null;
            }
        }

        if (!$this->verifyBlob($stagingPath, $hash, $size)) {
            $this->quarantine($stagingPath, 'staging-verify-failed');
            return null;
        }

        if (@rename($stagingPath, $blobPath)) {
            $this->log->debug(sprintf('Storage blob created: %s', $blobPath));
            return $blobPath;
        }

        if (is_file($blobPath) && $this->verifyBlob($blobPath, $hash, $size)) {
            $this->fileOps->deleteFile($stagingPath);
            return $blobPath;
        }

        $this->quarantine($stagingPath, 'blob-finalize-failed');
        return null;
    }

    public function verifyBlob(string $blobPath, string $hash, int $size): bool
    {
        if (!is_file($blobPath)) {
            return false;
        }

        clearstatcache(true, $blobPath);
        if ((int) (filesize($blobPath) ?: 0) !== $size) {
            return false;
        }

        return $this->hashFile($blobPath) === strtolower(trim($hash));
    }

    public function quarantine(string $path, string $reason): ?string
    {
        if (!file_exists($path)) {
            return null;
        }

        $this->fileOps->createDirectory($this->storageConfig->getQuarantineDir());
        $target = Tools::ds(
            $this->storageConfig->getQuarantineDir(),
            gmdate('YmdHis') . '-' . preg_replace('/[^a-z0-9_.-]+/i', '-', $reason) . '-' . basename($path)
        );

        if (@rename($path, $target)) {
            $this->log->warning(sprintf('Storage quarantined file: %s -> %s', $path, $target));
            return $target;
        }

        return null;
    }

    public function cleanupRunTmp(): void
    {
        $runTmp = Tools::ds($this->storageConfig->getTmpDir(), $this->runId);
        Tools::clearDirectory($runTmp);
        @rmdir($runTmp);
    }
}

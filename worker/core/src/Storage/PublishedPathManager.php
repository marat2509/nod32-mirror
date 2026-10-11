<?php

declare(strict_types=1);

namespace Nod32Mirror\Storage;

use Nod32Mirror\Enum\StorageLinkMethod;
use Nod32Mirror\FileSystem\SafeFileOperations;

final class PublishedPathManager
{
    public function __construct(
        private readonly StorageConfig $storageConfig,
        private readonly BlobStore $blobStore,
        private readonly SafeFileOperations $fileOps
    ) {
    }

    public function publishFromBlob(string $blobPath, string $targetPath, string $hash, int $size): bool
    {
        if (!is_file($blobPath) || !$this->blobStore->verifyBlob($blobPath, $hash, $size)) {
            return false;
        }

        if (is_file($targetPath)) {
            clearstatcache(true, $targetPath);
            $targetSize = (int) (filesize($targetPath) ?: 0);
            if ($targetSize === $size && $this->blobStore->hashFile($targetPath) === strtolower(trim($hash))) {
                return true;
            }
        }

        $this->fileOps->createDirectory(dirname($targetPath));
        $tempPath = $this->createPublishTempPath($targetPath);

        if (!$this->createProjection($blobPath, $tempPath)) {
            $this->fileOps->deleteFile($tempPath);
            return false;
        }

        if (!is_file($tempPath) || (int) (filesize($tempPath) ?: 0) !== $size) {
            $this->fileOps->deleteFile($tempPath);
            return false;
        }

        if ($this->blobStore->hashFile($tempPath) !== strtolower(trim($hash))) {
            $this->fileOps->deleteFile($tempPath);
            return false;
        }

        if (@rename($tempPath, $targetPath)) {
            return true;
        }

        $this->fileOps->deleteFile($tempPath);
        return false;
    }

    public function getLinkMethod(): StorageLinkMethod
    {
        return $this->storageConfig->getLinkMethod();
    }

    private function createProjection(string $sourcePath, string $targetPath): bool
    {
        return match ($this->storageConfig->getLinkMethod()) {
            StorageLinkMethod::Hardlink => $this->fileOps->createHardlink($sourcePath, $targetPath),
            StorageLinkMethod::Softlink => $this->fileOps->createSymlink($sourcePath, $targetPath),
            StorageLinkMethod::Copy => $this->fileOps->copyFile($sourcePath, $targetPath),
        };
    }

    private function createPublishTempPath(string $targetPath): string
    {
        do {
            $tempPath = $targetPath . '.publish-' . bin2hex(random_bytes(6)) . '.tmp';
        } while (file_exists($tempPath));

        return $tempPath;
    }
}

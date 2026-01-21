<?php

declare(strict_types=1);

namespace Nod32Mirror\ValueObject;

/**
 * Result of cleanup operation
 */
final readonly class CleanupResult
{
    /**
     * @param string[] $deletedFiles List of deleted file paths
     * @param string[] $deletedFolders List of deleted folder paths
     * @param string[] $failedFiles Files that couldn't be deleted
     * @param string[] $failedFolders Folders that couldn't be deleted
     */
    public function __construct(
        public int $deletedFilesCount,
        public int $deletedFoldersCount,
        public array $deletedFiles = [],
        public array $deletedFolders = [],
        public array $failedFiles = [],
        public array $failedFolders = []
    ) {
    }

    public static function empty(): self
    {
        return new self(0, 0);
    }

    public function hasChanges(): bool
    {
        return $this->deletedFilesCount > 0 || $this->deletedFoldersCount > 0;
    }

    public function hasFailures(): bool
    {
        return count($this->failedFiles) > 0 || count($this->failedFolders) > 0;
    }

    public function merge(self $other): self
    {
        return new self(
            deletedFilesCount: $this->deletedFilesCount + $other->deletedFilesCount,
            deletedFoldersCount: $this->deletedFoldersCount + $other->deletedFoldersCount,
            deletedFiles: array_merge($this->deletedFiles, $other->deletedFiles),
            deletedFolders: array_merge($this->deletedFolders, $other->deletedFolders),
            failedFiles: array_merge($this->failedFiles, $other->failedFiles),
            failedFolders: array_merge($this->failedFolders, $other->failedFolders)
        );
    }
}

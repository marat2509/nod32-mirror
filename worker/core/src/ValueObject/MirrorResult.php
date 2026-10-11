<?php

declare(strict_types=1);

namespace Nod32Mirror\ValueObject;

/**
 * Result of mirror synchronization operation
 */
final readonly class MirrorResult
{
    /**
     * @param string[] $platformsFound
     */
    public function __construct(
        public bool $updated,
        public int $totalDownloads,
        public ?int $totalSize,
        public ?float $averageSpeed,
        public array $platformsFound = [],
        public int $deletedFiles = 0,
        public int $deletedFolders = 0,
        public int $linkedFiles = 0
    ) {
    }

    public static function empty(): self
    {
        return new self(
            updated: false,
            totalDownloads: 0,
            totalSize: null,
            averageSpeed: null
        );
    }

    public static function upToDate(?int $totalSize = null): self
    {
        return new self(
            updated: false,
            totalDownloads: 0,
            totalSize: $totalSize,
            averageSpeed: null
        );
    }

    /**
     * Convert to legacy array format for backward compatibility
     *
     * @return array{totalSize: ?int, totalDownloads: int, averageSpeed: ?float}
     */
    public function toLegacyArray(): array
    {
        return [
            'totalSize' => $this->totalSize,
            'totalDownloads' => $this->totalDownloads,
            'averageSpeed' => $this->averageSpeed,
        ];
    }
}

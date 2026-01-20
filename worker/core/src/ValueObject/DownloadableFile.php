<?php

declare(strict_types=1);

namespace Nod32Mirror\ValueObject;

final readonly class DownloadableFile
{
    public function __construct(
        public string $path,
        public int $size,
        public ?string $platform = null,
        public ?string $version = null,
        public ?int $versionId = null
    ) {
    }

    /**
     * @param array{file?: string, size?: int|string, platform?: string, version?: string, versionid?: int|string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            path: $data['file'] ?? '',
            size: (int) ($data['size'] ?? 0),
            platform: $data['platform'] ?? null,
            version: $data['version'] ?? null,
            versionId: isset($data['versionid']) ? (int) $data['versionid'] : null
        );
    }

    /**
     * @return array{file: string, size: int, platform: ?string, version: ?string, versionid: ?int}
     */
    public function toArray(): array
    {
        return [
            'file' => $this->path,
            'size' => $this->size,
            'platform' => $this->platform,
            'version' => $this->version,
            'versionid' => $this->versionId,
        ];
    }

    public function getFilename(): string
    {
        return basename($this->path);
    }

    public function getDirectory(): string
    {
        return dirname($this->path);
    }

    public function isSmallEnoughForTest(int $maxSize = 1048576): bool
    {
        return $this->size <= $maxSize;
    }
}

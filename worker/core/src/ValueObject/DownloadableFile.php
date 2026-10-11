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
     * @param array{
     *     file?: string,
     *     size?: int|string,
     *     platform?: string,
     *     architecture?: string,
     *     arch?: string,
     *     version?: string,
     *     versionid?: int|string
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $normalizedData = array_change_key_case($data, CASE_LOWER);
        $platform = $normalizedData['platform'] ?? $normalizedData['architecture'] ?? $normalizedData['arch'] ?? null;

        return new self(
            path: (string) ($normalizedData['file'] ?? ''),
            size: (int) ($normalizedData['size'] ?? 0),
            platform: is_string($platform) && $platform !== '' ? $platform : null,
            version: isset($normalizedData['version']) ? (string) $normalizedData['version'] : null,
            versionId: isset($normalizedData['versionid']) ? (int) $normalizedData['versionid'] : null
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

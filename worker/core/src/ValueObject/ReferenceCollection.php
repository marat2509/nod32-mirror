<?php

declare(strict_types=1);

namespace Nod32Mirror\ValueObject;

final class ReferenceCollection
{
    /** @var array<string, true> */
    private array $paths = [];

    /** @var array<string, true> */
    private array $indexPaths = [];

    /** @var array<string, array<string, array<string, true>>> */
    private array $pathVersionChannels = [];

    /** @var string[] */
    private array $errors = [];

    private bool $complete = true;

    public function addPath(string $path): void
    {
        $path = $this->normalize($path);
        if ($path !== '') {
            $this->paths[$path] = true;
        }
    }

    public function addIndexPath(string $path): void
    {
        $path = $this->normalize($path);
        if ($path === '') {
            return;
        }

        $this->paths[$path] = true;
        $this->indexPaths[$path] = true;
    }

    public function addVersionChannel(string $path, string $versionId, string $channel): void
    {
        $path = $this->normalize($path);
        $versionId = trim($versionId);
        $channel = trim($channel) !== '' ? trim($channel) : 'default';

        if ($path === '' || $versionId === '') {
            return;
        }

        $this->paths[$path] = true;
        $this->pathVersionChannels[$path] ??= [];
        $this->pathVersionChannels[$path][$versionId] ??= [];
        $this->pathVersionChannels[$path][$versionId][$channel] = true;
    }

    public function addError(string $error): void
    {
        $this->complete = false;
        $this->errors[] = $error;
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }

    /**
     * @return string[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @return string[]
     */
    public function getPaths(): array
    {
        return array_keys($this->paths);
    }

    /**
     * @return array<string, true>
     */
    public function getPathSet(): array
    {
        return $this->paths;
    }

    /**
     * @return string[]
     */
    public function getIndexPaths(): array
    {
        return array_keys($this->indexPaths);
    }

    public function hasPath(string $path): bool
    {
        return isset($this->paths[$this->normalize($path)]);
    }

    /**
     * @return array<string, string[]>
     */
    public function getVersionChannelsForPath(string $path): array
    {
        $path = $this->normalize($path);
        $result = [];

        foreach ($this->pathVersionChannels[$path] ?? [] as $versionId => $channels) {
            $result[$versionId] = array_keys($channels);
            sort($result[$versionId]);
        }

        ksort($result);
        return $result;
    }

    private function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        return ltrim($path, '/');
    }
}

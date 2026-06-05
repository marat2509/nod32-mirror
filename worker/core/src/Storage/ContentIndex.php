<?php

declare(strict_types=1);

namespace Nod32Mirror\Storage;

use Nod32Mirror\FileSystem\SafeFileOperations;
use Nod32Mirror\Log\Language;
use Nod32Mirror\Log\Log;
use Nod32Mirror\ValueObject\ReferenceCollection;

final class ContentIndex
{
    /** @var array<string, mixed> */
    private array $index = [];

    private bool $loaded = false;

    private ?string $lastSaveError = null;

    public function __construct(
        private readonly SafeFileOperations $fileOps,
        private readonly Log $log,
        private readonly Language $language
    ) {
        $this->reset('sha256');
    }

    public function load(string $path, string $hashAlgorithm): void
    {
        $this->reset($hashAlgorithm);

        if (!is_file($path)) {
            return;
        }

        $content = $this->fileOps->readFile($path, false);
        if ($content === null) {
            return;
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            $this->log->warning($this->language->t('storage.index_invalid_json', $path));
            return;
        }

        $storedAlgorithm = is_string($decoded['hash_algorithm'] ?? null)
            ? strtolower(trim((string) $decoded['hash_algorithm']))
            : $hashAlgorithm;
        if ($storedAlgorithm !== $hashAlgorithm) {
            $this->log->warning($this->language->t('storage.index_hash_algorithm_mismatch', $storedAlgorithm, $hashAlgorithm));
            return;
        }

        $this->index = [
            'hash_algorithm' => $hashAlgorithm,
            'updated_at' => $this->normalizeTimestamp($decoded['updated_at'] ?? null),
            'hashes' => is_array($decoded['hashes'] ?? null) ? $decoded['hashes'] : [],
            'published' => is_array($decoded['published'] ?? null) ? $decoded['published'] : [],
        ];
        $this->loaded = true;
    }

    public function save(string $path): void
    {
        $this->index['updated_at'] = self::now();
        $this->lastSaveError = null;

        $this->fileOps->createDirectory(dirname($path));
        $tmpPath = $path . '.tmp-' . bin2hex(random_bytes(6));
        if (!$this->writeIndexJsonFile($tmpPath)) {
            $this->fileOps->deleteFile($tmpPath);
            $this->log->warning($this->language->t(
                'storage.index_json_encoding_failed',
                $this->lastSaveError ?? json_last_error_msg()
            ));
            return;
        }

        if (!@rename($tmpPath, $path)) {
            $this->fileOps->deleteFile($tmpPath);
            $this->log->warning($this->language->t('storage.index_save_failed', $path));
        }
    }

    private function writeIndexJsonFile(string $path): bool
    {
        $handle = @fopen($path, 'wb');
        if ($handle === false) {
            $this->lastSaveError = 'failed to open temporary index file';
            return false;
        }

        $ok = $this->writeBytes($handle, "{\n")
            && $this->writeProperty($handle, 1, 'hash_algorithm', $this->getHashAlgorithm(), true)
            && $this->writeProperty($handle, 1, 'updated_at', (string) ($this->index['updated_at'] ?? self::now()), true)
            && $this->writeObjectSection($handle, 1, 'hashes', $this->getHashes(), true)
            && $this->writeObjectSection($handle, 1, 'published', $this->getPublished(), false)
            && $this->writeBytes($handle, "}\n");

        if (!@fclose($handle)) {
            $this->lastSaveError = 'failed to close temporary index file';
            return false;
        }

        return $ok;
    }

    /**
     * @param resource $handle
     */
    private function writeProperty($handle, int $indentLevel, string $key, mixed $value, bool $comma): bool
    {
        $encodedKey = $this->encodeJson($key);
        $encodedValue = $this->encodeJson($value);

        if ($encodedKey === null || $encodedValue === null) {
            return false;
        }

        return $this->writeBytes(
            $handle,
            str_repeat("\t", $indentLevel) . $encodedKey . ': ' . $encodedValue . ($comma ? ',' : '') . "\n"
        );
    }

    /**
     * @param resource $handle
     * @param array<string, mixed> $entries
     */
    private function writeObjectSection($handle, int $indentLevel, string $key, array $entries, bool $comma): bool
    {
        $encodedKey = $this->encodeJson($key);
        if ($encodedKey === null) {
            return false;
        }

        if (!$this->writeBytes($handle, str_repeat("\t", $indentLevel) . $encodedKey . ": {\n")) {
            return false;
        }

        $entryCount = count($entries);
        $index = 0;
        foreach ($entries as $entryKey => $entryValue) {
            $index++;
            $encodedEntryKey = $this->encodeJson((string) $entryKey);
            $encodedEntryValue = $this->encodeJson($entryValue);

            if ($encodedEntryKey === null || $encodedEntryValue === null) {
                return false;
            }

            if (!$this->writeBytes(
                $handle,
                str_repeat("\t", $indentLevel + 1)
                . $encodedEntryKey
                . ': '
                . $encodedEntryValue
                . ($index < $entryCount ? ',' : '')
                . "\n"
            )) {
                return false;
            }
        }

        return $this->writeBytes($handle, str_repeat("\t", $indentLevel) . '}' . ($comma ? ',' : '') . "\n");
    }

    private function encodeJson(mixed $value): ?string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            $this->lastSaveError = json_last_error_msg();
            return null;
        }

        return $encoded;
    }

    /**
     * @param resource $handle
     */
    private function writeBytes($handle, string $content): bool
    {
        $length = strlen($content);
        $written = 0;

        while ($written < $length) {
            $result = @fwrite($handle, substr($content, $written));

            if ($result === false || $result === 0) {
                $this->lastSaveError = 'failed to write temporary index file';
                return false;
            }

            $written += $result;
        }

        return true;
    }

    public function wasLoaded(): bool
    {
        return $this->loaded;
    }

    public function getHashAlgorithm(): string
    {
        return (string) ($this->index['hash_algorithm'] ?? 'sha256');
    }

    public function setHashAlgorithm(string $hashAlgorithm): void
    {
        $this->index['hash_algorithm'] = $hashAlgorithm;
    }

    public function recordPublished(
        string $relativePath,
        string $hash,
        int $size,
        string $versionId,
        string $channel,
        string $linkMethod,
        string $blobPath
    ): void {
        $relativePath = $this->normalizePath($relativePath);
        $hash = strtolower(trim($hash));
        $now = self::now();

        if ($relativePath === '' || $hash === '') {
            return;
        }

        $existingHash = $this->index['hashes'][$hash] ?? [];
        $createdAt = is_array($existingHash)
            ? $this->normalizeTimestamp($existingHash['created_at'] ?? null)
            : $now;

        $this->index['published'][$relativePath] = [
            'hash' => $hash,
            'size' => $size,
            'version_id' => $versionId,
            'channel' => $channel !== '' ? $channel : 'default',
            'link_method' => $linkMethod,
            'updated_at' => $now,
        ];

        $this->index['hashes'][$hash] = [
            'hash' => $hash,
            'size' => $size,
            'blob_path' => $blobPath,
            'published_paths' => [],
            'version_ids' => [],
            'created_at' => $createdAt,
            'updated_at' => $now,
        ];

        $this->rebuildHashEntries();
    }

    public function getPublishedHash(string $relativePath): ?string
    {
        $relativePath = $this->normalizePath($relativePath);
        $hash = $this->index['published'][$relativePath]['hash'] ?? null;

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    public function getPublishedSize(string $relativePath): ?int
    {
        $relativePath = $this->normalizePath($relativePath);
        $size = $this->index['published'][$relativePath]['size'] ?? null;

        return is_numeric($size) ? (int) $size : null;
    }

    public function getBlobPath(string $hash): ?string
    {
        $hash = strtolower(trim($hash));
        $blobPath = $this->index['hashes'][$hash]['blob_path'] ?? null;

        return is_string($blobPath) && $blobPath !== '' ? $blobPath : null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getPublished(): array
    {
        return is_array($this->index['published'] ?? null) ? $this->index['published'] : [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getHashes(): array
    {
        return is_array($this->index['hashes'] ?? null) ? $this->index['hashes'] : [];
    }

    public function removePublished(string $relativePath): void
    {
        unset($this->index['published'][$this->normalizePath($relativePath)]);
        $this->rebuildHashEntries();
    }

    public function removeHash(string $hash): void
    {
        unset($this->index['hashes'][strtolower(trim($hash))]);
    }

    public function syncVersionRefs(ReferenceCollection $references): void
    {
        $this->rebuildHashEntries($references);
    }

    private function rebuildHashEntries(?ReferenceCollection $references = null): void
    {
        $existing = $this->getHashes();
        $hashes = [];

        foreach ($this->getPublished() as $path => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $hash = is_string($entry['hash'] ?? null) ? strtolower(trim($entry['hash'])) : '';
            if ($hash === '') {
                continue;
            }

            $current = $existing[$hash] ?? [];
            $now = self::now();

            if (!isset($hashes[$hash])) {
                $hashes[$hash] = [
                    'hash' => $hash,
                    'size' => (int) ($entry['size'] ?? ($current['size'] ?? 0)),
                    'blob_path' => (string) ($current['blob_path'] ?? ''),
                    'published_paths' => [],
                    'version_ids' => [],
                    'created_at' => $this->normalizeTimestamp($current['created_at'] ?? null),
                    'updated_at' => $now,
                ];
            }

            $normalizedPath = $this->normalizePath((string) $path);
            if (!in_array($normalizedPath, $hashes[$hash]['published_paths'], true)) {
                $hashes[$hash]['published_paths'][] = $normalizedPath;
            }

            $versionChannels = $references?->getVersionChannelsForPath($normalizedPath) ?? [];
            foreach ($versionChannels as $versionId => $channels) {
                $hashes[$hash]['version_ids'][$versionId] ??= [];
                foreach ($channels as $channel) {
                    if (!in_array($channel, $hashes[$hash]['version_ids'][$versionId], true)) {
                        $hashes[$hash]['version_ids'][$versionId][] = $channel;
                    }
                }
            }
        }

        foreach ($hashes as &$entry) {
            sort($entry['published_paths']);
            ksort($entry['version_ids']);
            foreach ($entry['version_ids'] as &$channels) {
                sort($channels);
            }
            unset($channels);
        }
        unset($entry);

        ksort($hashes);
        $this->index['hashes'] = $hashes;
    }

    private function reset(string $hashAlgorithm): void
    {
        $this->index = [
            'hash_algorithm' => $hashAlgorithm,
            'updated_at' => self::now(),
            'hashes' => [],
            'published' => [],
        ];
        $this->loaded = false;
    }

    public static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s+00:00');
    }

    private function normalizeTimestamp(mixed $value): string
    {
        return is_string($value) && $value !== '' ? $value : self::now();
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        return ltrim($path, '/');
    }
}

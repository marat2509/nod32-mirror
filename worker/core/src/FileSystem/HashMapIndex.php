<?php

declare(strict_types=1);

namespace Nod32Mirror\FileSystem;

use Nod32Mirror\Log\Language;
use Nod32Mirror\Log\Log;
use Nod32Mirror\Tools;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * In-memory index of file hashes stored in data/hash-map.json
 */
final class HashMapIndex
{
    /**
     * @var array{files: array<string, array{hash: array<string, string>, size: int, provides: array{versions: string[], files: string[]}}>, updated_at: int, algorithm?: string}
     */
    private array $map = [
        'files' => [],
        'updated_at' => 0,
    ];

    /** @var array<string, string[]> */
    private array $hashIndex = [];

    private string $hashAlgorithm;
    private bool $hashAlgorithmAvailable;
    private bool $loadedFromDisk = false;

    public function __construct(
        private readonly SafeFileOperations $fileOps,
        private readonly Log $log,
        private readonly Language $language,
        string $hashAlgorithm = 'xxh3'
    ) {
        $hashAlgorithm = strtolower(trim($hashAlgorithm));
        $this->hashAlgorithm = $hashAlgorithm !== '' ? $hashAlgorithm : 'xxh3';
        $this->hashAlgorithmAvailable = in_array($this->hashAlgorithm, hash_algos(), true);
    }

    public function isAvailable(): bool
    {
        return $this->hashAlgorithmAvailable;
    }

    public function getHashAlgorithm(): string
    {
        return $this->hashAlgorithm;
    }

    public function wasLoaded(): bool
    {
        return $this->loadedFromDisk;
    }

    public function load(string $path): void
    {
        $this->log->trace($this->language->t('log.running', __METHOD__));

        if (!file_exists($path)) {
            return;
        }

        $content = $this->fileOps->readFile($path, false);
        if ($content === null) {
            return;
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return;
        }

        $mapAlgorithm = is_string($decoded['algorithm'] ?? null)
            ? strtolower(trim((string) $decoded['algorithm']))
            : null;

        if (!empty($mapAlgorithm) && $mapAlgorithm !== $this->hashAlgorithm) {
            $this->log->warning(
                $this->language->t('filesystem.hash_map_algorithm_mismatch', $mapAlgorithm, $this->hashAlgorithm)
            );
            $this->map = [
                'files' => [],
                'updated_at' => (int) ($decoded['updated_at'] ?? 0),
                'algorithm' => $this->hashAlgorithm,
            ];
            $this->hashIndex = [];
            $this->loadedFromDisk = false;
            return;
        }

        $files = $decoded['files'] ?? [];
        if (!is_array($files)) {
            $files = [];
        }

        foreach ($files as $path => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (!isset($entry['hash']) || !is_array($entry['hash'])) {
                $files[$path]['hash'] = [];
            }

            if (isset($entry['versions']) && !isset($entry['provides'])) {
                $files[$path]['provides'] = $this->buildProvides(
                    is_array($entry['versions']) ? $entry['versions'] : [],
                    []
                );
                unset($files[$path]['versions']);
            }

            if (!isset($files[$path]['provides'])) {
                $files[$path]['provides'] = ['versions' => [], 'files' => []];
            }
        }

        $this->map = [
            'files' => $files,
            'updated_at' => (int) ($decoded['updated_at'] ?? 0),
            'algorithm' => $this->hashAlgorithm,
        ];

        $this->loadedFromDisk = true;

        $this->rebuildHashIndex();

        $this->log->debug($this->language->t('filesystem.hash_map_loaded', count($this->map['files'])));
    }

    public function save(string $path): void
    {
        $this->log->trace($this->language->t('log.running', __METHOD__));
        $this->map['algorithm'] = $this->hashAlgorithm;
        $this->map['updated_at'] = time();

        $json = json_encode($this->map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $this->log->warning('Hash map JSON encoding failed: ' . json_last_error_msg());
            return;
        }

        $this->fileOps->writeFile($path, $json . PHP_EOL);
        $this->log->debug($this->language->t('filesystem.hash_map_saved', count($this->map['files'])));
    }

    /**
     * Rebuild index from webDir (rehash all files)
     *
     * @param string $webDir
     * @param string[] $excludePatterns Relative paths or glob patterns
     */
    public function rebuildFromWebDir(string $webDir, array $excludePatterns = []): void
    {
        $this->log->trace($this->language->t('log.running', __METHOD__));
        $this->map['files'] = [];
        $this->hashIndex = [];

        if (!$this->hashAlgorithmAvailable) {
            $this->log->warning($this->language->t('filesystem.hash_algorithm_missing', $this->hashAlgorithm));
            return;
        }

        if (!is_dir($webDir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($webDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $fileObject) {
            if ($fileObject->isDir()) {
                continue;
            }

            $absolutePath = $fileObject->getPathname();
            $relativePath = $this->toRelativePath($webDir, $absolutePath);

            if ($this->isExcluded($relativePath, $excludePatterns)) {
                continue;
            }

            $hash = $this->hashFile($absolutePath);
            if ($hash === null) {
                continue;
            }

            $size = $fileObject->getSize();
            $versions = $this->extractVersions($relativePath);
            $provides = $this->buildProvides($versions, []);

            $this->updateEntry($relativePath, $hash, $size, $provides);
        }

        $this->log->debug($this->language->t('filesystem.hash_map_rebuilt', $this->hashAlgorithm, count($this->map['files'])));
    }

    public function getHashFor(string $relativePath): ?string
    {
        $relativePath = $this->normalizeRelativePath($relativePath);
        $hash = $this->map['files'][$relativePath]['hash'][$this->hashAlgorithm] ?? null;
        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    /**
     * Find an existing file path by hash
     */
    public function findPathByHash(string $hash, ?callable $filter = null): ?string
    {
        $paths = $this->hashIndex[$hash] ?? [];

        foreach ($paths as $path) {
            if ($filter === null || $filter($path) === true) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Update a single entry from an existing file
     */
    public function updateFileEntry(string $webDir, string $relativePath): ?string
    {
        if (!$this->hashAlgorithmAvailable) {
            return null;
        }

        $relativePath = $this->normalizeRelativePath($relativePath);
        $absolutePath = Tools::ds($webDir, $relativePath);

        if (!is_file($absolutePath)) {
            return null;
        }

        $hash = $this->hashFile($absolutePath);
        if ($hash === null) {
            return null;
        }

        $stat = $this->fileOps->stat($absolutePath);
        $size = (int) ($stat['size'] ?? 0);

        $this->updateEntry($relativePath, $hash, $size, null);

        return $hash;
    }

    /**
     * Update a single entry with a known hash (no file read)
     */
    public function updateEntryFromHash(string $relativePath, string $hash, int $size): void
    {
        if (!$this->hashAlgorithmAvailable || $hash === '') {
            return;
        }

        $this->updateEntry($relativePath, $hash, $size, null);
    }

    /**
     * Add provide metadata for a file
     */
    public function addProvides(string $relativePath, ?string $version = null, ?string $providerFile = null): void
    {
        $this->log->trace($this->language->t('log.running', __METHOD__));
        $relativePath = $this->normalizeRelativePath($relativePath);

        if (!isset($this->map['files'][$relativePath])) {
            $this->map['files'][$relativePath] = [
                'hash' => [$this->hashAlgorithm => ''],
                'size' => 0,
                'provides' => ['versions' => [], 'files' => []],
            ];
        }

        $provides = $this->map['files'][$relativePath]['provides'] ?? ['versions' => [], 'files' => []];

        if ($version !== null && $version !== '' && !in_array($version, $provides['versions'], true)) {
            $provides['versions'][] = $version;
        }

        if ($providerFile !== null && $providerFile !== '' && !in_array($providerFile, $provides['files'], true)) {
            $provides['files'][] = $providerFile;
        }

        $this->map['files'][$relativePath]['provides'] = $provides;
    }

    public function resetProvides(): void
    {
        $this->log->trace($this->language->t('log.running', __METHOD__));
        foreach ($this->map['files'] as $path => $entry) {
            $this->map['files'][$path]['provides'] = ['versions' => [], 'files' => []];
        }

        $this->log->debug($this->language->t('filesystem.hash_map_provides_reset', count($this->map['files'])));
    }

    /**
     * Find files on disk that are not referenced in the current provides list
     *
     * @param string $webDir
     * @param string[] $excludePatterns
     * @return string[] Relative paths of extra files
     */
    public function findExtraFiles(string $webDir, array $excludePatterns = []): array
    {
        $this->log->trace($this->language->t('log.running', __METHOD__));
        $extras = [];

        if (!is_dir($webDir)) {
            return $extras;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($webDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $fileObject) {
            if ($fileObject->isDir()) {
                continue;
            }

            $absolutePath = $fileObject->getPathname();
            $relativePath = $this->toRelativePath($webDir, $absolutePath);

            if ($this->isExcluded($relativePath, $excludePatterns)) {
                continue;
            }

            $entry = $this->map['files'][$relativePath] ?? null;
            if ($entry === null || $this->isProvidesEmpty($entry['provides'] ?? null)) {
                $extras[] = $relativePath;
            }
        }

        return $extras;
    }

    /**
     * Delete extra files and drop their entries
     *
     * @param string $webDir
     * @param string[] $excludePatterns
     * @return string[] Relative paths of deleted files
     */
    public function deleteExtraFiles(string $webDir, array $excludePatterns = []): array
    {
        $this->log->trace($this->language->t('log.running', __METHOD__));
        $deleted = [];
        $extras = $this->findExtraFiles($webDir, $excludePatterns);

        foreach ($extras as $relativePath) {
            $absolutePath = Tools::ds($webDir, $relativePath);
            if ($this->fileOps->deleteFile($absolutePath)) {
                $deleted[] = $relativePath;
            }

            $this->removeEntry($relativePath);
        }

        if (!empty($deleted)) {
            $this->log->info($this->language->t('filesystem.hash_map_removed_extra', count($deleted)));
        }

        return $deleted;
    }

    /**
     * Get hash for an existing file path
     */
    public function hashExistingFile(string $absolutePath): ?string
    {
        if (!$this->hashAlgorithmAvailable || !is_file($absolutePath)) {
            return null;
        }

        return $this->hashFile($absolutePath);
    }

    /**
     * @param string[] $excludePatterns
     */
    private function isExcluded(string $relativePath, array $excludePatterns): bool
    {
        if (empty($excludePatterns)) {
            return false;
        }

        foreach ($excludePatterns as $pattern) {
            $pattern = $this->normalizeRelativePath((string) $pattern);
            if ($pattern === '') {
                continue;
            }

            if ($relativePath === $pattern) {
                return true;
            }

            if (str_ends_with($pattern, '/')) {
                if (str_starts_with($relativePath, $pattern)) {
                    return true;
                }
            }

            if (strpbrk($pattern, '*?[]') !== false) {
                if ($this->matchGlobPattern($pattern, $relativePath)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array{versions: string[], files: string[]}|null $provides
     */
    private function updateEntry(string $relativePath, string $hash, int $size, ?array $provides): void
    {
        $relativePath = $this->normalizeRelativePath($relativePath);

        $existingProvides = $this->map['files'][$relativePath]['provides'] ?? ['versions' => [], 'files' => []];
        $existingHash = $this->map['files'][$relativePath]['hash'] ?? [];
        if (!is_array($existingHash)) {
            $existingHash = [];
        }

        $existingHash[$this->hashAlgorithm] = $hash;
        $finalProvides = $provides ?? $existingProvides;

        $this->map['files'][$relativePath] = [
            'hash' => $existingHash,
            'size' => $size,
            'provides' => $finalProvides,
        ];

        if ($hash !== '') {
            $this->hashIndex[$hash] = $this->hashIndex[$hash] ?? [];

            if (!in_array($relativePath, $this->hashIndex[$hash], true)) {
                $this->hashIndex[$hash][] = $relativePath;
            }
        }
    }

    private function rebuildHashIndex(): void
    {
        $this->hashIndex = [];

        foreach ($this->map['files'] as $path => $entry) {
            $hash = $entry['hash'][$this->hashAlgorithm] ?? null;
            if (!is_string($hash) || $hash === '') {
                continue;
            }

            $this->hashIndex[$hash] = $this->hashIndex[$hash] ?? [];
            $this->hashIndex[$hash][] = $path;
        }

        $this->log->debug($this->language->t('filesystem.hash_index_rebuilt', $this->hashAlgorithm, count($this->hashIndex)));
    }

    private function hashFile(string $path): ?string
    {
        $hash = hash_file($this->hashAlgorithm, $path);

        if ($hash === false) {
            $this->log->warning($this->language->t('filesystem.hash_failed', $this->hashAlgorithm, $path));
            return null;
        }

        return $this->normalizeHashValue($this->hashAlgorithm, (string) $hash);
    }
    /**
     * @return string[]
     */
    private function extractVersions(string $relativePath): array
    {
        if (preg_match('~(^|/)eset_upd/([^/]+)/~', $relativePath, $matches)) {
            $version = trim((string) ($matches[2] ?? ''));
            return $version !== '' ? [$version] : [];
        }

        return [];
    }

    public function toRelativePath(string $baseDir, string $absolutePath): string
    {
        $baseDir = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR;
        $relative = str_starts_with($absolutePath, $baseDir)
            ? substr($absolutePath, strlen($baseDir))
            : $absolutePath;

        return $this->normalizeRelativePath($relative);
    }

    private function normalizeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        return ltrim($path, '/');
    }

    private function matchGlobPattern(string $pattern, string $path): bool
    {
        if (!str_contains($pattern, '**')) {
            return fnmatch($pattern, $path);
        }

        $regex = preg_quote($pattern, '#');
        $regex = str_replace('\*\*', '.*', $regex);
        $regex = str_replace('\*', '[^/]*', $regex);
        $regex = str_replace('\?', '[^/]', $regex);

        return (bool) preg_match('#^' . $regex . '$#', $path);
    }

    private function normalizeHashValue(string $algorithm, string $hash): string
    {
        $hash = strtolower(trim($hash));

        if (str_starts_with($hash, '0x')) {
            $hash = substr($hash, 2);
        }

        return match ($algorithm) {
            'crc32', 'crc32b' => str_pad($hash, 8, '0', STR_PAD_LEFT),
            default => $hash,
        };
    }

    /**
     * @param string[] $versions
     * @param string[] $files
     * @return array{versions: string[], files: string[]}
     */
    private function buildProvides(array $versions, array $files): array
    {
        return [
            'versions' => array_values(array_unique(array_filter($versions, 'strlen'))),
            'files' => array_values(array_unique(array_filter($files, 'strlen'))),
        ];
    }

    /**
     * @param array{versions?: string[], files?: string[]}|null $provides
     */
    private function isProvidesEmpty(?array $provides): bool
    {
        if ($provides === null) {
            return true;
        }

        $versions = $provides['versions'] ?? [];
        $files = $provides['files'] ?? [];

        return empty($versions) && empty($files);
    }

    private function removeEntry(string $relativePath): void
    {
        $relativePath = $this->normalizeRelativePath($relativePath);

        if (!isset($this->map['files'][$relativePath])) {
            return;
        }

        $hash = $this->map['files'][$relativePath]['hash'][$this->hashAlgorithm] ?? null;
        unset($this->map['files'][$relativePath]);

        if (is_string($hash) && $hash !== '') {
            if (isset($this->hashIndex[$hash])) {
                $this->hashIndex[$hash] = array_values(array_filter(
                    $this->hashIndex[$hash],
                    static fn(string $path): bool => $path !== $relativePath
                ));

                if (empty($this->hashIndex[$hash])) {
                    unset($this->hashIndex[$hash]);
                }
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Nod32Mirror\Storage;

use Nod32Mirror\Config\Config;
use Nod32Mirror\FileSystem\SafeFileOperations;
use Nod32Mirror\Log\Language;
use Nod32Mirror\Log\Log;
use Nod32Mirror\Tools;
use Nod32Mirror\ValueObject\ReferenceCollection;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class StorageGarbageCollector
{
    public function __construct(
        private readonly Config $config,
        private readonly StorageConfig $storageConfig,
        private readonly ContentIndex $contentIndex,
        private readonly BlobStore $blobStore,
        private readonly SafeFileOperations $fileOps,
        private readonly Log $log,
        private readonly Language $language
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(string $webDir, ReferenceCollection $references): array
    {
        $state = [
            'enabled' => $this->storageConfig->isGcEnabled(),
            'completed' => false,
            'skipped_reason' => null,
            'started_at' => ContentIndex::now(),
            'finished_at' => null,
            'referenced_paths' => count($references->getPaths()),
            'deleted_published_paths' => [],
            'deleted_blobs' => [],
            'errors' => $references->getErrors(),
        ];

        if (!$this->storageConfig->isGcEnabled()) {
            $state['skipped_reason'] = 'disabled';
            $state['finished_at'] = ContentIndex::now();
            return $state;
        }

        if (!$references->isComplete()) {
            $state['skipped_reason'] = 'reference_scan_failed';
            $state['finished_at'] = ContentIndex::now();
            return $state;
        }

        if (empty($references->getPaths())) {
            $state['skipped_reason'] = 'no_references';
            $state['finished_at'] = ContentIndex::now();
            return $state;
        }

        $missing = $this->findMissingReferencedFiles($webDir, $references);
        if (!empty($missing)) {
            $state['skipped_reason'] = 'missing_referenced_paths';
            $state['errors'] = array_merge($state['errors'], array_map(
                fn(string $path): string => $this->language->t('storage.gc_missing_referenced_path', $path),
                $missing
            ));
            $state['finished_at'] = ContentIndex::now();
            return $state;
        }

        $state['deleted_published_paths'] = $this->deleteUnreferencedPublishedPaths($webDir, $references);
        $state['deleted_blobs'] = $this->deleteUnreferencedBlobs();
        $state['completed'] = true;
        $state['finished_at'] = ContentIndex::now();

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     */
    public function saveState(string $path, array $state): void
    {
        Tools::writeJsonPrettyTabsFile($path, $state);
    }

    /**
     * @return string[]
     */
    private function findMissingReferencedFiles(string $webDir, ReferenceCollection $references): array
    {
        $missing = [];

        foreach ($references->getPaths() as $relativePath) {
            if (!is_file(Tools::ds($webDir, $relativePath))) {
                $missing[] = $relativePath;
            }
        }

        return $missing;
    }

    /**
     * @return string[]
     */
    private function deleteUnreferencedPublishedPaths(string $webDir, ReferenceCollection $references): array
    {
        $deleted = [];

        if (!is_dir($webDir)) {
            return $deleted;
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

            if ($references->hasPath($relativePath) || $this->isExcludedPath($relativePath)) {
                continue;
            }

            if ($this->fileOps->deleteFile($absolutePath)) {
                $deleted[] = $relativePath;
                $this->contentIndex->removePublished($relativePath);
                $this->log->debug($this->language->t('storage.gc_deleted_published_path', $relativePath));
            }
        }

        return $deleted;
    }

    /**
     * @return string[]
     */
    private function deleteUnreferencedBlobs(): array
    {
        $deleted = [];
        $deletedHashes = [];
        foreach ($this->contentIndex->iterateUnreferencedBlobs() as $hash => $entry) {
            $hash = strtolower(trim((string) $hash));
            if ($hash === '') {
                continue;
            }

            $blobPath = is_array($entry) && is_string($entry['blob_path'] ?? null)
                ? $entry['blob_path']
                : $this->blobStore->getBlobPath($hash);

            if (!is_file($blobPath)) {
                $deletedHashes[] = $hash;
                continue;
            }

            if ($this->fileOps->deleteFile($blobPath)) {
                $deleted[] = $blobPath;
                $deletedHashes[] = $hash;
                $this->log->debug($this->language->t('storage.gc_deleted_blob', $blobPath));
            }
        }

        foreach ($deletedHashes as $hash) {
            $this->contentIndex->removeHash($hash);
        }

        foreach ($this->findBlobFiles() as $hash => $blobPath) {
            if ($this->contentIndex->hasHash($hash)) {
                continue;
            }

            if (is_file($blobPath) && $this->fileOps->deleteFile($blobPath)) {
                $deleted[] = $blobPath;
                $this->contentIndex->removeHash($hash);
                $this->log->debug($this->language->t('storage.gc_deleted_orphan_blob', $blobPath));
            }
        }

        return $deleted;
    }

    /**
     * @return iterable<string, string>
     */
    private function findBlobFiles(): iterable
    {
        $root = Tools::ds($this->storageConfig->getBlobDir(), $this->storageConfig->getHashAlgorithm());

        if (!is_dir($root)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $fileObject) {
            if ($fileObject->isDir()) {
                continue;
            }

            $hash = strtolower($fileObject->getBasename());
            if (preg_match('/^[a-f0-9]+$/', $hash)) {
                yield $hash => $fileObject->getPathname();
            }
        }
    }

    private function isExcludedPath(string $relativePath): bool
    {
        $relativePath = str_replace('\\', '/', ltrim($relativePath, '/'));

        $reports = $this->config->getOrDefault('web.reports', []);

        $excludes = [];
        if (is_array($reports)) {
            if (!empty($reports['html']['enabled'])) {
                $excludes[] = (string) ($reports['html']['file'] ?? 'index.html');
            }
            if (!empty($reports['json']['enabled'])) {
                $excludes[] = (string) ($reports['json']['file'] ?? 'index.json');
            }
            if (!empty($reports['status']['enabled'])) {
                $excludes[] = (string) ($reports['status']['file'] ?? 'status.json');
            }
        }

        $configuredExcludes = $this->config->getOrDefault('storage.gc.excludes', []);
        if (is_array($configuredExcludes)) {
            foreach ($configuredExcludes as $exclude) {
                if (is_string($exclude) && trim($exclude) !== '') {
                    $excludes[] = $exclude;
                }
            }
        }

        foreach ($excludes as $exclude) {
            $exclude = $this->normalizeExcludePattern((string) $exclude);
            if ($exclude === '') {
                continue;
            }

            if ($this->matchesExclude($relativePath, $exclude)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeExcludePattern(string $exclude): string
    {
        $exclude = str_replace('\\', '/', trim($exclude));
        $exclude = ltrim($exclude, '/');

        while (str_starts_with($exclude, './')) {
            $exclude = substr($exclude, 2);
        }

        return $exclude;
    }

    private function matchesExclude(string $relativePath, string $exclude): bool
    {
        if (str_ends_with($exclude, '/')) {
            return str_starts_with($relativePath, $exclude);
        }

        if (!str_contains($exclude, '*') && !str_contains($exclude, '?') && !str_contains($exclude, '[')) {
            return $relativePath === $exclude || str_starts_with($relativePath, rtrim($exclude, '/') . '/');
        }

        return (bool) @preg_match($this->globToRegex($exclude), $relativePath);
    }

    private function globToRegex(string $pattern): string
    {
        $regex = '';
        $length = strlen($pattern);

        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];

            if ($char === '*') {
                if (($pattern[$i + 1] ?? '') === '*') {
                    $regex .= '.*';
                    $i++;
                } else {
                    $regex .= '[^/]*';
                }
                continue;
            }

            if ($char === '?') {
                $regex .= '[^/]';
                continue;
            }

            if ($char === '[') {
                $end = strpos($pattern, ']', $i + 1);
                if ($end !== false) {
                    $class = substr($pattern, $i, $end - $i + 1);
                    $regex .= $class;
                    $i = $end;
                    continue;
                }
            }

            $regex .= preg_quote($char, '#');
        }

        return '#^' . $regex . '$#';
    }

    private function toRelativePath(string $baseDir, string $absolutePath): string
    {
        $baseDir = rtrim(str_replace('\\', '/', $baseDir), '/') . '/';
        $absolutePath = str_replace('\\', '/', $absolutePath);
        $relative = str_starts_with($absolutePath, $baseDir)
            ? substr($absolutePath, strlen($baseDir))
            : $absolutePath;

        return ltrim($relative, '/');
    }
}

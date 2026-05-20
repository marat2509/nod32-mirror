<?php

declare(strict_types=1);

namespace Nod32Mirror\Storage;

use Nod32Mirror\Config\Config;
use Nod32Mirror\FileSystem\SafeFileOperations;
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
        private readonly Log $log
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
                static fn(string $path): string => 'Missing referenced path: ' . $path,
                $missing
            ));
            $state['finished_at'] = ContentIndex::now();
            return $state;
        }

        $state['deleted_published_paths'] = $this->deleteUnreferencedPublishedPaths($webDir, $references);
        $state['deleted_blobs'] = $this->deleteUnreferencedBlobs($webDir, $references);
        $state['completed'] = true;
        $state['finished_at'] = ContentIndex::now();

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     */
    public function saveState(string $path, array $state): void
    {
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        $this->fileOps->writeFile($path, $json . PHP_EOL);
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

            if ($references->hasPath($relativePath) || $this->isProtectedPath($relativePath)) {
                continue;
            }

            if ($this->fileOps->deleteFile($absolutePath)) {
                $deleted[] = $relativePath;
                $this->contentIndex->removePublished($relativePath);
                $this->log->debug(sprintf('Storage GC deleted published path: %s', $relativePath));
            }
        }

        return $deleted;
    }

    /**
     * @return string[]
     */
    private function deleteUnreferencedBlobs(string $webDir, ReferenceCollection $references): array
    {
        $liveHashes = [];

        foreach ($this->contentIndex->getPublished() as $relativePath => $entry) {
            if (!is_array($entry) || !$references->hasPath((string) $relativePath)) {
                continue;
            }

            $hash = is_string($entry['hash'] ?? null) ? strtolower(trim($entry['hash'])) : '';
            if ($hash === '') {
                continue;
            }

            $absolutePath = Tools::ds($webDir, (string) $relativePath);
            if (!is_file($absolutePath)) {
                continue;
            }

            $liveHashes[$hash] = true;
        }

        $deleted = [];
        foreach ($this->contentIndex->getHashes() as $hash => $entry) {
            $hash = strtolower(trim((string) $hash));
            if ($hash === '' || isset($liveHashes[$hash])) {
                continue;
            }

            $blobPath = is_array($entry) && is_string($entry['blob_path'] ?? null)
                ? $entry['blob_path']
                : $this->blobStore->getBlobPath($hash);

            if (is_file($blobPath) && $this->fileOps->deleteFile($blobPath)) {
                $deleted[] = $blobPath;
                $this->log->debug(sprintf('Storage GC deleted blob: %s', $blobPath));
            }

            $this->contentIndex->removeHash($hash);
        }

        foreach ($this->findBlobFiles() as $hash => $blobPath) {
            if (isset($liveHashes[$hash])) {
                continue;
            }

            if (is_file($blobPath) && $this->fileOps->deleteFile($blobPath)) {
                $deleted[] = $blobPath;
                $this->contentIndex->removeHash($hash);
                $this->log->debug(sprintf('Storage GC deleted orphan blob: %s', $blobPath));
            }
        }

        return $deleted;
    }

    /**
     * @return array<string, string>
     */
    private function findBlobFiles(): array
    {
        $files = [];
        $root = Tools::ds($this->storageConfig->getBlobDir(), $this->storageConfig->getHashAlgorithm());

        if (!is_dir($root)) {
            return $files;
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
                $files[$hash] = $fileObject->getPathname();
            }
        }

        return $files;
    }

    private function isProtectedPath(string $relativePath): bool
    {
        $relativePath = str_replace('\\', '/', ltrim($relativePath, '/'));

        if (preg_match('/\.publish-[a-f0-9]+\.tmp$/i', $relativePath)) {
            return true;
        }

        $scriptConfig = $this->config->getOrDefault('script', []);
        $generate = is_array($scriptConfig) ? ($scriptConfig['generate'] ?? []) : [];

        $protected = [];
        if (is_array($generate)) {
            if (!empty($generate['html']['enabled'])) {
                $protected[] = (string) ($generate['html']['filename'] ?? 'index.html');
            }
            if (!empty($generate['json']['enabled'])) {
                $protected[] = (string) ($generate['json']['filename'] ?? 'index.json');
            }
        }

        return in_array($relativePath, $protected, true);
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

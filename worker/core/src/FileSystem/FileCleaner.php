<?php

declare(strict_types=1);

namespace Nod32Mirror\FileSystem;

use Nod32Mirror\Log\Language;
use Nod32Mirror\Log\Log;
use Nod32Mirror\Tools;
use Nod32Mirror\ValueObject\CleanupResult;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Service for cleaning up old and unused files
 */
final class FileCleaner
{
    public function __construct(
        private readonly SafeFileOperations $fileOps,
        private readonly Log $log,
        private readonly Language $language
    ) {
    }

    /**
     * Delete files that are not in the needed files list
     *
     * @param string $folder Folder to clean
     * @param string[] $neededFiles Files that should NOT be deleted
     * @return CleanupResult
     */
    public function deleteOldFiles(string $folder, array $neededFiles): CleanupResult
    {
        $this->log->trace($this->language->t('log.running', __METHOD__));

        $deletedFiles = [];
        $failedFiles = [];
        $count = 0;

        if (!is_dir($folder)) {
            return CleanupResult::empty();
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($folder),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $fileObject) {
                if ($fileObject->isDir()) {
                    continue;
                }

                $filePath = $fileObject->getPathname();

                if (in_array($filePath, $neededFiles, true)) {
                    continue;
                }

                if ($this->fileOps->deleteFile($filePath)) {
                    $deletedFiles[] = $filePath;
                    $count++;
                } else {
                    $failedFiles[] = $filePath;
                }
            }
        } catch (\Exception $e) {
            $this->log->debug($this->language->t('filesystem.cleanup_error', $folder, $e->getMessage()));
        }

        return new CleanupResult(
            deletedFilesCount: $count,
            deletedFoldersCount: 0,
            deletedFiles: $deletedFiles,
            failedFiles: $failedFiles
        );
    }

    /**
     * Delete empty folders recursively
     *
     * @param string $folder Root folder to check
     * @return CleanupResult
     */
    public function deleteEmptyFolders(string $folder): CleanupResult
    {
        $this->log->trace($this->language->t('log.running', __METHOD__));

        $deletedFolders = [];
        $failedFolders = [];
        $count = 0;

        if (!is_dir($folder)) {
            return CleanupResult::empty();
        }

        try {
            // Get all subdirectories
            $directories = $this->getAllSubDirectories($folder);

            // Sort by depth (deepest first) to delete from bottom up
            usort($directories, static function (string $a, string $b): int {
                return substr_count($b, DIRECTORY_SEPARATOR) - substr_count($a, DIRECTORY_SEPARATOR);
            });

            foreach ($directories as $dir) {
                if ($this->fileOps->isDirectoryEmpty($dir)) {
                    if ($this->fileOps->deleteDirectory($dir)) {
                        $deletedFolders[] = $dir;
                        $count++;
                    } else {
                        $failedFolders[] = $dir;
                    }
                }
            }

            // Finally check the root folder itself
            if ($this->fileOps->isDirectoryEmpty($folder)) {
                if ($this->fileOps->deleteDirectory($folder)) {
                    $deletedFolders[] = $folder;
                    $count++;
                } else {
                    $failedFolders[] = $folder;
                }
            }
        } catch (\Exception $e) {
            $this->log->debug($this->language->t('filesystem.cleanup_error', $folder, $e->getMessage()));
        }

        return new CleanupResult(
            deletedFilesCount: 0,
            deletedFoldersCount: $count,
            deletedFolders: $deletedFolders,
            failedFolders: $failedFolders
        );
    }

    /**
     * Clean folders by version prefix
     *
     * @param string $baseDir Base directory
     * @param string $versionPrefix Prefix like 'v10-*' or 'ep5-*'
     * @param string[] $neededFiles Files that should NOT be deleted
     * @return CleanupResult
     */
    public function cleanVersionFolders(string $baseDir, string $versionPrefix, array $neededFiles): CleanupResult
    {
        $this->log->trace($this->language->t('log.running', __METHOD__));

        $totalResult = CleanupResult::empty();
        $pattern = Tools::ds($baseDir, $versionPrefix . '-*');
        $folders = glob($pattern, GLOB_ONLYDIR);

        if ($folders === false || empty($folders)) {
            return $totalResult;
        }

        foreach ($folders as $folder) {
            $fileResult = $this->deleteOldFiles($folder, $neededFiles);
            $totalResult = $totalResult->merge($fileResult);

            $folderResult = $this->deleteEmptyFolders($folder);
            $totalResult = $totalResult->merge($folderResult);
        }

        return $totalResult;
    }

    /**
     * Get all subdirectories recursively
     *
     * @return string[]
     */
    private function getAllSubDirectories(string $folder): array
    {
        $directories = [];

        try {
            $iterator = new RecursiveDirectoryIterator($folder);

            foreach ($iterator as $fileObject) {
                if (!$fileObject->isDir() || $fileObject->isDot()) {
                    continue;
                }

                $path = $fileObject->getPathname();
                $directories[] = $path;

                // Recursively get subdirectories
                $subdirs = $this->getAllSubDirectories($path);
                $directories = array_merge($directories, $subdirs);
            }
        } catch (\Exception) {
            // Ignore errors
        }

        return $directories;
    }
}

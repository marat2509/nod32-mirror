<?php

declare(strict_types=1);

namespace Nod32Mirror\FileSystem;

use Nod32Mirror\Exception\FileSystemException;
use Nod32Mirror\Log\Language;
use Nod32Mirror\Log\Log;

/**
 * Safe file system operations with proper error handling
 */
final class SafeFileOperations
{
    public function __construct(
        private readonly Log $log,
        private readonly Language $language
    ) {
    }

    /**
     * Safely delete a file
     *
     * @throws FileSystemException
     */
    public function deleteFile(string $path, bool $throwOnFailure = false): bool
    {
        if (!file_exists($path)) {
            return true;
        }

        if (!is_file($path) && !is_link($path)) {
            if ($throwOnFailure) {
                throw FileSystemException::cannotDeleteFile($path);
            }
            return false;
        }

        $result = unlink($path);

        if (!$result) {
            $this->log->debug($this->language->t('filesystem.delete_file_failed', $path));
            if ($throwOnFailure) {
                throw FileSystemException::cannotDeleteFile($path);
            }
        }

        return $result;
    }

    /**
     * Safely delete a directory
     *
     * @throws FileSystemException
     */
    public function deleteDirectory(string $path, bool $throwOnFailure = false): bool
    {
        if (!is_dir($path)) {
            return true;
        }

        $result = rmdir($path);

        if (!$result) {
            $this->log->debug($this->language->t('filesystem.delete_dir_failed', $path));
            if ($throwOnFailure) {
                throw FileSystemException::cannotDeleteDirectory($path);
            }
        }

        return $result;
    }

    /**
     * Safely create a directory
     *
     * @throws FileSystemException
     */
    public function createDirectory(string $path, int $mode = 0755, bool $throwOnFailure = false): bool
    {
        if (is_dir($path)) {
            return true;
        }

        $result = mkdir($path, $mode, true);

        if (!$result && !is_dir($path)) {
            $this->log->debug($this->language->t('filesystem.create_dir_failed', $path));
            if ($throwOnFailure) {
                throw FileSystemException::cannotCreateDirectory($path);
            }
            return false;
        }

        return true;
    }

    /**
     * Safely read file contents
     *
     * @throws FileSystemException
     */
    public function readFile(string $path, bool $throwOnFailure = true): ?string
    {
        if (!file_exists($path)) {
            if ($throwOnFailure) {
                throw FileSystemException::fileNotFound($path);
            }
            return null;
        }

        $content = file_get_contents($path);

        if ($content === false) {
            $this->log->debug($this->language->t('filesystem.read_file_failed', $path));
            if ($throwOnFailure) {
                throw FileSystemException::cannotReadFile($path);
            }
            return null;
        }

        return $content;
    }

    /**
     * Safely write file contents
     *
     * @throws FileSystemException
     */
    public function writeFile(string $path, string $content, bool $throwOnFailure = false): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            $this->createDirectory($dir, 0755, $throwOnFailure);
        }

        $result = file_put_contents($path, $content);

        if ($result === false) {
            $this->log->debug($this->language->t('filesystem.write_file_failed', $path));
            if ($throwOnFailure) {
                throw FileSystemException::cannotWriteFile($path);
            }
            return false;
        }

        return true;
    }

    /**
     * Safely create a hard link
     *
     * @throws FileSystemException
     */
    public function createHardlink(string $source, string $target, bool $throwOnFailure = false): bool
    {
        $this->createDirectory(dirname($target));

        $result = link($source, $target);

        if (!$result) {
            $this->log->debug($this->language->t('filesystem.create_hardlink_failed', $source, $target));
            if ($throwOnFailure) {
                throw FileSystemException::cannotCreateLink($source, $target, 'hardlink');
            }
        }

        return $result;
    }

    /**
     * Safely create a symbolic link
     *
     * @throws FileSystemException
     */
    public function createSymlink(string $source, string $target, bool $throwOnFailure = false): bool
    {
        $this->createDirectory(dirname($target));

        $result = symlink($source, $target);

        if (!$result) {
            $this->log->debug($this->language->t('filesystem.create_symlink_failed', $source, $target));
            if ($throwOnFailure) {
                throw FileSystemException::cannotCreateLink($source, $target, 'symlink');
            }
        }

        return $result;
    }

    /**
     * Safely copy a file
     *
     * @throws FileSystemException
     */
    public function copyFile(string $source, string $target, bool $throwOnFailure = false): bool
    {
        $this->createDirectory(dirname($target));

        $result = copy($source, $target);

        if (!$result) {
            $this->log->debug($this->language->t('filesystem.copy_file_failed', $source, $target));
            if ($throwOnFailure) {
                throw FileSystemException::cannotCreateLink($source, $target, 'copy');
            }
        }

        return $result;
    }

    /**
     * Get file stat info safely
     *
     * @return array<string, mixed>|null
     */
    public function stat(string $path): ?array
    {
        if (!file_exists($path)) {
            return null;
        }

        $stat = stat($path);
        return $stat !== false ? $stat : null;
    }

    /**
     * Check if directory is empty
     */
    public function isDirectoryEmpty(string $path): bool
    {
        if (!is_dir($path)) {
            return true;
        }

        $contents = glob($path . DIRECTORY_SEPARATOR . '*');
        return $contents === false || count($contents) === 0;
    }
}

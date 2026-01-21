<?php

declare(strict_types=1);

namespace Nod32Mirror\FileSystem;

use Nod32Mirror\Enum\LinkMethod;
use Nod32Mirror\Log\Language;
use Nod32Mirror\Log\Log;
use Nod32Mirror\Tools;
use Nod32Mirror\ValueObject\DownloadableFile;
use Nod32Mirror\ValueObject\LinkInfo;
use Nod32Mirror\ValueObject\LinkResult;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RecursiveRegexIterator;
use RegexIterator;

/**
 * Service for creating file links/copies
 */
final class FileLinker
{
    public function __construct(
        private readonly SafeFileOperations $fileOps,
        private readonly Log $log,
        private readonly Language $language
    ) {
    }

    /**
     * Create links or copies for files based on existing older versions
     *
     * @param string $dir Target directory
     * @param DownloadableFile[] $files Files to process
     * @param string $version Current version (e.g., 'v10', 'ep5')
     * @param LinkMethod $linkMethod Method to use for linking
     * @return LinkResult
     */
    public function createLinks(
        string $dir,
        array $files,
        string $version,
        LinkMethod $linkMethod
    ): LinkResult {
        $this->log->trace($this->language->t('log.running', __METHOD__));

        $oldFiles = $this->findOldVersionFiles($dir, $version);
        $neededFiles = [];
        $downloadFiles = [];
        $linkedFiles = [];
        $linkedCount = 0;

        foreach ($files as $file) {
            $path = Tools::ds($dir, $file->path);
            $neededFiles[] = $path;

            // Check if file exists with correct size
            if (file_exists($path)) {
                $stat = $this->fileOps->stat($path);
                if ($stat !== null && !Tools::compareFiles($stat, ['size' => $file->size])) {
                    $this->fileOps->deleteFile($path);
                }
            }

            // If file still doesn't exist, try to link from old version
            if (!file_exists($path)) {
                $linkResult = $this->tryLinkFromOldFile($file, $path, $oldFiles, $dir, $linkMethod);

                if ($linkResult !== null) {
                    $linkedFiles[$file->path] = $linkResult;
                    if ($linkResult->success) {
                        $linkedCount++;
                    }
                }

                // If still no file, add to download list
                if (!file_exists($path) && !$this->isInDownloadList($file, $downloadFiles)) {
                    $downloadFiles[] = $file;
                }
            }
        }

        return new LinkResult(
            filesToDownload: $downloadFiles,
            neededFiles: $neededFiles,
            linkedFiles: $linkedFiles,
            linkedCount: $linkedCount
        );
    }

    /**
     * Find files from older versions that can be used for linking
     *
     * @return string[]
     */
    private function findOldVersionFiles(string $dir, string $version): array
    {
        $oldFiles = [];
        $versionPattern = '/([v|ep]+)(\d+)/is';

        try {
            $iterator = new RegexIterator(
                new RecursiveIteratorIterator(
                    new RecursiveRegexIterator(
                        new RecursiveDirectoryIterator($dir),
                        '/[v|ep]+\d+[-]+/i'
                    )
                ),
                '/\.nup$/i'
            );

            $versionMatch = null;
            preg_match($versionPattern, $version, $versionMatch);
            $currentVersionNum = (int) ($versionMatch[2] ?? 0);

            foreach ($iterator as $file) {
                $filepath = $file->getPathname();

                // Skip symbolic links
                if (is_link($filepath)) {
                    continue;
                }

                // Check if file is from an older version
                $pathVersionMatch = null;
                preg_match($versionPattern, $filepath, $pathVersionMatch);
                $fileVersionNum = (int) ($pathVersionMatch[2] ?? 0);

                if ($versionMatch && $currentVersionNum > $fileVersionNum) {
                    $oldFiles[] = $filepath;
                }
            }
        } catch (\Exception $e) {
            // Directory might not exist yet
            $this->log->trace($this->language->t('filesystem.scan_dir_failed', $dir, $e->getMessage()));
        }

        return $oldFiles;
    }

    /**
     * Try to link a file from an older version
     *
     * @param string[] $oldFiles
     */
    private function tryLinkFromOldFile(
        DownloadableFile $file,
        string $targetPath,
        array $oldFiles,
        string $dir,
        LinkMethod $linkMethod
    ): ?LinkInfo {
        $basename = basename($file->path);
        $pattern = '/' . preg_quote($basename, '/') . '$/';
        $matches = preg_grep($pattern, $oldFiles);

        if (empty($matches)) {
            return null;
        }

        foreach ($matches as $sourcePath) {
            $stat = $this->fileOps->stat($sourcePath);

            if ($stat === null || !Tools::compareFiles($stat, ['size' => $file->size])) {
                continue;
            }

            $this->fileOps->createDirectory(dirname($targetPath));

            $success = match ($linkMethod) {
                LinkMethod::Hardlink => $this->fileOps->createHardlink($sourcePath, $targetPath),
                LinkMethod::Symlink => $this->fileOps->createSymlink($sourcePath, $targetPath),
                default => $this->fileOps->copyFile($sourcePath, $targetPath),
            };

            $linkInfo = new LinkInfo(
                sourcePath: $sourcePath,
                targetPath: $targetPath,
                method: $linkMethod,
                success: $success
            );

            if ($success) {
                $this->log->info(
                    $this->language->t(
                        $this->getLinkMessageKey($linkMethod),
                        $linkInfo->getRelativeSource($dir),
                        $linkInfo->getRelativeTarget($dir)
                    )
                );
                return $linkInfo;
            }
        }

        return null;
    }

    private function getLinkMessageKey(LinkMethod $method): string
    {
        return match ($method) {
            LinkMethod::Hardlink => 'mirror.created_hardlink',
            LinkMethod::Symlink => 'mirror.created_symlink',
            default => 'mirror.copied_file',
        };
    }

    /**
     * @param DownloadableFile[] $downloadFiles
     */
    private function isInDownloadList(DownloadableFile $file, array $downloadFiles): bool
    {
        foreach ($downloadFiles as $df) {
            if ($df->path === $file->path) {
                return true;
            }
        }
        return false;
    }
}

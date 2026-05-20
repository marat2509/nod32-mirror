<?php

declare(strict_types=1);

namespace Nod32Mirror\Storage;

use Nod32Mirror\Config\VersionConfig;
use Nod32Mirror\FileSystem\SafeFileOperations;
use Nod32Mirror\Log\Language;
use Nod32Mirror\Log\Log;
use Nod32Mirror\Mirror\Mirror;
use Nod32Mirror\Parser\Parser;
use Nod32Mirror\Tools;
use Nod32Mirror\ValueObject\DownloadableFile;
use Nod32Mirror\ValueObject\ReferenceCollection;

final class ReferenceCollector
{
    /**
     * @param array<string, array<string, mixed>> $directories
     */
    public function __construct(
        private readonly VersionConfig $versionConfig,
        private readonly Parser $parser,
        private readonly SafeFileOperations $fileOps,
        private readonly Log $log,
        private readonly Language $language,
        private readonly Mirror $mirror,
        private readonly array $directories
    ) {
    }

    public function collect(string $webDir): ReferenceCollection
    {
        $references = new ReferenceCollection();
        $indexCount = 0;

        foreach ($this->versionConfig->getEnabledVersions() as $version) {
            if (!isset($this->directories[$version])) {
                continue;
            }

            $platforms = $this->versionConfig->getVersionPlatforms($version);
            $channels = $this->versionConfig->getVersionChannels($version);
            $this->mirror->init($version, $this->directories[$version], $platforms, $channels);

            foreach ($this->mirror->getUpdateVariants() as $variant) {
                $indexRelativePath = $this->toRelativePath($webDir, $variant->localPath);

                if (!is_file($variant->localPath)) {
                    $this->log->warning(
                        $this->language->t('global_cleanup.index_missing', $indexRelativePath),
                        $version,
                        $variant->getChannel()
                    );
                    continue;
                }

                $content = $this->fileOps->readFile($variant->localPath, false);
                if ($content === null) {
                    $message = sprintf('Published index is unreadable: %s', $indexRelativePath);
                    $references->addError($message);
                    $this->log->warning($message, $version, $variant->getChannel());
                    continue;
                }

                if (!preg_match_all('#\[\w+\][^\[]+#', $content, $matches)) {
                    $message = sprintf('Published index parse failed: %s', $indexRelativePath);
                    $references->addError($message);
                    $this->log->warning($message, $version, $variant->getChannel());
                    continue;
                }

                $references->addIndexPath($indexRelativePath);
                $indexCount++;
                $channel = $variant->getChannel() ?? 'default';

                $parsed = $this->parser->parseUpdateFile(
                    $matches[0],
                    fn(DownloadableFile $file): bool => $this->fileMatchesPlatforms($file, $platforms)
                );

                foreach ($parsed['files'] as $file) {
                    if (!$this->isSafeRelativePath($file->path)) {
                        $this->log->warning(sprintf('Unsafe path in published index skipped: %s', $file->path), $version, $channel);
                        continue;
                    }

                    $relativePath = $this->toRelativePath($webDir, Tools::ds($webDir, $file->path));
                    if ($relativePath === '') {
                        continue;
                    }

                    $references->addVersionChannel($relativePath, $version, $channel);
                }
            }
        }

        $this->log->debug($this->language->t('global_cleanup.references_collected', count($references->getPaths()), $indexCount));

        return $references;
    }

    /**
     * @param string[]|true $platforms
     */
    private function fileMatchesPlatforms(DownloadableFile $file, array|bool $platforms): bool
    {
        if ($file->platform === null) {
            return true;
        }

        if ($platforms === true || empty($platforms)) {
            return true;
        }

        return in_array($file->platform, $platforms, true);
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

    private function isSafeRelativePath(string $path): bool
    {
        $path = str_replace('\\', '/', $path);

        if ($path === '' || str_starts_with($path, '/') || preg_match('#^[A-Za-z]:/#', $path)) {
            return false;
        }

        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                return false;
            }
        }

        return true;
    }
}

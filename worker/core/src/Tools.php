<?php

declare(strict_types=1);

namespace Nod32Mirror;

final class Tools
{
    /**
     * Build a path with proper directory separators
     */
    public static function ds(string ...$parts): string
    {
        return preg_replace('/[\/\\\\]+/', DIRECTORY_SEPARATOR, implode('/', $parts)) ?? '';
    }

    /**
     * Format bytes to human-readable size (1024-based)
     */
    public static function bytesToSize1024(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB'];

        if ($bytes <= 0) {
            return '0 ' . $units[0];
        }

        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);

        return round($bytes / (1024 ** $i), $precision) . ' ' . $units[$i];
    }

    /**
     * Format seconds to human-readable time
     */
    public static function secondsToHumanReadable(int $seconds): string
    {
        if ($seconds > 86400) {
            return gmdate('H:i:s', $seconds);
        }

        return gmdate('i:s', $seconds);
    }

    /**
     * Convert human-readable size to bytes
     */
    public static function human2bytes(string $str): int
    {
        if (!preg_match('/^(\d+)\s*([BKMG])$/i', trim($str), $matches)) {
            return 0;
        }

        $value = (int) $matches[1];
        $unit = strtoupper($matches[2]);

        return match ($unit) {
            'G' => $value << 30,
            'M' => $value << 20,
            'K' => $value << 10,
            default => $value,
        };
    }

    /**
     * Parse comma-separated string into array
     *
     * @return string[]
     */
    public static function parseCommaList(string $string, string $delimiter = ','): array
    {
        if (empty($string)) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode($delimiter, $string)),
            static fn(string $v): bool => $v !== ''
        ));
    }

    /**
     * Compare file stats for matching
     *
     * @param array{size?: int} $file1
     * @param array{size?: int} $file2
     */
    public static function compareFiles(array $file1, array $file2): bool
    {
        return ($file1['size'] ?? 0) === ($file2['size'] ?? 0);
    }

    /**
     * Write content to file with directory creation
     */
    public static function writeToFile(string $filename, string $content, bool $append = true): bool
    {
        $dir = dirname($filename);

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $flags = $append ? FILE_APPEND | LOCK_EX : LOCK_EX;

        return file_put_contents($filename, $content, $flags) !== false;
    }

    /**
     * Convert text encoding
     */
    public static function convertEncoding(string $text, string $toEncoding): string
    {
        if (preg_match('/utf-8/i', $toEncoding)) {
            return $text;
        }

        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($text, $toEncoding, 'UTF-8');
        }

        if (function_exists('iconv')) {
            $result = @iconv('UTF-8', $toEncoding . '//TRANSLIT', $text);
            return $result !== false ? $result : $text;
        }

        return $text;
    }

    /**
     * Get archive file extension
     */
    public static function getArchiveExtension(): string
    {
        return '.gz';
    }

    /**
     * Archive a file using gzip
     */
    public static function archiveFile(string $file): void
    {
        $fp = gzopen($file . '.1.gz', 'w9');

        if ($fp === false) {
            return;
        }

        gzwrite($fp, file_get_contents($file) ?: '');
        gzclose($fp);
        unlink($file);
    }

    /**
     * Get file MIME type
     */
    public static function getFileMimetype(string $file): string
    {
        $finfo = new \finfo();
        return $finfo->file($file, FILEINFO_MIME_TYPE) ?: 'application/octet-stream';
    }

    /**
     * Ensure directory exists
     */
    public static function ensureDirectory(string $path): void
    {
        if (!is_dir($path)) {
            @mkdir($path, 0755, true);
        }
    }

    /**
     * Clean path from trailing separators
     */
    public static function cleanPath(string $path): string
    {
        return rtrim($path, '/\\' . DIRECTORY_SEPARATOR);
    }

    /**
     * Recursively delete directory contents
     */
    public static function clearDirectory(string $path): bool
    {
        if (!is_dir($path)) {
            return false;
        }

        try {
            $iterator = new \DirectoryIterator($path);

            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isDot()) {
                    continue;
                }

                if ($fileInfo->isDir()) {
                    self::clearDirectory($fileInfo->getPathname());
                    @rmdir($fileInfo->getPathname());
                } else {
                    @unlink($fileInfo->getPathname());
                }
            }

            return true;
        } catch (\Exception) {
            return false;
        }
    }
}

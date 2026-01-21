<?php

declare(strict_types=1);

namespace Nod32Mirror\Exception;

/**
 * Exception for filesystem-related errors
 */
final class FileSystemException extends Nod32MirrorException
{
    public static function cannotCreateDirectory(string $path): self
    {
        return new self("Cannot create directory: $path");
    }

    public static function cannotDeleteFile(string $path): self
    {
        return new self("Cannot delete file: $path");
    }

    public static function cannotDeleteDirectory(string $path): self
    {
        return new self("Cannot delete directory: $path");
    }

    public static function cannotReadFile(string $path): self
    {
        return new self("Cannot read file: $path");
    }

    public static function cannotWriteFile(string $path): self
    {
        return new self("Cannot write file: $path");
    }

    public static function cannotCreateLink(string $source, string $target, string $type = 'link'): self
    {
        return new self("Cannot create $type from '$source' to '$target'");
    }

    public static function fileNotFound(string $path): self
    {
        return new self("File not found: $path");
    }
}

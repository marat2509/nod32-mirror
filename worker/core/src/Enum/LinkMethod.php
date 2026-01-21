<?php

declare(strict_types=1);

namespace Nod32Mirror\Enum;

enum LinkMethod: string
{
    case Hardlink = 'hardlink';
    case Symlink = 'symlink';
    case Copy = 'copy';

    public static function fromString(string $method): self
    {
        return self::tryFrom(strtolower(trim($method))) ?? self::Copy;
    }
}

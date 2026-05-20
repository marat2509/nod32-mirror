<?php

declare(strict_types=1);

namespace Nod32Mirror\Enum;

enum StorageLinkMethod: string
{
    case Hardlink = 'hardlink';
    case Softlink = 'softlink';
    case Copy = 'copy';

    public static function fromString(string $method): self
    {
        return self::tryFrom(strtolower(trim($method))) ?? self::Hardlink;
    }
}

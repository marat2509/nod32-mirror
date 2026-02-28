<?php

declare(strict_types=1);

namespace Nod32Mirror\Enum;

/**
 * Strategy for selecting mirrors
 */
enum MirrorStrategy: string
{
    case Random = 'random';
    case Best = 'best';
    case First = 'first';

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        return match ($normalized) {
            'best', 'fastest' => self::Best,
            'first', 'ordered' => self::First,
            default => self::Random,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Random => 'random',
            self::Best => 'best',
            self::First => 'first',
        };
    }
}

<?php

declare(strict_types=1);

namespace Nod32Mirror\Enum;

enum LogLevel: int
{
    case Error = 0;
    case Warning = 1;
    case Notice = 2;
    case Info = 3;
    case Debug = 4;
    case Trace = 5;

    public function label(): string
    {
        return match ($this) {
            self::Error => 'error',
            self::Warning => 'warning',
            self::Notice => 'notice',
            self::Info => 'info',
            self::Debug => 'debug',
            self::Trace => 'trace',
        };
    }

    public static function fromString(string $level): self
    {
        $normalized = strtolower(trim($level));

        return match ($normalized) {
            'error', 'err' => self::Error,
            'warning', 'warn' => self::Warning,
            'notice' => self::Notice,
            'info', 'information' => self::Info,
            'debug', 'verbose' => self::Debug,
            'trace' => self::Trace,
            default => self::Info,
        };
    }

    public static function fromMixed(mixed $level): self
    {
        if ($level instanceof self) {
            return $level;
        }

        if (is_string($level)) {
            return self::fromString($level);
        }

        if (is_int($level)) {
            return self::tryFrom($level) ?? self::Info;
        }

        return self::Info;
    }

    public function isEnabled(self $configuredLevel): bool
    {
        return $configuredLevel->value >= $this->value;
    }
}

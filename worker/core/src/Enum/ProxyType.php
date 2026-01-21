<?php

declare(strict_types=1);

namespace Nod32Mirror\Enum;

enum ProxyType: string
{
    case Http = 'http';
    case Socks4 = 'socks4';
    case Socks4a = 'socks4a';
    case Socks5 = 'socks5';

    public static function fromString(string $type): self
    {
        return self::tryFrom(strtolower(trim($type))) ?? self::Http;
    }

    public function toCurlConstant(): int
    {
        return match ($this) {
            self::Http => CURLPROXY_HTTP,
            self::Socks4 => CURLPROXY_SOCKS4,
            self::Socks4a => CURLPROXY_SOCKS4A,
            self::Socks5 => CURLPROXY_SOCKS5,
        };
    }
}

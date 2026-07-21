<?php

declare(strict_types=1);

namespace Nod32Mirror\Mirror;

use Closure;
use Nod32Mirror\Config\Config;
use Nod32Mirror\Exception\ConfigException;

final class MirrorHostValidator
{
    /** @var string[]|null */
    private readonly ?array $allowedHosts;

    /** @var Closure(string): string[] */
    private readonly Closure $dnsResolver;

    /**
     * @param Closure(string): string[]|null $dnsResolver
     */
    public function __construct(
        private readonly Config $config,
        ?Closure $dnsResolver = null
    ) {
        $this->allowedHosts = $this->config->getAllowedMirrorHosts();
        $this->validateAllowedHosts();
        $this->dnsResolver = $dnsResolver ?? static fn(string $host): array => self::resolveHost($host);
    }

    public function normalize(string $candidate): ?string
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return null;
        }

        [$host, $port] = $this->splitHostAndPort($candidate);
        if ($host === null) {
            return null;
        }

        if ($port !== null && !$this->config->areMirrorDiscoveryPortsAllowed()) {
            return null;
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP);
        if ($ip !== false) {
            $host = strtolower($ip);
            if (!$this->config->arePrivateMirrorAddressesAllowed() && !$this->isPublicIp($host)) {
                return null;
            }
        } else {
            $host = strtolower(rtrim($host, '.'));
            if (!$this->isValidHostname($host)) {
                return null;
            }

            $resolvedAddresses = ($this->dnsResolver)($host);
            if ($resolvedAddresses === []) {
                return null;
            }

            if (!$this->config->arePrivateMirrorAddressesAllowed()) {
                foreach ($resolvedAddresses as $resolvedAddress) {
                    if (!$this->isPublicIp($resolvedAddress)) {
                        return null;
                    }
                }
            }
        }

        if (!$this->matchesAllowedHosts($host)) {
            return null;
        }

        $endpoint = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? '[' . $host . ']'
            : $host;

        return $port !== null ? $endpoint . ':' . $port : $endpoint;
    }

    /**
     * @return array{0: ?string, 1: ?int}
     */
    private function splitHostAndPort(string $candidate): array
    {
        if (str_starts_with($candidate, '//')) {
            $candidate = substr($candidate, 2);
        }

        if (
            preg_match('#^[a-z][a-z0-9+.-]*://#i', $candidate)
            || strpbrk($candidate, '/\\@?#') !== false
            || preg_match('/\s/', $candidate)
        ) {
            return [null, null];
        }

        if (str_starts_with($candidate, '[')) {
            if (!preg_match('/^\[([^\]]+)](?::(\d+))?$/', $candidate, $matches)) {
                return [null, null];
            }

            if (filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return [null, null];
            }

            $port = isset($matches[2]) ? (int) $matches[2] : null;
            return $this->isValidPort($port) ? [$matches[1], $port] : [null, null];
        }

        if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return [$candidate, null];
        }

        if (preg_match('/^(.+):(\d+)$/', $candidate, $matches)) {
            $port = (int) $matches[2];
            return $this->isValidPort($port) ? [$matches[1], $port] : [null, null];
        }

        return [$candidate, null];
    }

    private function isValidPort(?int $port): bool
    {
        return $port === null || ($port >= 1 && $port <= 65535);
    }

    private function isValidHostname(string $host): bool
    {
        return strlen($host) <= 253
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    private function matchesAllowedHosts(string $host): bool
    {
        if ($this->allowedHosts === null || $this->allowedHosts === []) {
            return true;
        }

        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        foreach ($this->allowedHosts as $rule) {
            if ($isIp) {
                if ($this->isIpRuleMatch($host, $rule)) {
                    return true;
                }
                continue;
            }

            if ($this->isHostnameRuleMatch($host, $rule)) {
                return true;
            }
        }

        return false;
    }

    private function isHostnameRuleMatch(string $host, string $rule): bool
    {
        if (str_starts_with($rule, '**.')) {
            $suffix = substr($rule, 3);
            return $host === $suffix || str_ends_with($host, '.' . $suffix);
        }

        if (str_starts_with($rule, '*.')) {
            $suffix = substr($rule, 2);
            if (!str_ends_with($host, '.' . $suffix)) {
                return false;
            }

            $prefix = substr($host, 0, -strlen($suffix) - 1);
            return $prefix !== '' && !str_contains($prefix, '.');
        }

        return $host === $rule;
    }

    private function isIpRuleMatch(string $ip, string $rule): bool
    {
        if (!str_contains($rule, '/')) {
            return filter_var($rule, FILTER_VALIDATE_IP) !== false
                && inet_pton($ip) === inet_pton($rule);
        }

        [$network, $prefix] = explode('/', $rule, 2);
        $networkBytes = @inet_pton($network);
        $ipBytes = @inet_pton($ip);
        if ($networkBytes === false || $ipBytes === false || strlen($networkBytes) !== strlen($ipBytes)) {
            return false;
        }

        $prefixLength = (int) $prefix;
        $fullBytes = intdiv($prefixLength, 8);
        $remainingBits = $prefixLength % 8;

        if ($fullBytes > 0 && substr($networkBytes, 0, $fullBytes) !== substr($ipBytes, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
        return (ord($networkBytes[$fullBytes]) & $mask) === (ord($ipBytes[$fullBytes]) & $mask);
    }

    private function validateAllowedHosts(): void
    {
        foreach ($this->allowedHosts ?? [] as $rule) {
            if (str_contains($rule, '/')) {
                [$network, $prefix] = array_pad(explode('/', $rule, 2), 2, null);
                $packed = $network !== null ? @inet_pton($network) : false;
                $maxPrefix = $packed === false ? -1 : strlen($packed) * 8;
                if ($packed === false || $prefix === null || !ctype_digit($prefix) || (int) $prefix > $maxPrefix) {
                    throw new ConfigException('Invalid CIDR in mirror allowed_hosts: ' . $rule);
                }
                continue;
            }

            if (filter_var($rule, FILTER_VALIDATE_IP) !== false) {
                continue;
            }

            $hostname = $rule;
            if (str_starts_with($hostname, '**.')) {
                $hostname = substr($hostname, 3);
            } elseif (str_starts_with($hostname, '*.')) {
                $hostname = substr($hostname, 2);
            } elseif (str_contains($hostname, '*')) {
                throw new ConfigException('Invalid wildcard in mirror allowed_hosts: ' . $rule);
            }

            if (!$this->isValidHostname($hostname)) {
                throw new ConfigException('Invalid hostname in mirror allowed_hosts: ' . $rule);
            }
        }
    }

    /**
     * @return string[]
     */
    private static function resolveHost(string $host): array
    {
        $addresses = [];
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    $address = $record['ip'] ?? $record['ipv6'] ?? null;
                    if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false) {
                        $addresses[$address] = true;
                    }
                }
            }
        }

        if ($addresses === []) {
            foreach (@gethostbynamel($host) ?: [] as $address) {
                if (filter_var($address, FILTER_VALIDATE_IP) !== false) {
                    $addresses[$address] = true;
                }
            }
        }

        return array_keys($addresses);
    }
}

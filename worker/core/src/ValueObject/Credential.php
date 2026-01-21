<?php

declare(strict_types=1);

namespace Nod32Mirror\ValueObject;

final readonly class Credential
{
    /**
     * @param string[] $versions
     */
    public function __construct(
        public string $login,
        public string $password,
        public array $versions = []
    ) {
    }

    public function hasVersion(string $version): bool
    {
        return empty($this->versions) || in_array($version, $this->versions, true);
    }

    public function withVersion(string $version): self
    {
        if ($this->hasVersion($version) && !empty($this->versions)) {
            return $this;
        }

        $versions = $this->versions;
        $versions[] = $version;

        return new self($this->login, $this->password, array_values(array_unique($versions)));
    }

    public function withoutVersion(string $version): self
    {
        $versions = array_values(array_filter(
            $this->versions,
            static fn(string $v): bool => $v !== $version
        ));

        return new self($this->login, $this->password, $versions);
    }

    public function toAuthString(): string
    {
        return $this->login . ':' . $this->password;
    }

    public static function fromAuthString(string $auth, ?string $version = null): self
    {
        $parts = explode(':', $auth, 2);
        $login = $parts[0] ?? '';
        $password = $parts[1] ?? '';
        $versions = $version !== null ? [$version] : [];

        return new self($login, $password, $versions);
    }

    /**
     * @return array{login: string, password: string, versions: string[]}
     */
    public function toArray(): array
    {
        return [
            'login' => $this->login,
            'password' => $this->password,
            'versions' => $this->versions,
        ];
    }

    /**
     * @param array{login?: string, password?: string, versions?: string[]} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['login'] ?? '',
            $data['password'] ?? '',
            $data['versions'] ?? []
        );
    }

    public function equals(self $other): bool
    {
        return $this->login === $other->login && $this->password === $other->password;
    }
}

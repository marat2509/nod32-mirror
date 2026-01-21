<?php

declare(strict_types=1);

namespace Nod32Mirror\Config;

use Nod32Mirror\Exception\ConfigKeyNotFoundException;
use Nod32Mirror\Tools;

final class VersionConfig
{
    /**
     * @param array<string, array<string, mixed>> $directories
     */
    public function __construct(
        private readonly Config $config,
        private readonly array $directories
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getVersionConfig(string $version): ?array
    {
        try {
            $overrides = $this->config->get('eset.versions.overrides');
            return $overrides[$version] ?? null;
        } catch (ConfigKeyNotFoundException) {
            return null;
        }
    }

    public function isVersionEnabled(string $version): bool
    {
        $versionConfig = $this->getVersionConfig($version);

        if ($versionConfig === null) {
            return false;
        }

        return !empty($versionConfig['mirror']);
    }

    /**
     * @return string[]|true
     */
    public function getVersionPlatforms(string $version): array|bool
    {
        $versionConfig = $this->getVersionConfig($version);

        if ($versionConfig !== null && isset($versionConfig['platforms'])) {
            $platforms = $this->normalizeListValue($versionConfig['platforms']);

            if (is_array($platforms) && !empty($platforms)) {
                return $platforms;
            }
        }

        try {
            $globalConfig = $this->config->get('eset.versions');

            if (is_array($globalConfig) && isset($globalConfig['platforms'])) {
                $platforms = $this->normalizeListValue($globalConfig['platforms']);

                if (is_array($platforms) && !empty($platforms)) {
                    return $platforms;
                }
            }
        } catch (ConfigKeyNotFoundException) {
            // Ignore
        }

        return true;
    }

    /**
     * @return string[]|true
     */
    public function getVersionChannels(string $version): array|bool
    {
        $versionConfig = $this->getVersionConfig($version);

        if ($versionConfig !== null && isset($versionConfig['channels'])) {
            $channels = $this->normalizeListValue($versionConfig['channels']);

            if (is_array($channels) && !empty($channels)) {
                return $channels;
            }
        }

        try {
            $globalConfig = $this->config->get('eset.versions');

            if (is_array($globalConfig) && isset($globalConfig['channels'])) {
                $channels = $this->normalizeListValue($globalConfig['channels']);

                if (is_array($channels) && !empty($channels)) {
                    return $channels;
                }
            }
        } catch (ConfigKeyNotFoundException) {
            // Ignore
        }

        return true;
    }

    /**
     * @return string[]
     */
    public function getEnabledVersions(): array
    {
        $enabledVersions = [];

        foreach (array_keys($this->directories) as $version) {
            if ($this->isVersionEnabled($version)) {
                $enabledVersions[] = $version;
            }
        }

        return $enabledVersions;
    }

    /**
     * @return string[]|true
     */
    private function normalizeListValue(mixed $value): array|bool
    {
        if ($value === true || $value === null) {
            return true;
        }

        if ($value === false) {
            return [];
        }

        if (is_array($value)) {
            $filtered = array_values(array_filter(array_map('trim', $value), 'strlen'));
            return empty($filtered) ? true : $filtered;
        }

        if (is_string($value)) {
            $parsed = Tools::parseCommaList($value);
            return empty($parsed) ? true : $parsed;
        }

        return true;
    }
}

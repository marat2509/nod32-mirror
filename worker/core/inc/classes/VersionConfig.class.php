<?php

/**
 * Class VersionConfig
 * Handles version-specific configuration from config file
 */
class VersionConfig
{
    /**
     * Get version configuration from config file
     * @param string $version
     * @return array|null
     */
    public static function get_version_config($version)
    {
        return Config::get('eset.versions.overrides.' . $version);
    }

    /**
     * Check if version is enabled for mirroring
     * @param string $version
     * @return bool
     */
    public static function is_version_enabled($version)
    {
        $version_config = self::get_version_config($version);

        if (!$version_config) {
            return false;
        }

        return !empty($version_config['mirror']);
    }


    /**
     * Get platforms for version
     * @param string $version
     * @return array|bool
     */
    public static function get_version_platforms($version)
    {
        // First check version-specific config
        $version_config = self::get_version_config($version);
        if ($version_config && isset($version_config['platforms'])) {
            return self::normalizeListValue($version_config['platforms']);
        }

        // If no version-specific config, check global config
        $global_config = Config::get('eset.versions');
        if ($global_config && isset($global_config['platforms'])) {
            return self::normalizeListValue($global_config['platforms']);
        }

        // No platforms specified anywhere - download all available platforms
        return true;
    }

    /**
     * Get channels for version
     * @param string $version
     * @return array|bool
     */
    public static function get_version_channels($version)
    {
        // First check version-specific config
        $version_config = self::get_version_config($version);
        if ($version_config && isset($version_config['channels'])) {
            return self::normalizeListValue($version_config['channels']);
        }

        // If no version-specific config, check global config
        $global_config = Config::get('eset.versions');
        if ($global_config && isset($global_config['channels'])) {
            return self::normalizeListValue($global_config['channels']);
        }

        // No channels specified anywhere - download all available channels
        return true;
    }

    /**
     * Get all enabled versions
     * @return array
     */
    public static function get_enabled_versions()
    {
        global $DIRECTORIES;
        $enabled_versions = [];

        if (!$DIRECTORIES) {
            return $enabled_versions;
        }

        // Get all versions from directory configuration
        foreach ($DIRECTORIES as $version => $config) {
            if (self::is_version_enabled($version)) {
                $enabled_versions[] = $version;
            }
        }

        return $enabled_versions;
    }

    /**
     * Normalize channel/platform configuration into array or true
     * @param mixed $value
     * @return array|bool
     */
    private static function normalizeListValue($value)
    {
        if ($value === true || $value === null) {
            return true;
        }

        if ($value === false) {
            return [];
        }

        if (is_array($value)) {
            return empty($value) ? true : array_values(array_filter(array_map('trim', $value), 'strlen'));
        }

        if (is_string($value)) {
            $parsed = Tools::parse_comma_list($value);
            return empty($parsed) ? true : $parsed;
        }

        return true;
    }

}

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
        return Config::get('ESET.VERSIONS.' . $version);
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

        return isset($version_config['mirror']) && $version_config['mirror'] == 1;
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
            $platforms = $version_config['platforms'];

            // If platforms is empty, true, or null, return all platforms
            if (empty($platforms) || $platforms === true || $platforms === null) {
                return true;
            }

            return Tools::parse_comma_list($platforms);
        }

        // If no version-specific config, check global config
        $global_config = Config::get('ESET.VERSIONS');
        if ($global_config && isset($global_config['platforms'])) {
            $platforms = $global_config['platforms'];

            // If platforms is empty, true, or null, return all platforms
            if (empty($platforms) || $platforms === true || $platforms === null) {
                return true;
            }

            return Tools::parse_comma_list($platforms);
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
            $channels = $version_config['channels'];

            // If channels is empty, true, or null, return all channels
            if (empty($channels) || $channels === true || $channels === null) {
                return true;
            }

            return Tools::parse_comma_list($channels);
        }

        // If no version-specific config, check global config
        $global_config = Config::get('ESET.VERSIONS');
        if ($global_config && isset($global_config['channels'])) {
            $channels = $global_config['channels'];

            // If channels is empty, true, or null, return all channels
            if (empty($channels) || $channels === true || $channels === null) {
                return true;
            }

            return Tools::parse_comma_list($channels);
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

}

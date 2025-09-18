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
        $version_config = self::get_version_config($version);

        if (!$version_config || !isset($version_config['platforms'])) {
            // No specific config, get platforms from directory config
            global $DIRECTORIES;
            if (isset($DIRECTORIES[$version]['platforms'])) {
                return $DIRECTORIES[$version]['platforms'];
            }
            return true; // No filtering - download all available platforms
        }

        $platforms = $version_config['platforms'];

        // If platforms is empty, true, or null, return all platforms
        if (empty($platforms) || $platforms === true || $platforms === null) {
            return true;
        }

        return Tools::parse_comma_list($platforms);
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

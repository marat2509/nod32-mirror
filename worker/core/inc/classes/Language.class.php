<?php

/**
 * Class Language
 */
class Language
{
    /**
     * @var null
     */
    static private $language = 'en';

    /**
     * @var
     */
    static private $language_file = null;

    /**
     * @var
     */
    static private $language_pack = array();

    /**
     * @var
     */
    static private $default_language_pack = array();

    /**
     * @var
     */
    static private $default_language_file = null;

    /**
     * @var bool
     */
    static private $initialized = false;

    /**
     * @throws Exception
     */
    static public function init()
    {
        if (static::$initialized) {
            return;
        }

        static::bootstrapDefaultPack();

        Config::init();

        $scriptConfig = Config::get('script');

        static::$language = $scriptConfig['language'] ?? 'en';
        static::$language_file = Tools::ds(LANGPACKS_DIR, static::$language . '.json');
        static::$default_language_file = Tools::ds(LANGPACKS_DIR, 'en.json');

        if (static::$language !== 'en') {
            $languagePack = static::loadLanguagePack(static::$language_file, static::$language);
            static::$language_pack = array_replace(static::$default_language_pack, $languagePack);
        } else {
            static::$language_pack = static::$default_language_pack;
        }

        static::$initialized = true;
    }

    /**
     * @return string
     */
    static public function t()
    {
        if (empty(static::$default_language_pack)) {
            static::bootstrapDefaultPack();
        }

        $text = func_get_arg(0);
        $params = func_get_args();
        @array_shift($params);

        $defaultKey = array_search($text, static::$default_language_pack, true);
        $key = ($defaultKey !== false) ? $defaultKey : $text;

        $default = static::$default_language_pack[$key] ?? $text;
        $translation = static::$language_pack[$key] ?? $default;

        if (!static::placeholdersMatch($default, $translation)) {
            $translation = $default;
        }

        return vsprintf($translation, $params);
    }

    /**
     * @param string $path
     * @param string $code
     * @return array
     * @throws Exception
     */
    static private function loadLanguagePack($path, $code)
    {
        if (!file_exists($path)) {
            throw new Exception("Language file [" . $code . ".json] does not exist!");
        }

        $content = file_get_contents($path);
        $decoded = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new Exception("Language file [" . $code . ".json] is corrupted!");
        }

        return $decoded;
    }

    /**
     * Ensure english language pack is available for fallbacks
     * @throws Exception
     */
    static private function bootstrapDefaultPack()
    {
        if (!empty(static::$default_language_pack)) {
            return;
        }

        static::$default_language_file = Tools::ds(LANGPACKS_DIR, 'en.json');
        static::$default_language_pack = static::loadLanguagePack(static::$default_language_file, 'en');

        if (empty(static::$language_pack)) {
            static::$language_pack = static::$default_language_pack;
        }
    }

    /**
     * @param string $expected
     * @param string $actual
     * @return bool
     */
    static private function placeholdersMatch($expected, $actual)
    {
        return static::countPlaceholders($expected) === static::countPlaceholders($actual);
    }

    /**
     * @param string $string
     * @return int
     */
    static private function countPlaceholders($string)
    {
        if (!is_string($string)) {
            return 0;
        }

        return preg_match_all('/%(?:\\d+\\$)?[bcdeEfFgGosuxX]/', $string, $matches);
    }
}

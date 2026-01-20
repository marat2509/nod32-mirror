<?php

declare(strict_types=1);

namespace Nod32Mirror\Log;

use Nod32Mirror\Exception\LanguageException;
use Nod32Mirror\Tools;

final class Language
{
    private string $languageCode = 'en';

    /** @var array<string, string> */
    private array $languagePack = [];

    /** @var array<string, string> */
    private array $defaultPack = [];

    private bool $initialized = false;

    public function __construct(
        private readonly string $langpacksDir = LANGPACKS_DIR
    ) {
    }

    /**
     * @throws LanguageException
     */
    public function init(string $languageCode = 'en'): void
    {
        if ($this->initialized && $this->languageCode === $languageCode) {
            return;
        }

        $this->loadDefaultPack();

        $this->languageCode = $languageCode;

        if ($languageCode !== 'en') {
            $langFile = Tools::ds($this->langpacksDir, $languageCode . '.json');
            $langPack = $this->loadLanguagePack($langFile, $languageCode);
            $this->languagePack = array_replace($this->defaultPack, $langPack);
        } else {
            $this->languagePack = $this->defaultPack;
        }

        $this->initialized = true;
    }

    /**
     * Translate a key with optional parameters
     */
    public function t(string $key, mixed ...$params): string
    {
        if (empty($this->defaultPack)) {
            $this->loadDefaultPack();
        }

        $default = $this->defaultPack[$key] ?? $key;
        $translation = $this->languagePack[$key] ?? $default;

        if (!$this->placeholdersMatch($default, $translation)) {
            $translation = $default;
        }

        if (empty($params)) {
            return $translation;
        }

        return vsprintf($translation, $params);
    }

    /**
     * @throws LanguageException
     */
    private function loadDefaultPack(): void
    {
        if (!empty($this->defaultPack)) {
            return;
        }

        $defaultFile = Tools::ds($this->langpacksDir, 'en.json');
        $this->defaultPack = $this->loadLanguagePack($defaultFile, 'en');

        if (empty($this->languagePack)) {
            $this->languagePack = $this->defaultPack;
        }
    }

    /**
     * @return array<string, string>
     * @throws LanguageException
     */
    private function loadLanguagePack(string $path, string $code): array
    {
        if (!file_exists($path)) {
            throw new LanguageException(sprintf('Language file [%s.json] does not exist', $code));
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new LanguageException(sprintf('Failed to read language file [%s.json]', $code));
        }

        $decoded = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new LanguageException(sprintf('Language file [%s.json] is corrupted', $code));
        }

        return $decoded;
    }

    private function placeholdersMatch(string $expected, string $actual): bool
    {
        return $this->countPlaceholders($expected) === $this->countPlaceholders($actual);
    }

    private function countPlaceholders(string $string): int
    {
        return preg_match_all('/%(?:\d+\$)?[bcdeEfFgGosuxX]/', $string);
    }

    public function getLanguageCode(): string
    {
        return $this->languageCode;
    }
}

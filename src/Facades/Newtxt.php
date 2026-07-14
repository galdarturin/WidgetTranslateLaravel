<?php

namespace Newtxt\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Newtxt\Laravel\NewtxtManager;

/**
 * @method static string widgetSnippet(array $attributes = [])
 * @method static array|null renderPage(string $languageCode, string $path, array $options = [])
 * @method static array|null rememberRenderedPage(string $languageCode, string $path, array $options = [])
 * @method static void clearRenderedPageCache(?string $languageCode = null, ?string $path = null)
 * @method static int syncHashedTranslations(string $languageCode, string $path, array $options = [])
 * @method static array putHashedTranslation(string $languageCode, string $sourceText, string $translatedText, array $metadata = [])
 * @method static array|null hashedTranslation(string $languageCode, string $sourceText)
 * @method static array|null recordSourcePage(string $path, string $html, array $options = [])
 * @method static array accountSettings(bool $forceRefresh = false)
 * @method static array targetLanguages()
 */
class Newtxt extends Facade
{
    /**
     * Return the service container binding used by the facade.
     */
    protected static function getFacadeAccessor(): string
    {
        return NewtxtManager::class;
    }
}

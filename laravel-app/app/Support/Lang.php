<?php

namespace App\Support;

/**
 * Language of the current API request: ?lang=en, Russian by default (SetLanguage middleware).
 * Russian content is the original; another language shows the translations of it (Polylang).
 */
class Lang
{
    public const DEFAULT = 'ru';

    public const ALL = ['ru', 'en'];

    private static string $current = self::DEFAULT;

    public static function set(string $lang): void
    {
        self::$current = in_array($lang, self::ALL, true) ? $lang : self::DEFAULT;
    }

    public static function current(): string
    {
        return self::$current;
    }

    public static function isDefault(): bool
    {
        return self::$current === self::DEFAULT;
    }

    /** A cache key of the current language: Russian keys stay as they were. */
    public static function key(string $key): string
    {
        return self::isDefault() ? $key : self::$current.':'.$key;
    }

    /** $russian or its English text, for the few API texts not kept in WordPress. */
    public static function text(string $russian, string $english): string
    {
        return self::isDefault() ? $russian : $english;
    }
}

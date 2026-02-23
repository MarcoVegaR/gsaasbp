<?php

declare(strict_types=1);

namespace App\Support;

final class I18nCatalog
{
    /**
     * @var array<string, array<string, string>>
     */
    private static array $cache = [];

    /**
     * @return array<string, mixed>
     */
    public static function core(string $locale): array
    {
        return self::extract($locale, 'core.');
    }

    /**
     * @return array<string, mixed>
     */
    public static function page(string $locale, string $pageKey): array
    {
        return self::extract($locale, 'page.'.$pageKey.'.');
    }

    /**
     * @return array<string, mixed>
     */
    private static function extract(string $locale, string $prefix): array
    {
        $catalog = self::loadLocaleCatalog($locale);
        $dictionary = [];

        foreach ($catalog as $key => $value) {
            if (! str_starts_with($key, $prefix)) {
                continue;
            }

            $trimmedKey = substr($key, strlen($prefix));

            if ($trimmedKey === '' || $value === '') {
                continue;
            }

            self::setNestedValue($dictionary, $trimmedKey, $value);
        }

        return $dictionary;
    }

    /**
     * @return array<string, string>
     */
    private static function loadLocaleCatalog(string $locale): array
    {
        if (array_key_exists($locale, self::$cache)) {
            return self::$cache[$locale];
        }

        $path = lang_path($locale.'.json');

        if (! is_file($path)) {
            return self::$cache[$locale] = [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            return self::$cache[$locale] = [];
        }

        $normalized = [];

        foreach ($decoded as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $normalized[$key] = $value;
            }
        }

        return self::$cache[$locale] = $normalized;
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private static function setNestedValue(array &$target, string $dotKey, string $value): void
    {
        $segments = array_values(array_filter(explode('.', $dotKey), static fn (string $segment): bool => $segment !== ''));

        if ($segments === []) {
            return;
        }

        $cursor = &$target;
        $lastSegment = array_pop($segments);

        foreach ($segments as $segment) {
            if (! isset($cursor[$segment]) || ! is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }

        if ($lastSegment !== null) {
            $cursor[$lastSegment] = $value;
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Support\Sso;

use InvalidArgumentException;

final class RedirectPathGuard
{
    public static function normalize(string $rawPath): string
    {
        $path = trim($rawPath);

        if ($path === '') {
            return '/';
        }

        if (! str_starts_with($path, '/')) {
            throw new InvalidArgumentException('Invalid redirect path.');
        }

        if (str_starts_with($path, '//') || str_contains($path, '\\')) {
            throw new InvalidArgumentException('Invalid redirect path.');
        }

        $lowerPath = strtolower($path);

        if (str_contains($lowerPath, '%2f%2f') || str_contains($lowerPath, '%5c')) {
            throw new InvalidArgumentException('Invalid redirect path.');
        }

        $decoded = rawurldecode($path);

        $decodedTwice = rawurldecode($decoded);

        if (str_starts_with($decoded, '//') || str_contains($decoded, '\\')) {
            throw new InvalidArgumentException('Invalid redirect path.');
        }

        if (str_starts_with($decodedTwice, '//') || str_contains($decodedTwice, '\\')) {
            throw new InvalidArgumentException('Invalid redirect path.');
        }

        $parts = parse_url($path);

        if ($parts === false) {
            throw new InvalidArgumentException('Invalid redirect path.');
        }

        if (isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Invalid redirect path.');
        }

        $normalizedPath = $parts['path'] ?? '/';

        if ($normalizedPath === '' || ! str_starts_with($normalizedPath, '/')) {
            throw new InvalidArgumentException('Invalid redirect path.');
        }

        if (str_starts_with($normalizedPath, '//')) {
            throw new InvalidArgumentException('Invalid redirect path.');
        }

        $query = $parts['query'] ?? null;

        return $query === null ? $normalizedPath : $normalizedPath.'?'.$query;
    }
}

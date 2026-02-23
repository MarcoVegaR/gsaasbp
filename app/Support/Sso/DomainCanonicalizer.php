<?php

declare(strict_types=1);

namespace App\Support\Sso;

use InvalidArgumentException;

final class DomainCanonicalizer
{
    public static function canonicalize(string $domain): string
    {
        $normalized = strtolower(trim($domain));
        $normalized = rtrim($normalized, '.');

        if ($normalized === '') {
            throw new InvalidArgumentException('Invalid domain.');
        }

        if (str_contains($normalized, '://') || str_contains($normalized, '/')) {
            throw new InvalidArgumentException('Invalid domain.');
        }

        $ascii = $normalized;

        if (function_exists('idn_to_ascii')) {
            $idna = idn_to_ascii($normalized, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            if (! is_string($idna) || $idna === '') {
                throw new InvalidArgumentException('Invalid domain.');
            }

            $ascii = strtolower($idna);
        }

        if (filter_var($ascii, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new InvalidArgumentException('Invalid domain.');
        }

        return $ascii;
    }
}

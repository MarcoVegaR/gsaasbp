<?php

declare(strict_types=1);

use App\Support\Sso\RedirectPathGuard;

it('accepts only strict relative redirect paths', function () {
    expect(RedirectPathGuard::normalize('/tenant/dashboard'))->toBe('/tenant/dashboard');
    expect(RedirectPathGuard::normalize('/tenant/dashboard?tab=security'))->toBe('/tenant/dashboard?tab=security');
    expect(RedirectPathGuard::normalize(''))->toBe('/');
});

it('rejects absolute urls and parser mismatch payloads', function (string $path) {
    expect(fn () => RedirectPathGuard::normalize($path))
        ->toThrow(InvalidArgumentException::class, 'Invalid redirect path.');
})->with([
    '//dashboard',
    '\\dashboard',
    '/%2f%2fdashboard',
    '/%5cdashboard',
    '/%252f%252fdashboard',
    'https://evil.example/steal',
    'dashboard',
]);

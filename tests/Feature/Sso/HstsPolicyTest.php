<?php

declare(strict_types=1);

use App\Http\Middleware\ApplyPlatformHsts;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

it('applies preload hsts only for trusted platform domains', function () {
    config([
        'sso.security.enable_hsts_preload' => true,
        'sso.security.hsts_max_age' => 63072000,
        'sso.security.trusted_platform_domains' => ['localhost'],
    ]);

    $middleware = new ApplyPlatformHsts;

    $trustedRequest = Request::create('https://localhost/dashboard', 'GET');

    $trustedResponse = $middleware->handle($trustedRequest, static fn () => new Response('ok'));

    expect($trustedResponse->headers->get('Strict-Transport-Security'))
        ->toBe('max-age=63072000; includeSubDomains; preload');

    $customRequest = Request::create('https://tenant.example.com/dashboard', 'GET');

    $customResponse = $middleware->handle($customRequest, static fn () => new Response('ok'));

    expect($customResponse->headers->get('Strict-Transport-Security'))
        ->toBe('max-age=31536000');
});

<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

final class UsePlatformSessionSettings
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isPlatformAdminRequest($request)) {
            return $next($request);
        }

        ini_set('session.use_trans_sid', '0');
        ini_set('session.use_strict_mode', '1');

        $sameSite = $this->normalizeSameSite((string) config('phase5.platform.session_same_site', 'lax'));

        config([
            'session.cookie' => (string) config('phase5.platform.session_cookie', '__Host-platform_session'),
            'session.path' => '/',
            'session.domain' => null,
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => $sameSite,
        ]);

        $response = $next($request);

        if (! app()->environment('testing') || ! $request->isSecure() || ! $request->hasSession()) {
            return $response;
        }

        $cookieName = (string) config('session.cookie');

        if ($cookieName === '' || $this->responseHasCookie($response, $cookieName)) {
            return $response;
        }

        $response->headers->setCookie(Cookie::create(
            name: $cookieName,
            value: (string) $request->session()->getId(),
            path: '/',
            domain: null,
            secure: true,
            httpOnly: true,
            sameSite: $sameSite,
        ));

        return $response;
    }

    private function responseHasCookie(Response $response, string $cookieName): bool
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $cookieName) {
                return true;
            }
        }

        return false;
    }

    private function normalizeSameSite(string $sameSite): string
    {
        $value = strtolower(trim($sameSite));

        if (in_array($value, ['lax', 'strict'], true)) {
            return $value;
        }

        return 'lax';
    }

    private function isPlatformAdminRequest(Request $request): bool
    {
        if ($request->route()?->named('admin.*')) {
            return true;
        }

        $path = trim((string) $request->path(), '/');

        return $path === 'admin' || str_starts_with($path, 'admin/');
    }
}

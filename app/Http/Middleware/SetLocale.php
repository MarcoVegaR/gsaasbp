<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $cookieName = (string) config('app.locale_cookie', 'locale');
        $supportedLocales = $this->supportedLocales();

        $resolvedLocale = null;
        $shouldPersistCookie = false;

        if ($request->query->has('lang')) {
            $lang = (string) $request->query('lang', '');

            if (! in_array($lang, $supportedLocales, true)) {
                abort(422, 'Invalid locale.');
            }

            $resolvedLocale = $lang;
            $shouldPersistCookie = true;
        }

        if ($resolvedLocale === null) {
            $cookieLocale = (string) $request->cookie($cookieName, '');
            if ($cookieLocale !== '' && in_array($cookieLocale, $supportedLocales, true)) {
                $resolvedLocale = $cookieLocale;
            }
        }

        if ($resolvedLocale === null) {
            $userLocale = $request->user()?->locale;
            if (is_string($userLocale) && in_array($userLocale, $supportedLocales, true)) {
                $resolvedLocale = $userLocale;
            }
        }

        if ($resolvedLocale === null) {
            $resolvedLocale = (string) config('app.locale_default', config('app.locale', 'en'));
        }

        app()->setLocale($resolvedLocale);
        $request->attributes->set('resolved_locale', $resolvedLocale);

        /** @var Response $response */
        $response = $next($request);

        if ($shouldPersistCookie) {
            $secure = $request->isSecure() || app()->isProduction();

            $response->headers->setCookie(new Cookie(
                name: $cookieName,
                value: $resolvedLocale,
                expire: now()->addYear(),
                path: '/',
                domain: null,
                secure: $secure,
                httpOnly: false,
                raw: false,
                sameSite: Cookie::SAMESITE_LAX,
            ));
        }

        return $response;
    }

    /**
     * @return array<int, string>
     */
    private function supportedLocales(): array
    {
        $configured = config('app.supported_locales', [config('app.locale', 'en')]);

        if (! is_array($configured)) {
            return [(string) config('app.locale', 'en')];
        }

        $normalized = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => is_string($value) ? trim($value) : '',
            $configured,
        ), static fn (string $value): bool => $value !== '')));

        return $normalized !== [] ? $normalized : [(string) config('app.locale', 'en')];
    }
}

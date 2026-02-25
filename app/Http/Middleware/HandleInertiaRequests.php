<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\I18nCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $locale = $this->resolvedLocale($request);

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'locale' => $locale,
            'supportedLocales' => $this->supportedLocales(),
            'auth' => [
                'user' => $request->user(),
                'guard' => $this->resolvedGuard($request),
            ],
            'impersonation' => $this->resolvedImpersonationContext($request),
            'coreDictionary' => I18nCatalog::core($locale),
            'pageDictionary' => Inertia::defer(
                fn (): array => I18nCatalog::page($locale, $this->resolvePageDictionaryKey($request)),
                'i18n',
            ),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    private function resolvedLocale(Request $request): string
    {
        $resolvedLocale = $request->attributes->get('resolved_locale');

        if (is_string($resolvedLocale) && $resolvedLocale !== '') {
            return $resolvedLocale;
        }

        return (string) config('app.locale_default', config('app.locale', 'en'));
    }

    /**
     * @return array<int, string>
     */
    private function supportedLocales(): array
    {
        $configured = config('app.supported_locales', [config('app.locale_default', config('app.locale', 'en'))]);

        if (! is_array($configured)) {
            return [(string) config('app.locale_default', config('app.locale', 'en'))];
        }

        $normalized = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => is_string($value) ? trim($value) : '',
            $configured,
        ), static fn (string $value): bool => $value !== '')));

        return $normalized !== []
            ? $normalized
            : [(string) config('app.locale_default', config('app.locale', 'en'))];
    }

    private function resolvePageDictionaryKey(Request $request): string
    {
        $path = trim($request->path(), '/');

        if ($path === '') {
            return 'home';
        }

        return str_replace('/', '.', $path);
    }

    private function resolvedGuard(Request $request): string
    {
        if ($request->user('platform') !== null || Auth::guard('platform')->check()) {
            return 'platform';
        }

        return 'web';
    }

    /**
     * @return array<string, string|null>
     */
    private function resolvedImpersonationContext(Request $request): array
    {
        $context = $request->session()->get('phase5.impersonation');

        if (! is_array($context)) {
            return [
                'is_impersonating' => 'false',
                'actor_platform_user_id' => null,
                'subject_user_id' => null,
                'impersonation_ticket_id' => null,
            ];
        }

        return [
            'is_impersonating' => (string) ($context['is_impersonating'] ?? 'false'),
            'actor_platform_user_id' => isset($context['actor_platform_user_id']) ? trim((string) $context['actor_platform_user_id']) : null,
            'subject_user_id' => isset($context['subject_user_id']) ? trim((string) $context['subject_user_id']) : null,
            'impersonation_ticket_id' => isset($context['impersonation_ticket_id']) ? trim((string) $context['impersonation_ticket_id']) : null,
            'jti' => isset($context['jti']) ? trim((string) $context['jti']) : null,
        ];
    }
}

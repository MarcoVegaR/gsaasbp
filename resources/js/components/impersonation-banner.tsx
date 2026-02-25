import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import tenant from '@/routes/tenant';

function getCookie(name: string): string | null {
    const cookieEntry = document.cookie
        .split(';')
        .map((entry) => entry.trim())
        .find((entry) => entry.startsWith(`${name}=`));

    if (cookieEntry === undefined) {
        return null;
    }

    return decodeURIComponent(cookieEntry.slice(name.length + 1));
}

function errorMessageFromPayload(payload: unknown): string {
    if (payload !== null && typeof payload === 'object') {
        const candidate = payload as Record<string, unknown>;

        if (typeof candidate.message === 'string' && candidate.message.trim() !== '') {
            return candidate.message.trim();
        }

        if (typeof candidate.code === 'string' && candidate.code.trim() !== '') {
            return candidate.code.trim();
        }
    }

    return 'Unable to terminate impersonation session.';
}

export function ImpersonationBanner() {
    const { auth, impersonation } = usePage().props;
    const [isBusy, setIsBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const currentGuard = typeof auth?.guard === 'string' ? auth.guard : 'web';
    const currentJti = typeof impersonation?.jti === 'string' ? impersonation.jti.trim() : '';
    const isBreakGlassActive =
        currentGuard === 'web' &&
        impersonation?.is_impersonating === 'true' &&
        currentJti !== '';

    if (!isBreakGlassActive) {
        return null;
    }

    const terminateImpersonation = async (): Promise<void> => {
        if (isBusy) {
            return;
        }

        setIsBusy(true);
        setError(null);

        try {
            const xsrfToken = getCookie('XSRF-TOKEN');
            const response = await fetch(tenant.phase7.impersonation.terminate().url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(xsrfToken !== null ? { 'X-XSRF-TOKEN': xsrfToken } : {}),
                },
                body: JSON.stringify({}),
            });

            if (!response.ok) {
                const payload = await response
                    .json()
                    .catch(() => ({ message: 'Request failed.' }));

                throw new Error(errorMessageFromPayload(payload));
            }

            window.location.reload();
        } catch (caughtError: unknown) {
            setError(
                caughtError instanceof Error && caughtError.message.trim() !== ''
                    ? caughtError.message
                    : 'Unable to terminate impersonation session.',
            );
        } finally {
            setIsBusy(false);
        }
    };

    return (
        <div className="px-4 pt-4">
            <Alert
                variant="destructive"
                className="border-red-700 bg-red-700/95 text-white [&_[data-slot=alert-description]]:text-red-100"
            >
                <AlertTitle className="text-white">
                    Sesion Break-Glass Activa
                </AlertTitle>
                <AlertDescription className="gap-2">
                    <p>
                        This tenant session is currently impersonated by a
                        platform operator for an approved support workflow.
                    </p>
                    <p className="font-mono text-xs text-red-100/90">
                        actor_platform_user_id:{' '}
                        {impersonation.actor_platform_user_id ?? '-'} | jti:{' '}
                        {currentJti}
                    </p>
                    <Button
                        type="button"
                        size="sm"
                        variant="secondary"
                        disabled={isBusy}
                        onClick={() => void terminateImpersonation()}
                    >
                        {isBusy ? <Spinner className="mr-2" /> : null}
                        Terminate impersonation now
                    </Button>
                    {error !== null ? (
                        <p className="text-xs text-red-100">
                            {error}
                        </p>
                    ) : null}
                </AlertDescription>
            </Alert>
        </div>
    );
}

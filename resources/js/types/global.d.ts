import type { Auth, I18nSharedProps, ImpersonationContext } from '@/types';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: I18nSharedProps & {
            name: string;
            auth: Auth;
            impersonation: ImpersonationContext;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}

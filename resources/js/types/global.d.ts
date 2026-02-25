import type {
    Auth,
    I18nSharedProps,
    ImpersonationContext,
    TenantModuleNavItem,
} from '@/types';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: I18nSharedProps & {
            name: string;
            auth: Auth;
            impersonation: ImpersonationContext;
            sidebarOpen: boolean;
            tenantModules: TenantModuleNavItem[];
            [key: string]: unknown;
        };
    }
}

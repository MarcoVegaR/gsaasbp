import { I18nPageDictionaryBridge } from '@/i18n/page-dictionary-bridge';
import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import type { AppLayoutProps } from '@/types';

export default ({ children, breadcrumbs, ...props }: AppLayoutProps) => (
    <AppLayoutTemplate breadcrumbs={breadcrumbs} {...props}>
        <I18nPageDictionaryBridge />
        {children}
    </AppLayoutTemplate>
);

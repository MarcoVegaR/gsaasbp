import { Link, usePage } from '@inertiajs/react';
import { BookOpen, Folder, LayoutGrid, Settings2 } from 'lucide-react';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import admin from '@/routes/admin';
import { dashboard } from '@/routes';
import tenant from '@/routes/tenant';
import type { NavItem } from '@/types';
import AppLogo from './app-logo';

type TenantModuleNavItem = {
    title: string;
    href: string;
};

type SidebarPageProps = {
    tenantModules?: TenantModuleNavItem[];
};

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/react-starter-kit',
        icon: Folder,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#react',
        icon: BookOpen,
    },
];

export function AppSidebar() {
    const page = usePage<SidebarPageProps>();
    const isAdminArea = page.url.startsWith('/admin');
    const isTenantArea = !isAdminArea && page.url.startsWith('/tenant');

    const tenantModules = Array.isArray(page.props.tenantModules)
        ? page.props.tenantModules.filter(
              (item): item is TenantModuleNavItem =>
                  typeof item?.title === 'string' && item.title !== '' &&
                  typeof item?.href === 'string' && item.href !== '',
          )
        : [];

    const mainNavItems: NavItem[] = isTenantArea
        ? [
              {
                  title: 'Tenant dashboard',
                  href: tenant.dashboard(),
                  icon: LayoutGrid,
              },
              {
                  title: 'Workspace settings',
                  href: tenant.settings(),
                  icon: Settings2,
              },
              ...tenantModules.map((module) => ({
                  title: module.title,
                  href: module.href,
                  icon: Folder,
              })),
          ]
        : isAdminArea
          ? [
                {
                    title: 'Central admin panel',
                    href: admin.panel(),
                    icon: LayoutGrid,
                },
            ]
        : [
              {
                  title: 'Dashboard',
                  href: dashboard(),
                  icon: LayoutGrid,
              },
          ];

    const homeHref = isTenantArea
        ? tenant.dashboard()
        : isAdminArea
          ? admin.panel()
          : dashboard();

    const navLabel = isTenantArea
        ? 'Tenant workspace'
        : isAdminArea
          ? 'Platform admin'
          : 'Platform';

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={homeHref} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} label={navLabel} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}

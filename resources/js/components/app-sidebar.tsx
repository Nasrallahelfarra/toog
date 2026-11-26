import { Link, usePage } from '@inertiajs/react';
import { BadgeDollarSign, Bell, LayoutDashboard, PackageCheck, ScrollText, Settings2, Store, Users, UsersRound } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import type { NavGroup, NavLink } from '@/components/nav-main';
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
import { useI18n } from '@/hooks/use-i18n';
import { useStoreAccess } from '@/hooks/use-store-access';
import { STORE_SECTION_GROUPS, STORE_SECTIONS } from '@/lib/store-sections';

/**
 * Two navigation sets: the platform admin panel (/admin) and the store
 * dashboard (/store). The admin sees the store set while inside a store.
 * A staff member sees only the pages the store owner granted them, plus
 * their own products and notifications.
 */
export function AppSidebar() {
    const { t, isRtl } = useI18n();
    const { url, props } = usePage();
    const { staff, can, home: storeHome } = useStoreAccess();
    const unreadNotifications = props.unreadNotifications;
    const connection = props.currentStore?.connection_status;
    const connectionDot = connection === 'connected' ? 'success' : connection === 'error' ? 'danger' : 'muted';
    const inAdmin = url.startsWith('/admin');

    const adminGroups: NavGroup[] = [
        {
            title: t.nav.platform,
            items: [
                { title: t.nav.adminDashboard, href: '/admin', icon: LayoutDashboard, exact: true },
                { title: t.nav.stores, href: '/admin/stores', icon: Store },
                { title: t.nav.users, href: '/admin/users', icon: Users },
                { title: t.nav.aiCosts, href: '/admin/ai-costs', icon: BadgeDollarSign },
                { title: t.nav.activityLog, href: '/admin/activity', icon: ScrollText },
                { title: t.nav.platformSettings, href: '/admin/settings', icon: Settings2 },
            ],
        },
    ];

    const page = (section: string): NavLink => {
        const meta = STORE_SECTIONS[section];

        return {
            title: t.nav[meta.label],
            href: meta.href,
            icon: meta.icon,
            exact: meta.exact,
            exclude: meta.exclude,
            dot: section === 'connection' ? connectionDot : undefined,
        };
    };

    // Pages every member has, slotted beside the pages the owner grants.
    const extras: Record<string, { before?: NavLink[]; after?: Record<string, NavLink[]> }> = {
        store: { before: staff ? [{ title: t.nav.myProducts, href: '/store/my-products', icon: PackageCheck }] : [] },
        management: {
            after: {
                reports: [{ title: t.nav.notifications, href: '/store/notifications', icon: Bell, badge: unreadNotifications }],
            },
        },
    };

    const storeGroups: NavGroup[] = STORE_SECTION_GROUPS.map((group) => {
        const extra = extras[group.key] ?? {};
        const items = group.sections.flatMap((section) => [...(can(section) ? [page(section)] : []), ...(extra.after?.[section] ?? [])]);

        return { title: t.nav[group.label], items: [...(extra.before ?? []), ...items] };
    }).filter((group) => group.items.length > 0);

    const home = inAdmin ? '/admin' : storeHome;

    return (
        <Sidebar collapsible="icon" variant="inset" side={isRtl ? 'right' : 'left'}>
            <SidebarHeader className="pb-3">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild className="hover:bg-transparent">
                            <Link href={home} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain groups={inAdmin ? adminGroups : storeGroups} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}

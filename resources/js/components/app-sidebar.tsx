import { Link, usePage } from '@inertiajs/react';
import {
    Bell,
    BadgeDollarSign,
    CalendarDays,
    FileBarChart2,
    History,
    Images,
    LayoutDashboard,
    LineChart,
    PlugZap,
    Radar,
    Scale,
    ScanSearch,
    ScrollText,
    Settings,
    Settings2,
    Store,
    Swords,
    Users,
    WandSparkles,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import type { NavGroup } from '@/components/nav-main';
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

/**
 * Two navigation sets: the platform admin panel (/admin) and the store
 * dashboard (/store). The admin sees the store set while inside a store.
 */
export function AppSidebar() {
    const { t, isRtl } = useI18n();
    const { url, props } = usePage();
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

    const storeGroups: NavGroup[] = [
        {
            title: t.nav.store,
            items: [
                { title: t.nav.dashboard, href: '/store', icon: LayoutDashboard, exact: true },
                { title: t.nav.performance, href: '/store/performance', icon: LineChart },
                { title: t.nav.connection, href: '/store/settings/integration', icon: PlugZap, dot: connectionDot },
            ],
        },
        {
            title: t.nav.decisions,
            items: [
                { title: t.nav.evaluations, href: '/store/evaluations', icon: ScanSearch },
                { title: t.nav.studio, href: '/store/studio', icon: WandSparkles },
                { title: t.nav.library, href: '/store/library', icon: Images },
                { title: t.nav.balance, href: '/store/balance', icon: Scale },
            ],
        },
        {
            title: t.nav.intelligence,
            items: [
                { title: t.nav.market, href: '/store/market', icon: Radar },
                { title: t.nav.competitors, href: '/store/competitors', icon: Swords },
                { title: t.nav.calendar, href: '/store/calendar', icon: CalendarDays },
            ],
        },
        {
            title: t.nav.management,
            items: [
                { title: t.nav.reports, href: '/store/reports', icon: FileBarChart2 },
                { title: t.nav.notifications, href: '/store/notifications', icon: Bell, badge: unreadNotifications },
                { title: t.nav.activity, href: '/store/activity', icon: History },
                { title: t.nav.storeSettings, href: '/store/settings', icon: Settings, exclude: ['/store/settings/integration'] },
            ],
        },
    ];

    const home = inAdmin ? '/admin' : '/store';

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

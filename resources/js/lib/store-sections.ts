import {
    CalendarDays,
    FileBarChart2,
    History,
    Images,
    LayoutDashboard,
    LayoutGrid,
    LineChart,
    PlugZap,
    Radar,
    Scale,
    ScanSearch,
    Settings,
    Swords,
    Turtle,
    WandSparkles,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { Dictionary } from '@/i18n';

type NavKey = keyof Dictionary['nav'];

/**
 * The store dashboard's pages, keyed as the server keys them
 * (App\Support\StoreSections). The sidebar and the team's permission form
 * both read this list, so they cannot drift. The owner grants staff any of
 * them except "connection": the Zid connection is the owner's alone, so the
 * server never offers it and only the owner sees it in the sidebar.
 */
export const STORE_SECTIONS: Record<string, { href: string; icon: LucideIcon; label: NavKey; exact?: boolean; exclude?: string[] }> = {
    dashboard: { href: '/store', icon: LayoutDashboard, label: 'dashboard', exact: true },
    performance: { href: '/store/performance', icon: LineChart, label: 'performance' },
    catalog: { href: '/store/catalog', icon: LayoutGrid, label: 'catalog' },
    stagnant: { href: '/store/stagnant', icon: Turtle, label: 'stagnant' },
    connection: { href: '/store/settings/integration', icon: PlugZap, label: 'connection' },
    evaluations: { href: '/store/evaluations', icon: ScanSearch, label: 'evaluations' },
    studio: { href: '/store/studio', icon: WandSparkles, label: 'studio' },
    library: { href: '/store/library', icon: Images, label: 'library' },
    balance: { href: '/store/balance', icon: Scale, label: 'balance' },
    market: { href: '/store/market', icon: Radar, label: 'market' },
    competitors: { href: '/store/competitors', icon: Swords, label: 'competitors' },
    calendar: { href: '/store/calendar', icon: CalendarDays, label: 'calendar' },
    reports: { href: '/store/reports', icon: FileBarChart2, label: 'reports' },
    activity: { href: '/store/activity', icon: History, label: 'activity' },
    settings: { href: '/store/settings', icon: Settings, label: 'storeSettings', exclude: ['/store/settings/integration'] },
};

/** Sidebar groups, in order, with the nav key of each group's title. */
export const STORE_SECTION_GROUPS: { key: string; label: NavKey; sections: string[] }[] = [
    { key: 'store', label: 'store', sections: ['dashboard', 'performance', 'catalog', 'stagnant', 'connection'] },
    { key: 'decisions', label: 'decisions', sections: ['evaluations', 'studio', 'library', 'balance'] },
    { key: 'intelligence', label: 'intelligence', sections: ['market', 'competitors', 'calendar'] },
    { key: 'management', label: 'management', sections: ['reports', 'activity', 'settings'] },
];

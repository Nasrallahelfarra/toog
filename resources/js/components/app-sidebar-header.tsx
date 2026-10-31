import { Link, router, usePage } from '@inertiajs/react';
import { Bell, Check, ChevronsUpDown, Languages, Sparkles, Store, TriangleAlert } from 'lucide-react';
import { AnimatePresence, motion } from 'motion/react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useI18n } from '@/hooks/use-i18n';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({ breadcrumbs = [] }: { breadcrumbs?: BreadcrumbItemType[] }) {
    const { t } = useI18n();
    const { url } = usePage();
    const inAdmin = url.startsWith('/admin');

    return (
        <header className="bg-background/80 sticky top-0 z-20 flex h-16 shrink-0 items-center justify-between gap-3 border-b border-border/60 px-4 backdrop-blur-md transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-14 md:rounded-t-xl md:px-6">
            <div className="flex min-w-0 items-center gap-2">
                <SidebarTrigger className="-ms-1 rtl:rotate-180" />
                {breadcrumbs.length > 0 && <Breadcrumbs breadcrumbs={breadcrumbs} />}
                {!inAdmin && <StoreSwitcher />}
            </div>

            <div className="flex items-center gap-1.5">
                <AiModeBadge />
                <LanguageToggle />
                {!inAdmin && <NotificationBell />}
            </div>
        </header>
    );
}

function StoreSwitcher() {
        const { t } = useI18n();
        const { currentStore, stores } = usePage().props;

        if (!currentStore) {
            return null;
        }

        const label = (
            <span className="flex min-w-0 items-center gap-2">
                <span className="bg-primary/8 text-primary dark:bg-primary/15 flex size-7 shrink-0 items-center justify-center rounded-lg">
                    <Store className="size-4" />
                </span>
                <span className="truncate text-sm font-medium">{currentStore.name}</span>
            </span>
        );

        if (stores.length <= 1) {
            return <div className="ms-1 hidden min-w-0 sm:block">{label}</div>;
        }

        return (
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant="ghost" className="ms-1 h-9 min-w-0 gap-2 px-2">
                        {label}
                        <ChevronsUpDown className="text-muted-foreground size-3.5" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" className="w-56">
                    <DropdownMenuLabel className="text-muted-foreground text-xs">{t.nav.switchStore}</DropdownMenuLabel>
                    <DropdownMenuSeparator />
                    {stores.map((s) => (
                        <DropdownMenuItem key={s.id} onSelect={() => router.post(`/stores/${s.id}/enter`)}>
                            <span className="flex-1 truncate">{s.name}</span>
                            {s.id === currentStore.id && <Check className="size-4" />}
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>
        );
}

function AiModeBadge() {
    const { t } = useI18n();
    const { aiLive, aiError } = usePage().props;

    if (aiLive && aiError) {
        return (
            <Tooltip>
                <TooltipTrigger asChild>
                    <span className="bg-danger-soft text-danger hidden items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium sm:inline-flex">
                        <TriangleAlert className="size-3.5" />
                        {t.common.aiError}
                    </span>
                </TooltipTrigger>
                <TooltipContent className="max-w-72 space-y-1">
                    <p>{t.common.aiErrorHint}</p>
                    <p className="opacity-75" dir="ltr">
                        {String(aiError)}
                    </p>
                </TooltipContent>
            </Tooltip>
        );
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span
                    className={
                        aiLive
                            ? 'bg-success-soft text-success hidden items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium sm:inline-flex'
                            : 'bg-gold-soft text-gold-foreground dark:text-gold hidden items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium sm:inline-flex'
                    }
                >
                    <Sparkles className="size-3.5" />
                    {aiLive ? t.common.aiLive : t.common.demoMode}
                </span>
            </TooltipTrigger>
            {!aiLive && <TooltipContent className="max-w-64">{t.common.demoModeHint}</TooltipContent>}
        </Tooltip>
    );
}

export function LanguageToggle({ className }: { className?: string }) {
    const { t, locale } = useI18n();

    return (
        <Button
            variant="ghost"
            size="sm"
            className={className ?? 'gap-1.5 px-2.5'}
            onClick={() => router.post(`/locale/${locale === 'ar' ? 'en' : 'ar'}`, {}, { preserveScroll: true })}
        >
            <Languages className="size-4" />
            <span className="text-xs font-medium">{t.common.switchLanguage}</span>
        </Button>
    );
}

function NotificationBell() {
    const { t } = useI18n();
    const { unreadNotifications } = usePage().props;

    return (
        <Button variant="ghost" size="icon" asChild className="relative">
            <Link href="/store/notifications" aria-label={t.common.notifications}>
                <Bell className="size-[18px]" />
                <AnimatePresence>
                    {unreadNotifications > 0 && (
                        <motion.span
                            initial={{ scale: 0 }}
                            animate={{ scale: 1 }}
                            exit={{ scale: 0 }}
                            className="bg-danger absolute top-1 end-1 flex min-w-4 items-center justify-center rounded-full px-1 text-[10px] leading-4 font-semibold text-white ring-2 ring-background"
                        >
                            {unreadNotifications > 9 ? '9+' : unreadNotifications}
                        </motion.span>
                    )}
                </AnimatePresence>
            </Link>
        </Button>
    );
}

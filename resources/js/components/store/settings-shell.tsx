import { Link, usePage } from '@inertiajs/react';
import { Palette, PlugZap, Settings, SlidersHorizontal, Store } from 'lucide-react';
import { motion } from 'motion/react';
import type { ReactNode } from 'react';
import { PageBody, PageHeader } from '@/components/mojo/page-header';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

/**
 * Header and tab bar shared by the store settings pages.
 */
export function SettingsShell({ children, actions }: { children: ReactNode; actions?: ReactNode }) {
    const { t } = useI18n();
    const { url } = usePage();
    const s = t.settings;

    const tabs = [
        { href: '/store/settings/general', label: s.tabs.general, icon: Store },
        { href: '/store/settings/integration', label: s.tabs.integration, icon: PlugZap },
        { href: '/store/settings/evaluation', label: s.tabs.evaluation, icon: SlidersHorizontal },
        { href: '/store/settings/brand', label: s.tabs.brand, icon: Palette },
    ];

    return (
        <PageBody>
            <PageHeader title={s.title} description={s.description} icon={Settings} actions={actions} />

            <nav className="bg-muted/60 flex w-full gap-1 overflow-x-auto rounded-xl p-1 sm:w-fit">
                {tabs.map((tab) => {
                    const active = url.split('?')[0] === tab.href;

                    return (
                        <Link
                            key={tab.href}
                            href={tab.href}
                            prefetch
                            preserveScroll
                            className={cn(
                                'relative flex shrink-0 items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-medium transition-colors',
                                active ? 'text-foreground' : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {active && (
                                <motion.span
                                    layoutId="settings-tab"
                                    className="bg-card absolute inset-0 rounded-lg shadow-sm"
                                    transition={{ type: 'spring', stiffness: 400, damping: 34 }}
                                />
                            )}
                            <tab.icon className="relative size-4" />
                            <span className="relative">{tab.label}</span>
                        </Link>
                    );
                })}
            </nav>

            {children}
        </PageBody>
    );
}

/**
 * Two-column settings row: explanation on one side, controls on the other.
 */
export function SettingsRow({ title, description, children }: { title: string; description?: ReactNode; children: ReactNode }) {
    return (
        <div className="grid gap-4 border-b border-border/60 py-6 last:border-0 md:grid-cols-[minmax(0,1fr)_minmax(0,2fr)] md:gap-10">
            <div className="space-y-1">
                <h3 className="text-sm font-semibold">{title}</h3>
                {description && <p className="text-muted-foreground text-xs leading-relaxed">{description}</p>}
            </div>
            <div className="min-w-0">{children}</div>
        </div>
    );
}

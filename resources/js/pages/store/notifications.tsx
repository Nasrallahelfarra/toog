import { router } from '@inertiajs/react';
import { Bell, BellOff, CheckCheck, Cog, Palette, RefreshCw, Scale, ScanSearch, TrendingUp } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { AnimatePresence, motion } from 'motion/react';
import { EmptyState } from '@/components/mojo/empty-state';
import { PageBody, PageHeader } from '@/components/mojo/page-header';
import { Pagination } from '@/components/mojo/pagination';
import type { Paginated } from '@/components/mojo/pagination';
import { toneClasses } from '@/components/mojo/tone';
import type { Tone } from '@/components/mojo/tone';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

type Notification = {
    id: string;
    kind: 'evaluation' | 'trend' | 'balance' | 'sync' | 'design' | 'system';
    level: 'info' | 'success' | 'warning' | 'danger';
    title_ar: string;
    title_en: string;
    body_ar: string;
    body_en: string;
    url: string | null;
    read_at: string | null;
    created_at: string | null;
};

type Props = {
    notifications: Paginated<Notification>;
    filter: 'all' | 'unread';
    counts: { all: number; unread: number };
};

const kindIcon: Record<Notification['kind'], LucideIcon> = {
    evaluation: ScanSearch,
    trend: TrendingUp,
    balance: Scale,
    sync: RefreshCw,
    design: Palette,
    system: Cog,
};

const levelTone: Record<Notification['level'], Tone> = {
    info: 'info',
    success: 'success',
    warning: 'warning',
    danger: 'danger',
};

export default function Notifications({ notifications, filter, counts }: Props) {
    const { t, field, relative, dateTime } = useI18n();

    const setFilter = (value: 'all' | 'unread') =>
        router.get('/store/notifications', value === 'unread' ? { filter: 'unread' } : {}, { preserveScroll: true, preserveState: true, replace: true });

    const markRead = (id: string) => router.post(`/store/notifications/${id}/read`, {}, { preserveScroll: true, preserveState: true });

    const open = (n: Notification) => {
        if (!n.url) {
            return markRead(n.id);
        }

        router.post(`/store/notifications/${n.id}/read`, { open: 1 });
    };

    return (
        <PageBody className="max-w-4xl">
            <PageHeader
                title={t.notifications.title}
                description={t.notifications.description}
                icon={Bell}
                actions={
                    <Button
                        variant="outline"
                        disabled={counts.unread === 0}
                        onClick={() => router.post('/store/notifications/read-all', {}, { preserveScroll: true })}
                    >
                        <CheckCheck className="size-4" />
                        {t.notifications.markAllRead}
                    </Button>
                }
            />

            <div className="bg-muted inline-flex w-fit rounded-xl p-1">
                {(['all', 'unread'] as const).map((value) => (
                    <button
                        key={value}
                        type="button"
                        onClick={() => setFilter(value)}
                        className={cn(
                            'flex cursor-pointer items-center gap-2 rounded-lg px-3.5 py-1.5 text-sm transition-colors',
                            filter === value ? 'bg-card text-foreground font-medium shadow-sm' : 'text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {t.notifications[value]}
                        <span
                            className={cn(
                                'num rounded-full px-1.5 text-[11px]',
                                value === 'unread' && counts.unread > 0 ? 'bg-danger text-white' : 'bg-background text-muted-foreground',
                            )}
                        >
                            {counts[value]}
                        </span>
                    </button>
                ))}
            </div>

            {notifications.data.length === 0 ? (
                <EmptyState
                    icon={BellOff}
                    title={filter === 'unread' ? t.notifications.emptyUnread : t.notifications.empty}
                    description={t.notifications.emptyHint}
                />
            ) : (
                <div className="bg-card overflow-hidden rounded-2xl border border-border/70 shadow-[0_1px_2px_rgba(16,24,40,0.04)]">
                    <ul className="divide-y divide-border/60">
                        <AnimatePresence initial={false}>
                            {notifications.data.map((n, i) => {
                                const Icon = kindIcon[n.kind] ?? Bell;
                                const tone = toneClasses[levelTone[n.level] ?? 'info'];
                                const unread = !n.read_at;

                                return (
                                    <motion.li
                                        key={n.id}
                                        layout
                                        initial={{ opacity: 0, y: 8 }}
                                        animate={{ opacity: 1, y: 0 }}
                                        transition={{ delay: Math.min(i * 0.03, 0.3) }}
                                        className={cn('group relative flex gap-4 px-5 py-4 transition-colors', unread ? 'bg-primary/[0.03]' : 'hover:bg-muted/30')}
                                    >
                                        {unread && <span className="bg-gold absolute inset-y-3 start-0 w-[3px] rounded-full" />}
                                        <span className={cn('flex size-10 shrink-0 items-center justify-center rounded-xl', tone.soft, tone.text)}>
                                            <Icon className="size-[18px]" />
                                        </span>
                                        <div className="min-w-0 flex-1 space-y-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <h3 className={cn('text-sm', unread ? 'font-semibold' : 'font-medium')}>{field(n, 'title')}</h3>
                                                <span className="text-muted-foreground bg-muted rounded-full px-2 py-0.5 text-[11px]">
                                                    {t.notifications.kinds[n.kind] ?? n.kind}
                                                </span>
                                            </div>
                                            {field(n, 'body') && <p className="text-muted-foreground text-sm leading-relaxed">{field(n, 'body')}</p>}
                                            <div className="flex flex-wrap items-center gap-3 pt-0.5 text-xs">
                                                <span className="text-muted-foreground" title={dateTime(n.created_at)}>
                                                    {relative(n.created_at)}
                                                </span>
                                                {n.url && (
                                                    <button type="button" onClick={() => open(n)} className="text-primary cursor-pointer font-medium hover:underline">
                                                        {t.notifications.open}
                                                    </button>
                                                )}
                                                {unread && (
                                                    <button
                                                        type="button"
                                                        onClick={() => markRead(n.id)}
                                                        className="text-muted-foreground hover:text-foreground cursor-pointer"
                                                    >
                                                        {t.notifications.markRead}
                                                    </button>
                                                )}
                                            </div>
                                        </div>
                                    </motion.li>
                                );
                            })}
                        </AnimatePresence>
                    </ul>
                </div>
            )}

            <Pagination meta={notifications} />
        </PageBody>
    );
}

import { router } from '@inertiajs/react';
import { History, ShieldCheck, UserRound, X } from 'lucide-react';
import { motion } from 'motion/react';
import { useState } from 'react';
import { FilterSelect } from '@/components/market/filter-select';
import { EmptyState } from '@/components/mojo/empty-state';
import { PageBody, PageHeader } from '@/components/mojo/page-header';
import { Pagination } from '@/components/mojo/pagination';
import type { Paginated } from '@/components/mojo/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

type Log = {
    id: number;
    action: string;
    user: { id: number; name: string; role: string } | null;
    properties: Record<string, unknown> | null;
    subject_type: string | null;
    subject_id: number | null;
    acting_as_admin: boolean;
    ip: string | null;
    created_at: string | null;
};

type Filters = { user?: string; action?: string; from?: string; to?: string };

type Props = {
    logs: Paginated<Log>;
    filters: Filters;
    users: { id: number; name: string; role: string }[];
    actions: string[];
};

const groupColor: Record<string, string> = {
    evaluation: 'bg-success',
    design: 'bg-chart-2',
    competitor: 'bg-info',
    settings: 'bg-warning',
    data: 'bg-chart-3',
    report: 'bg-primary',
    calendar: 'bg-chart-4',
    market: 'bg-chart-5',
    admin: 'bg-gold',
    batch: 'bg-success',
    integration: 'bg-chart-3',
};

export default function ActivityPage({ logs, filters, users, actions }: Props) {
    const { t, dateTime, relative } = useI18n();
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');

    const apply = (next: Filters) => {
        const params = Object.fromEntries(Object.entries({ ...filters, ...next }).filter(([, v]) => v));
        router.get('/store/activity', params, { preserveScroll: true, preserveState: true, replace: true });
    };

    // Group filters ("evaluation" = every evaluation.* action) only where a group has dotted actions.
    const groups = [...new Set(actions.filter((a) => a.includes('.')).map((a) => a.split('.')[0]))].filter((g) => !actions.includes(g));
    const actionLabel = (action: string) => t.activity.actions[action as keyof typeof t.activity.actions] ?? action;
    const hasFilters = Object.values(filters).some(Boolean);

    return (
        <PageBody>
            <PageHeader title={t.activity.title} description={t.activity.description} icon={History} />

            <div className="bg-card flex flex-wrap items-center gap-2 rounded-2xl border border-border/70 p-3 shadow-[0_1px_2px_rgba(16,24,40,0.04)]">
                <FilterSelect
                    value={filters.user}
                    onChange={(v) => apply({ user: v ?? undefined })}
                    allLabel={t.activity.allUsers}
                    options={users.map((u) => ({ value: String(u.id), label: u.name }))}
                />
                <FilterSelect
                    value={filters.action}
                    onChange={(v) => apply({ action: v ?? undefined })}
                    allLabel={t.activity.allActions}
                    options={[
                        ...groups.map((g) => ({ value: g, label: `${t.activity.groups[g as keyof typeof t.activity.groups] ?? g} — ${t.common.all}` })),
                        ...actions.map((a) => ({ value: a, label: actionLabel(a) })),
                    ]}
                    className="min-w-52"
                />
                <form
                    className="flex flex-wrap items-center gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        apply({ from: from || undefined, to: to || undefined });
                    }}
                >
                    <Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="h-9 w-40" aria-label={t.activity.from} />
                    <Input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="h-9 w-40" aria-label={t.activity.to} />
                    <Button type="submit" size="sm" variant="outline" className="h-9">
                        {t.common.filter}
                    </Button>
                </form>
                {hasFilters && (
                    <Button variant="ghost" size="sm" className="ms-auto h-9" onClick={() => router.get('/store/activity', {}, { replace: true })}>
                        <X className="size-4" />
                        {t.common.clear}
                    </Button>
                )}
            </div>

            {logs.data.length === 0 ? (
                <EmptyState icon={History} title={t.activity.empty} description={t.activity.emptyHint} />
            ) : (
                <div className="bg-card overflow-hidden rounded-2xl border border-border/70 shadow-[0_1px_2px_rgba(16,24,40,0.04)]">
                    <ol className="divide-y divide-border/60">
                        {logs.data.map((log, i) => {
                            const group = log.action.split('.')[0];

                            return (
                                <motion.li
                                    key={log.id}
                                    initial={{ opacity: 0, y: 6 }}
                                    animate={{ opacity: 1, y: 0 }}
                                    transition={{ delay: Math.min(i * 0.025, 0.3) }}
                                    className="flex gap-4 px-5 py-3.5"
                                >
                                    <span className={cn('mt-1.5 size-2.5 shrink-0 rounded-full', groupColor[group] ?? 'bg-muted-foreground')} />
                                    <div className="min-w-0 flex-1 space-y-1">
                                        <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                                            <span className="flex items-center gap-1.5 font-medium">
                                                <UserRound className="text-muted-foreground size-3.5" />
                                                {log.user?.name ?? t.activity.system}
                                            </span>
                                            <span className="text-foreground/80">{actionLabel(log.action)}</span>
                                            {log.acting_as_admin && (
                                                <span className="bg-gold-soft text-gold-foreground dark:text-gold inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium">
                                                    <ShieldCheck className="size-3" />
                                                    {t.activity.asAdmin}
                                                </span>
                                            )}
                                        </div>
                                        <Details properties={log.properties} />
                                    </div>
                                    <div className="shrink-0 text-end text-xs">
                                        <div className="text-muted-foreground" title={dateTime(log.created_at)}>
                                            {relative(log.created_at)}
                                        </div>
                                        {log.ip && <div className="text-muted-foreground/70 num mt-0.5" dir="ltr">{log.ip}</div>}
                                    </div>
                                </motion.li>
                            );
                        })}
                    </ol>
                </div>
            )}

            <Pagination meta={logs} />
        </PageBody>
    );
}

/**
 * Compact key: value chips for an entry's recorded properties.
 */
function Details({ properties }: { properties: Record<string, unknown> | null }) {
    if (!properties || Object.keys(properties).length === 0) {
        return null;
    }

    const entries = Object.entries(properties)
        .filter(([, v]) => v !== null && v !== '' && typeof v !== 'object')
        .slice(0, 5);
    const nested = Object.entries(properties).filter(([, v]) => Array.isArray(v) && (v as unknown[]).length > 0);

    return (
        <div className="flex flex-wrap gap-1.5">
            {entries.map(([key, value]) => (
                <span key={key} className="bg-muted text-muted-foreground max-w-xs truncate rounded-md px-1.5 py-0.5 text-[11px]">
                    <span className="opacity-70">{key}:</span> <span className="text-foreground/80">{String(value)}</span>
                </span>
            ))}
            {nested.map(([key, value]) => (
                <span key={key} className="bg-muted text-muted-foreground max-w-xs truncate rounded-md px-1.5 py-0.5 text-[11px]">
                    <span className="opacity-70">{key}:</span> <span className="text-foreground/80">{(value as unknown[]).join(', ')}</span>
                </span>
            ))}
        </div>
    );
}

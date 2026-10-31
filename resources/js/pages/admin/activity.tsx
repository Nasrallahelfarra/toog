import { Link, router } from '@inertiajs/react';
import { RotateCcw, ScrollText, ShieldCheck } from 'lucide-react';
import { ActivityDetails, actionLabel } from '@/components/admin/activity-feed';
import type { ActivityRow } from '@/components/admin/activity-feed';
import { Initials } from '@/components/admin/badges';
import { FilterSelect } from '@/components/admin/filter-select';
import { useFilters } from '@/components/admin/use-filters';
import { EmptyState } from '@/components/mojo/empty-state';
import { FadeIn } from '@/components/mojo/motion';
import { PageBody, PageHeader } from '@/components/mojo/page-header';
import { Pagination } from '@/components/mojo/pagination';
import type { Paginated } from '@/components/mojo/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useI18n } from '@/hooks/use-i18n';

type Props = {
    logs: Paginated<ActivityRow>;
    filters: { store?: string; user?: string; action?: string; admin?: string; from?: string; to?: string };
    stores: { id: number; name: string }[];
    users: { id: number; name: string }[];
    actions: string[];
};

export default function AdminActivity({ logs, filters: initial, stores, users, actions }: Props) {
    const { t, dateTime, relative } = useI18n();
    const a = t.admin.activity;
    const { filters, set } = useFilters('/admin/activity', initial);
    const hasFilters = Object.values(initial).some(Boolean);

    return (
        <PageBody>
            <PageHeader title={a.title} description={a.subtitle} icon={ScrollText} />

            <FadeIn delay={0.05} className="bg-card flex flex-wrap items-end gap-2 rounded-2xl border border-border/70 p-3">
                <FilterSelect
                    value={filters.store}
                    onChange={(v) => set('store', v)}
                    allLabel={a.allStores}
                    options={stores.map((s) => ({ value: String(s.id), label: s.name }))}
                />
                <FilterSelect
                    value={filters.user}
                    onChange={(v) => set('user', v)}
                    allLabel={a.allUsers}
                    options={users.map((u) => ({ value: String(u.id), label: u.name }))}
                />
                <FilterSelect
                    value={filters.action}
                    onChange={(v) => set('action', v)}
                    allLabel={a.allActions}
                    className="sm:min-w-52"
                    options={actions.map((key) => ({ value: key, label: actionLabel(t, key) }))}
                />
                <FilterSelect
                    value={filters.admin}
                    onChange={(v) => set('admin', v)}
                    allLabel={a.anyone}
                    options={[
                        { value: '1', label: a.adminOnly },
                        { value: '0', label: a.ownersOnly },
                    ]}
                />
                <label className="grid gap-1 text-xs">
                    <span className="text-muted-foreground">{a.from}</span>
                    <Input type="date" value={filters.from ?? ''} onChange={(e) => set('from', e.target.value)} className="h-9 w-40" />
                </label>
                <label className="grid gap-1 text-xs">
                    <span className="text-muted-foreground">{a.to}</span>
                    <Input type="date" value={filters.to ?? ''} onChange={(e) => set('to', e.target.value)} className="h-9 w-40" />
                </label>
                {hasFilters && (
                    <Button variant="ghost" size="sm" className="h-9" onClick={() => router.get('/admin/activity', {}, { preserveScroll: true })}>
                        <RotateCcw className="size-4" />
                        {t.common.clear}
                    </Button>
                )}
            </FadeIn>

            <FadeIn delay={0.1} className="bg-card overflow-hidden rounded-2xl border border-border/70 shadow-[0_1px_2px_rgba(16,24,40,0.04)]">
                {logs.data.length === 0 ? (
                    <EmptyState icon={ScrollText} title={a.empty} />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{a.time}</TableHead>
                                <TableHead>{a.actor}</TableHead>
                                <TableHead>{a.action}</TableHead>
                                <TableHead>{a.store}</TableHead>
                                <TableHead>IP</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {logs.data.map((log) => (
                                <TableRow key={log.id}>
                                    <TableCell className="text-xs">
                                        <span className="block font-medium">{relative(log.created_at)}</span>
                                        <span className="text-muted-foreground">{dateTime(log.created_at)}</span>
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex items-center gap-2.5">
                                            <Initials name={log.user?.name ?? a.system} className="size-8 rounded-lg" />
                                            <div>
                                                <span className="block text-sm">{log.user?.name ?? a.system}</span>
                                                {log.acting_as_admin && (
                                                    <span className="text-gold-foreground dark:text-gold inline-flex items-center gap-1 text-[11px]">
                                                        <ShieldCheck className="size-3" />
                                                        {a.viaAdmin}
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                    </TableCell>
                                    <TableCell className="max-w-md whitespace-normal">
                                        <span className="block text-sm">{actionLabel(t, log.action)}</span>
                                        <ActivityDetails properties={log.properties} />
                                    </TableCell>
                                    <TableCell>
                                        {log.store ? (
                                            <Link href={`/admin/stores/${log.store.id}`} className="text-primary text-sm hover:underline">
                                                {log.store.name}
                                            </Link>
                                        ) : (
                                            <span className="text-muted-foreground text-xs">—</span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground font-mono text-xs" dir="ltr">
                                        {log.ip ?? '—'}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </FadeIn>

            <Pagination meta={logs} />
        </PageBody>
    );
}

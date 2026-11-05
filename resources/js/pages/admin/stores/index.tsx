import { Link } from '@inertiajs/react';
import { Eye, LogIn, MoreHorizontal, PauseCircle, PlayCircle, Plus, Search, Store, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ConnectionBadge, Initials, StoreStatusBadge } from '@/components/admin/badges';
import { FilterSelect } from '@/components/admin/filter-select';
import { StoreStatusDialogs, enterStore } from '@/components/admin/store-actions';
import type { AdminStoreRow } from '@/components/admin/types';
import { useDebouncedSearch, useFilters } from '@/components/admin/use-filters';
import { EmptyState } from '@/components/mojo/empty-state';
import { Meter } from '@/components/mojo/meter';
import { FadeIn } from '@/components/mojo/motion';
import { PageBody, PageHeader } from '@/components/mojo/page-header';
import { Pagination } from '@/components/mojo/pagination';
import type { Paginated } from '@/components/mojo/pagination';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { DeleteStoreDialog } from '@/components/admin/delete-dialogs';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';


type Props = {
    stores: Paginated<AdminStoreRow>;
    filters: { search?: string; status?: string; connection?: string };
    counts: { all: number; active: number; suspended: number };
};

export default function AdminStores({ stores, filters: initial, counts }: Props) {
    const { t, number, usd, relative, date } = useI18n();
    const s = t.admin.stores;
    const { filters, set } = useFilters('/admin/stores', initial);
    const [search, setSearch] = useDebouncedSearch(initial.search ?? '', (v) => set('search', v));

    const tabs = [
        { key: null, label: s.all, count: counts.all },
        { key: 'active', label: t.admin.statuses.active, count: counts.active },
        { key: 'suspended', label: t.admin.statuses.suspended, count: counts.suspended },
    ];

    return (
        <PageBody>
            <PageHeader
                title={s.title}
                description={s.subtitle}
                icon={Store}
                actions={
                    <Button asChild>
                        <Link href="/admin/stores/create">
                            <Plus className="size-4" />
                            {s.newStore}
                        </Link>
                    </Button>
                }
            />

            <FadeIn delay={0.05} className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div className="bg-muted inline-flex w-fit rounded-xl p-1">
                    {tabs.map((tab) => (
                        <button
                            key={tab.label}
                            type="button"
                            onClick={() => set('status', tab.key)}
                            className={cn(
                                'flex cursor-pointer items-center gap-2 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                                (filters.status ?? null) === tab.key ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {tab.label}
                            <span className="num bg-background/70 rounded-md px-1.5 text-xs">{number(tab.count)}</span>
                        </button>
                    ))}
                </div>
                <div className="flex flex-col gap-2 sm:flex-row">
                    <div className="relative sm:w-80">
                        <Search className="text-muted-foreground absolute start-3 top-1/2 size-4 -translate-y-1/2" />
                        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={s.searchPlaceholder} className="ps-9" />
                    </div>
                    <FilterSelect
                        value={filters.connection}
                        onChange={(v) => set('connection', v)}
                        allLabel={s.allConnections}
                        options={Object.entries(t.admin.connections).map(([value, label]) => ({ value, label }))}
                    />
                </div>
            </FadeIn>

            <FadeIn delay={0.1} className="bg-card overflow-hidden rounded-2xl border border-border/70 shadow-[0_1px_2px_rgba(16,24,40,0.04)]">
                {stores.data.length === 0 ? (
                    <EmptyState icon={Store} title={s.empty} description={s.emptyHint} />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{s.store}</TableHead>
                                <TableHead>{s.owner}</TableHead>
                                <TableHead>{s.status}</TableHead>
                                <TableHead>{s.connection}</TableHead>
                                <TableHead className="text-center">{s.products}</TableHead>
                                <TableHead className="text-center">{s.evaluations}</TableHead>
                                <TableHead className="min-w-44">{s.aiSpend}</TableHead>
                                <TableHead>{s.lastSync}</TableHead>
                                <TableHead className="w-12" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {stores.data.map((store) => (
                                <TableRow key={store.id}>
                                    <TableCell>
                                        <Link href={`/admin/stores/${store.id}`} className="group flex items-center gap-3">
                                            <Initials name={store.name} />
                                            <span className="min-w-0">
                                                <span className="block max-w-56 truncate font-medium group-hover:underline">{store.name}</span>
                                                <span className="text-muted-foreground block max-w-56 truncate text-xs" dir="ltr">
                                                    {store.domain ?? t.admin.platforms[store.platform as keyof typeof t.admin.platforms] ?? store.platform}
                                                </span>
                                            </span>
                                        </Link>
                                    </TableCell>
                                    <TableCell>
                                        {store.owner ? (
                                            <span className="block">
                                                <span className={cn('block text-sm', store.owner.status === 'disabled' && 'text-muted-foreground line-through')}>
                                                    {store.owner.name}
                                                </span>
                                                <span className="text-muted-foreground block text-xs" dir="ltr">
                                                    {store.owner.email}
                                                </span>
                                            </span>
                                        ) : (
                                            <span className="text-muted-foreground text-xs">{s.noOwner}</span>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <StoreStatusBadge status={store.status} />
                                    </TableCell>
                                    <TableCell>
                                        <ConnectionBadge status={store.connection_status} />
                                    </TableCell>
                                    <TableCell className="num text-center">{number(store.products_count)}</TableCell>
                                    <TableCell className="num text-center">{number(store.evaluations_count)}</TableCell>
                                    <TableCell>
                                        <div className="space-y-1">
                                            <div className="flex items-baseline justify-between gap-2 text-xs">
                                                <span className="num font-semibold">{usd(store.month_cost)}</span>
                                                <span className="text-muted-foreground">
                                                    {store.ai_monthly_budget ? `/ ${usd(store.ai_monthly_budget, 0)}` : t.admin.overview.noBudget}
                                                </span>
                                            </div>
                                            {store.budget_usage !== null && (
                                                <Meter
                                                    value={store.budget_usage}
                                                    height={5}
                                                    tone={store.budget_usage >= 100 ? 'danger' : store.budget_usage >= 80 ? 'warning' : 'navy'}
                                                />
                                            )}
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-muted-foreground text-xs" title={date(store.last_synced_at)}>
                                        {store.last_synced_at ? relative(store.last_synced_at) : t.common.never}
                                    </TableCell>
                                    <TableCell>
                                        <RowActions store={store} />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </FadeIn>

            <Pagination meta={stores} />
        </PageBody>
    );
}

function RowActions({ store }: { store: AdminStoreRow }) {
    const { t } = useI18n();
    const [dialog, setDialog] = useState<'suspend' | 'activate' | 'delete' | null>(null);

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant="ghost" size="icon" className="size-8">
                        <MoreHorizontal className="size-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-52">
                    <DropdownMenuItem asChild>
                        <Link href={`/admin/stores/${store.id}`}>
                            <Eye className="size-4" />
                            {t.admin.stores.view}
                        </Link>
                    </DropdownMenuItem>
                    <DropdownMenuItem onSelect={() => enterStore(store.id)}>
                        <LogIn className="size-4 rtl:rotate-180" />
                        {t.admin.stores.openDashboard}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    {store.status === 'suspended' ? (
                        <DropdownMenuItem onSelect={() => setDialog('activate')} className="text-success focus:text-success">
                            <PlayCircle className="size-4" />
                            {t.admin.stores.activate}
                        </DropdownMenuItem>
                    ) : (
                        <DropdownMenuItem onSelect={() => setDialog('suspend')} className="text-danger focus:text-danger">
                            <PauseCircle className="size-4" />
                            {t.admin.stores.suspend}
                        </DropdownMenuItem>
                    )}
                    <DropdownMenuItem onSelect={() => setDialog('delete')} className="text-danger focus:text-danger">
                        <Trash2 className="size-4" />
                        {t.admin.deletion.storeAction}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            <StoreStatusDialogs store={store} dialog={dialog === 'delete' ? null : dialog} onClose={() => setDialog(null)} />
            <DeleteStoreDialog store={store} open={dialog === 'delete'} onOpenChange={(open) => !open && setDialog(null)} />
        </>
    );
}

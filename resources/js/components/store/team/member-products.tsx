import { Link, router } from '@inertiajs/react';
import { ArrowLeft, BarChart3, CalendarRange, ChevronDown, ImageOff, Lock, Package, PackageCheck, PackagePlus, Plus, Search, ShoppingBag, Wallet, X } from 'lucide-react';
import { Fragment, useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Initials } from '@/components/admin/badges';
import { ConfirmModal } from '@/components/admin/store-actions';
import { PerformanceBadge } from '@/components/mojo/decision-badge';
import { EmptyState } from '@/components/mojo/empty-state';
import { FadeIn, Stagger, StaggerItem } from '@/components/mojo/motion';
import { PageBody, PageHeader } from '@/components/mojo/page-header';
import { Pagination } from '@/components/mojo/pagination';
import type { Paginated } from '@/components/mojo/pagination';
import { SectionCard } from '@/components/mojo/section-card';
import { Sparkline, StatCard } from '@/components/mojo/stat-card';
import { MonthlyMatrix, PeriodPicker } from '@/components/store/team/shared';
import type { MemberFigures, MonthPoint, Period, Range } from '@/components/store/team/shared';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useI18n } from '@/hooks/use-i18n';
import { useRevenueVisible } from '@/hooks/use-revenue-visible';
import { useStoreAccess } from '@/hooks/use-store-access';
import { cn } from '@/lib/utils';

type ProductRow = {
    id: number;
    name: string;
    sku: string | null;
    category: string | null;
    price: number | null;
    image_url: string | null;
    performance: string | null;
    units: number;
    orders: number;
    revenue: number | null;
    assignee: { id: number; name: string } | null;
    assigned_at: string | null;
    history: MonthPoint[];
};

type Tab = 'mine' | 'add';

export type MemberProductsProps = {
    member: { id: number; name: string };
    /** The store owner looking at a member, rather than the member themselves. */
    asOwner: boolean;
    months: Range;
    month: string | null;
    availableMonths: string[];
    tab: Tab;
    filters: { search?: string; category?: string };
    stats: MemberFigures | null;
    counts: { mine: number; free: number; categories: string[] };
    products: Paginated<ProductRow>;
};

const ALL = '__all__';
const UNCATEGORIZED = '__none__';

/**
 * One member's products and how each sells month by month: the member's
 * own "my products" page, and the owner's view of that member. A product
 * another member holds is shown as taken and cannot be added; the owner
 * alone can move it.
 */
export function MemberProducts(props: MemberProductsProps) {
    const { t, tr, number, money, percent } = useI18n();
    const p = t.team.products;
    const showRevenue = useRevenueVisible();
    const { can } = useStoreAccess();
    const { member, asOwner, tab, stats } = props;
    const base = asOwner ? `/store/team/${member.id}` : '/store/my-products';
    const actionUrl = asOwner ? `${base}/products` : base;

    const [search, setSearch] = useState(props.filters.search ?? '');
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [open, setOpen] = useState<Set<number>>(new Set());
    const [busy, setBusy] = useState<Set<number>>(new Set());
    const [confirmRelease, setConfirmRelease] = useState(false);

    const visit = (next: { tab?: Tab; search?: string; category?: string; months?: Range; month?: string | null }, only?: string[]) => {
        const query = {
            tab: next.tab ?? tab,
            months: next.months ?? props.months,
            month: next.month === undefined ? props.month : next.month,
            search: next.search === undefined ? props.filters.search : next.search,
            category: next.category === undefined ? props.filters.category : next.category,
        };

        setSelected(new Set());
        router.get(
            base,
            Object.fromEntries(Object.entries(query).filter(([key, v]) => v !== null && v !== undefined && v !== '' && !(key === 'tab' && v === 'mine'))),
            { preserveState: true, preserveScroll: true, replace: true, ...(only ? { only: [...only, 'tab', 'filters', 'months', 'month'] } : {}) },
        );
    };

    useEffect(() => {
        if (search === (props.filters.search ?? '')) return;
        const timer = window.setTimeout(() => visit({ search }, ['products']), 350);
        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    /** A product the viewer can act on in this tab. */
    const actionable = (product: ProductRow) => tab === 'mine' || asOwner || product.assignee === null;
    const rows = props.products.data;
    const pickable = rows.filter(actionable);
    const allPicked = pickable.length > 0 && pickable.every((product) => selected.has(product.id));

    const toggle = (id: number, on: boolean) =>
        setSelected((current) => {
            const next = new Set(current);
            if (on) next.add(id);
            else next.delete(id);
            return next;
        });

    const send = (method: 'post' | 'delete', ids: number[], done?: () => void) => {
        setBusy(new Set(ids));
        const options = {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => setSelected(new Set()),
            // Someone else may have taken the product a moment ago; the
            // list reloads with the errors, so it shows who holds it now.
            onError: (errors: Record<string, string>) => toast.error(Object.values(errors)[0]),
            onFinish: () => {
                setBusy(new Set());
                done?.();
            },
        };

        if (method === 'post') router.post(actionUrl, { ids }, options);
        else router.delete(actionUrl, { ...options, data: { ids } });
    };

    const add = (ids: number[]) => send('post', ids);
    const release = (ids: number[], done?: () => void) => send('delete', ids, done);
    const picked = [...selected];
    const toggleOpen = (id: number) =>
        setOpen((current) => {
            const next = new Set(current);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    const columns = 5 + (showRevenue ? 1 : 0) + 2;

    return (
        <PageBody>
            <PageHeader
                title={asOwner ? tr(p.memberTitle, { name: member.name }) : p.myTitle}
                description={asOwner ? p.memberSubtitle : p.mySubtitle}
                icon={PackageCheck}
                eyebrow={
                    asOwner ? (
                        <Link href="/store/team" className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1">
                            <ArrowLeft className="size-3 rtl:rotate-180" />
                            {p.backToTeam}
                        </Link>
                    ) : undefined
                }
                actions={
                    <PeriodPicker
                        period={{ months: props.months, month: props.month }}
                        available={props.availableMonths}
                        onChange={(next: Period) => visit({ months: next.months, month: next.month }, ['stats', 'products'])}
                    />
                }
            />

            {stats && (
                <Stagger className={cn('grid gap-4 sm:grid-cols-2', showRevenue ? 'xl:grid-cols-5' : 'xl:grid-cols-4')}>
                    <StaggerItem>
                        <StatCard
                            label={p.stats.products}
                            value={stats.products}
                            format={(n) => number(n)}
                            icon={Package}
                            tone="navy"
                            hint={tr(p.stats.selling, { count: number(stats.selling) })}
                        />
                    </StaggerItem>
                    <StaggerItem>
                        <StatCard
                            label={p.stats.units}
                            value={stats.units}
                            format={(n) => number(n)}
                            icon={ShoppingBag}
                            tone="success"
                            hint={tr(p.stats.share, { share: percent(stats.share, 1) })}
                            spark={stats.monthly.map((point) => point.units)}
                        />
                    </StaggerItem>
                    <StaggerItem>
                        <StatCard
                            label={p.stats.avgPerMonth}
                            value={stats.avg_per_month}
                            format={(n) => number(n, 1)}
                            icon={CalendarRange}
                            tone="info"
                            hint={tr(p.stats.avgPerProduct, { avg: number(stats.avg_per_product, 1) })}
                        />
                    </StaggerItem>
                    <StaggerItem>
                        <StatCard label={p.stats.orders} value={stats.orders} format={(n) => number(n)} icon={BarChart3} tone="neutral" />
                    </StaggerItem>
                    {showRevenue && (
                        <StaggerItem>
                            <StatCard label={p.stats.revenue} value={stats.revenue} format={(n) => money(n)} icon={Wallet} tone="gold" />
                        </StaggerItem>
                    )}
                </Stagger>
            )}

            {stats && stats.products > 0 && (
                <FadeIn delay={0.05}>
                    <SectionCard title={p.monthlyTitle} description={p.monthlyHint} icon={BarChart3}>
                        <div className="space-y-3">
                            <MonthlyMatrix rows={[{ key: 'units', label: t.catalog.metrics.units, monthly: stats.monthly }]} firstColumn={t.catalog.month} selected={props.month} />
                            {showRevenue && (
                                <MonthlyMatrix
                                    rows={[{ key: 'revenue', label: t.catalog.metrics.revenue, monthly: stats.monthly }]}
                                    metric="revenue"
                                    firstColumn={t.catalog.month}
                                    selected={props.month}
                                />
                            )}
                        </div>
                    </SectionCard>
                </FadeIn>
            )}

            <section className="space-y-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="bg-muted inline-flex w-fit flex-wrap rounded-xl p-1">
                        {(
                            [
                                { key: 'mine', label: p.tabs.mine, count: props.counts.mine, icon: PackageCheck },
                                { key: 'add', label: p.tabs.add, count: props.counts.free, icon: PackagePlus },
                            ] as const
                        ).map((segment) => (
                            <button
                                key={segment.key}
                                type="button"
                                onClick={() => segment.key !== tab && visit({ tab: segment.key }, ['products', 'counts'])}
                                aria-pressed={tab === segment.key}
                                className={cn(
                                    'flex cursor-pointer items-center gap-2 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                                    tab === segment.key ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                <segment.icon className="size-4" />
                                {segment.label}
                                <span className="num bg-background/70 rounded-md px-1.5 text-xs">{number(segment.count)}</span>
                            </button>
                        ))}
                    </div>
                    <div className="flex w-full flex-wrap gap-2 sm:w-auto">
                        <div className="relative min-w-0 flex-1 sm:w-64 sm:flex-none">
                            <Search className="text-muted-foreground pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2" />
                            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={p.searchPlaceholder} className="ps-9" />
                        </div>
                        {props.counts.categories.length > 0 && (
                            <Select value={props.filters.category ?? ALL} onValueChange={(v) => visit({ category: v === ALL ? '' : v }, ['products'])}>
                                <SelectTrigger className="w-44">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>{p.allCategories}</SelectItem>
                                    {props.counts.categories.map((category) => (
                                        <SelectItem key={category} value={category}>
                                            {category}
                                        </SelectItem>
                                    ))}
                                    <SelectItem value={UNCATEGORIZED}>{p.uncategorized}</SelectItem>
                                </SelectContent>
                            </Select>
                        )}
                    </div>
                </div>

                {tab === 'add' && !asOwner && <p className="text-muted-foreground text-xs">{p.heldHint}</p>}

                <div className="bg-card overflow-hidden rounded-2xl border border-border/70 shadow-[0_1px_2px_rgba(16,24,40,0.04)]">
                    {rows.length === 0 ? (
                        props.filters.search || props.filters.category ? (
                            <EmptyState icon={Search} title={p.emptySearch} />
                        ) : tab === 'mine' ? (
                            <EmptyState
                                icon={PackagePlus}
                                title={p.emptyMine}
                                description={asOwner ? p.emptyMineOwner : p.emptyMineSelf}
                                action={
                                    <Button onClick={() => visit({ tab: 'add' }, ['products', 'counts'])}>
                                        <Plus className="size-4" />
                                        {p.goAdd}
                                    </Button>
                                }
                            />
                        ) : (
                            <EmptyState icon={PackageCheck} title={p.emptyAdd} description={p.emptyAddHint} />
                        )
                    ) : (
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-10">
                                            <Checkbox
                                                checked={allPicked ? true : pickable.some((product) => selected.has(product.id)) ? 'indeterminate' : false}
                                                disabled={pickable.length === 0}
                                                onCheckedChange={(value) => setSelected(value === true ? new Set(pickable.map((product) => product.id)) : new Set())}
                                                aria-label={t.team.form.selectAll}
                                            />
                                        </TableHead>
                                        <TableHead>{p.columns.product}</TableHead>
                                        <TableHead>{p.columns.class}</TableHead>
                                        <TableHead className="text-end">{p.columns.units}</TableHead>
                                        <TableHead className="text-end">{p.columns.orders}</TableHead>
                                        {showRevenue && <TableHead className="text-end">{p.columns.revenue}</TableHead>}
                                        <TableHead>{p.columns.trend}</TableHead>
                                        <TableHead className="w-44" />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {rows.map((product) => {
                                        const taken = product.assignee !== null && product.assignee.id !== member.id;
                                        const working = busy.has(product.id);
                                        const expanded = open.has(product.id);
                                        // A member without the catalog pages opens only the products they hold.
                                        const linked = asOwner || tab === 'mine' || can('catalog', 'performance', 'stagnant');

                                        return (
                                            <Fragment key={product.id}>
                                                <TableRow className={cn(taken && !asOwner && 'opacity-60', expanded && 'border-b-0')}>
                                                    <TableCell>
                                                        {actionable(product) && (
                                                            <Checkbox checked={selected.has(product.id)} onCheckedChange={(value) => toggle(product.id, value === true)} aria-label={product.name} />
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        <div className="flex items-center gap-3">
                                                            <Thumb src={product.image_url} alt={product.name} />
                                                            <div className="min-w-0">
                                                                {linked ? (
                                                                    <Link href={`/store/products/${product.id}`} className="line-clamp-1 max-w-72 font-medium hover:underline" dir="auto">
                                                                        {product.name}
                                                                    </Link>
                                                                ) : (
                                                                    <span className="line-clamp-1 max-w-72 font-medium" dir="auto">
                                                                        {product.name}
                                                                    </span>
                                                                )}
                                                                <p className="text-muted-foreground flex flex-wrap gap-x-2 text-xs">
                                                                    {product.sku && <span dir="ltr">{product.sku}</span>}
                                                                    {product.category && <span>{product.category}</span>}
                                                                    {product.price !== null && <span className="num">{money(product.price)}</span>}
                                                                </p>
                                                            </div>
                                                        </div>
                                                    </TableCell>
                                                    <TableCell>
                                                        <PerformanceBadge value={product.performance} />
                                                    </TableCell>
                                                    <TableCell className="num text-end font-semibold">{number(product.units)}</TableCell>
                                                    <TableCell className="num text-end">{number(product.orders)}</TableCell>
                                                    {showRevenue && <TableCell className="num text-end">{money(product.revenue)}</TableCell>}
                                                    <TableCell>
                                                        <button
                                                            type="button"
                                                            onClick={() => toggleOpen(product.id)}
                                                            aria-expanded={expanded}
                                                            aria-label={expanded ? p.hideMonths : p.showMonths}
                                                            title={expanded ? p.hideMonths : p.showMonths}
                                                            className="hover:bg-muted flex cursor-pointer items-center gap-1.5 rounded-lg px-1.5 py-1"
                                                        >
                                                            <Sparkline data={product.history.map((point) => point.units)} width={84} height={26} />
                                                            <ChevronDown className={cn('text-muted-foreground size-4 transition-transform', expanded && 'rotate-180')} />
                                                        </button>
                                                    </TableCell>
                                                    <TableCell className="text-end">
                                                        {tab === 'mine' ? (
                                                            <Button variant="ghost" size="sm" className="text-muted-foreground hover:text-danger h-8" disabled={working} onClick={() => release([product.id])}>
                                                                {working ? <Spinner /> : <X className="size-4" />}
                                                                {p.release}
                                                            </Button>
                                                        ) : taken && !asOwner ? (
                                                            <span className="bg-muted text-muted-foreground inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs whitespace-nowrap">
                                                                <Lock className="size-3" />
                                                                {tr(p.heldBy, { name: product.assignee?.name ?? '' })}
                                                            </span>
                                                        ) : (
                                                            <Button variant="outline" size="sm" className="h-8" disabled={working} onClick={() => add([product.id])}>
                                                                {working ? <Spinner /> : <Plus className="size-4" />}
                                                                {taken ? tr(p.moveFrom, { name: product.assignee?.name ?? '' }) : asOwner ? p.assign : p.claim}
                                                            </Button>
                                                        )}
                                                    </TableCell>
                                                </TableRow>
                                                {expanded && (
                                                    <TableRow className="hover:bg-transparent">
                                                        <TableCell colSpan={columns} className="bg-muted/30 pt-0 pb-4">
                                                            <MonthCells history={product.history} />
                                                        </TableCell>
                                                    </TableRow>
                                                )}
                                            </Fragment>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </div>

                <Pagination meta={props.products} />
            </section>

            {picked.length > 0 && (
                <div className="sticky bottom-4 z-20 mx-auto flex w-fit max-w-full flex-wrap items-center gap-2 rounded-2xl border bg-card px-3 py-2 shadow-lg">
                    <span className="num px-1 text-sm font-medium">{tr(p.selected, { count: number(picked.length) })}</span>
                    {tab === 'mine' ? (
                        <Button variant="destructive" size="sm" onClick={() => setConfirmRelease(true)}>
                            <X className="size-4" />
                            {tr(p.releaseSelected, { count: number(picked.length) })}
                        </Button>
                    ) : (
                        <Button size="sm" disabled={busy.size > 0} onClick={() => add(picked)}>
                            {busy.size > 0 ? <Spinner /> : <Plus className="size-4" />}
                            {tr(asOwner ? p.assignSelected : p.claimSelected, { count: number(picked.length) })}
                        </Button>
                    )}
                    <Button variant="ghost" size="sm" onClick={() => setSelected(new Set())}>
                        {p.clearSelection}
                    </Button>
                </div>
            )}

            <ConfirmModal
                open={confirmRelease}
                onOpenChange={setConfirmRelease}
                title={tr(p.releaseTitle, { count: number(picked.length) })}
                description={p.releaseDescription}
                confirmLabel={p.release}
                destructive
                onConfirm={(done) =>
                    release(picked, () => {
                        done();
                        setConfirmRelease(false);
                    })
                }
            />
        </PageBody>
    );
}

/** A product's sales, one cell per month. */
function MonthCells({ history }: { history: MonthPoint[] }) {
    const { number, money, date } = useI18n();
    const showRevenue = useRevenueVisible();
    const peak = Math.max(1, ...history.map((point) => point.units));

    return (
        <div className="flex gap-1.5 overflow-x-auto pt-3">
            {history.map((point) => (
                <div
                    key={point.period}
                    className="min-w-16 flex-1 rounded-lg border bg-card px-2 py-1.5 text-center"
                    style={point.units > 0 ? { backgroundColor: `color-mix(in oklch, var(--primary) ${6 + Math.round((point.units / peak) * 30)}%, var(--card))` } : undefined}
                >
                    <div className="text-muted-foreground text-[11px] whitespace-nowrap">{date(point.period, { month: 'short', year: '2-digit' })}</div>
                    <div className={cn('num text-sm font-semibold', point.units === 0 && 'text-muted-foreground/60')}>{number(point.units)}</div>
                    {showRevenue && <div className="num text-muted-foreground text-[11px] whitespace-nowrap">{money(point.revenue)}</div>}
                </div>
            ))}
        </div>
    );
}

function Thumb({ src, alt }: { src: string | null; alt: string }) {
    const [broken, setBroken] = useState(false);

    return src && !broken ? (
        <img src={src} alt={alt} loading="lazy" onError={() => setBroken(true)} className="bg-muted size-11 shrink-0 rounded-lg object-cover" />
    ) : (
        <span className="bg-muted text-muted-foreground/60 flex size-11 shrink-0 items-center justify-center rounded-lg">
            <ImageOff className="size-4" />
        </span>
    );
}

/** Small avatar with the name of the member who holds a product. */
export function HolderChip({ name, className }: { name: string; className?: string }) {
    return (
        <span className={cn('bg-card/95 inline-flex max-w-full items-center gap-1.5 rounded-full py-0.5 ps-0.5 pe-2 text-[11px] font-medium shadow-sm', className)}>
            <Initials name={name} className="size-5 rounded-full text-[9px]" />
            <span className="truncate" dir="auto">
                {name}
            </span>
        </span>
    );
}

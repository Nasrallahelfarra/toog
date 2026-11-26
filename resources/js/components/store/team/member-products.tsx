import { Link, router } from '@inertiajs/react';
import {
    ArrowDownWideNarrow,
    ArrowLeft,
    BarChart3,
    CalendarRange,
    ChevronDown,
    ImageOff,
    Lock,
    Package,
    PackageCheck,
    PackagePlus,
    Plus,
    Search,
    ShieldCheck,
    ShoppingBag,
    Wallet,
    X,
} from 'lucide-react';
import { AnimatePresence, motion } from 'motion/react';
import { Fragment, useEffect, useRef, useState } from 'react';
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
import { MemberAccessDialog } from '@/components/store/team/member-dialogs';
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
    assignee: { id: number; name: string } | null;
    assigned_at: string | null;
    launched_at: string | null;
    // How the product sells: sent to the store manager only, never to the
    // staff member who holds it.
    performance?: string | null;
    units?: number;
    orders?: number;
    revenue?: number | null;
    history?: MonthPoint[];
};

type Sort = 'newest' | 'units';

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
    /** Newest first by default; the manager can rank by units sold. */
    sort: Sort;
    /** Null for the staff member: their sales are the manager's to see. */
    stats: MemberFigures | null;
    counts: { mine: number; free: number; categories: string[] };
    products: Paginated<ProductRow>;
    /** The manager's view only: the member's dashboard pages, changed from here. */
    pages?: { granted: string[]; groups: Record<string, string[]> } | null;
    /** The manager's view: false when the viewer reads the report but does not manage the team. */
    canManage?: boolean;
};

const ALL = '__all__';
const UNCATEGORIZED = '__none__';

/**
 * One member's products: the member's own "my products" page, and the
 * store manager's view of that member. Sales and performance (figures,
 * class, month by month) show for the manager only; the member sees the
 * list itself, newest first. A product another member holds is shown as
 * taken and cannot be added; the manager alone can move it.
 */
export function MemberProducts(props: MemberProductsProps) {
    const { t, tr, number, money, percent, date, relative } = useI18n();
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
    const [accessOpen, setAccessOpen] = useState(false);
    // On a phone the checkboxes stay out of the way until "Select" is tapped.
    const [selecting, setSelecting] = useState(false);
    const listRef = useRef<HTMLElement>(null);
    const pages = asOwner ? props.pages : undefined;
    // The manager's view has more columns, so its table needs a wider screen.
    const cardsOnly = asOwner ? 'lg:hidden' : 'md:hidden';
    const tableOnly = asOwner ? 'hidden lg:block' : 'hidden md:block';

    const visit = (next: { tab?: Tab; search?: string; category?: string; months?: Range; month?: string | null; sort?: Sort }, only?: string[]) => {
        const query = {
            tab: next.tab ?? tab,
            // The period and the ranking by sales belong to the manager's view.
            months: asOwner ? (next.months ?? props.months) : null,
            month: asOwner ? (next.month === undefined ? props.month : next.month) : null,
            sort: next.sort ?? props.sort,
            search: next.search === undefined ? props.filters.search : next.search,
            category: next.category === undefined ? props.filters.category : next.category,
        };
        const isDefault = (key: string, v: unknown) => (key === 'tab' && v === 'mine') || (key === 'sort' && v === 'newest');

        setSelected(new Set());
        router.get(
            base,
            Object.fromEntries(Object.entries(query).filter(([key, v]) => v !== null && v !== undefined && v !== '' && !isDefault(key, v))),
            { preserveState: true, preserveScroll: true, replace: true, ...(only ? { only: [...only, 'tab', 'filters', 'months', 'month', 'sort'] } : {}) },
        );
    };

    // The search waits for typing to pause; it then reads the filters as
    // they are at that moment, not as they were when typing began.
    const visitRef = useRef(visit);
    useEffect(() => {
        visitRef.current = visit;
    });
    useEffect(() => {
        if (search === (props.filters.search ?? '')) return;
        const timer = window.setTimeout(() => visitRef.current({ search }, ['products']), 350);
        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    // A new page or tab starts with nothing selected, and a page picked
    // from the pager at the bottom opens at the top of the list.
    const shownPage = useRef(props.products.current_page);
    useEffect(() => {
        setSelected(new Set());
        setSelecting(false);

        if (shownPage.current === props.products.current_page) return;
        shownPage.current = props.products.current_page;
        const list = listRef.current;
        if (list && list.getBoundingClientRect().top < 0) list.scrollIntoView({ block: 'start', behavior: 'smooth' });
    }, [props.products.current_page, tab]);

    /** A product the viewer can act on in this tab. */
    // Someone who reads the staff report without managing the team only looks.
    const readOnly = asOwner && props.canManage === false;
    const actionable = (product: ProductRow) => !readOnly && (tab === 'mine' || asOwner || product.assignee === null);
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
        setBusy((current) => new Set([...current, ...ids]));
        const options = {
            preserveScroll: true,
            preserveState: true,
            // Several quick taps each go through instead of cancelling the
            // one before; only the list and the tab counts reload.
            async: true,
            only: ['products', 'counts'],
            onSuccess: () => {
                setSelected(new Set());
                setSelecting(false);
            },
            // Someone else may have taken the product a moment ago; the
            // list reloads with the errors, so it shows who holds it now.
            onError: (errors: Record<string, string>) => toast.error(Object.values(errors)[0]),
            onFinish: () => {
                setBusy((current) => new Set([...current].filter((id) => !ids.includes(id))));
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
    // The product page is its sales: it opens where those pages are in use.
    const linked = can('catalog', 'performance', 'stagnant');

    /**
     * Just arrived: launched in the last two weeks on offer, or taken in
     * the last day among one's own.
     */
    const isNew = (product: ProductRow) => {
        const since = tab === 'mine' ? product.assigned_at : product.launched_at;
        const span = tab === 'mine' ? DAY : 14 * DAY;

        return since !== null && Date.now() - new Date(since).getTime() < span;
    };

    /** When it was taken (own list) or launched (on offer); recent ones read "3 days ago". */
    const rowDate = (product: ProductRow, labelled = true) => {
        const at = tab === 'mine' ? product.assigned_at : product.launched_at;
        if (!at) return '—';
        const when = Date.now() - new Date(at).getTime() < 7 * DAY ? relative(at) : date(at);

        return labelled ? tr(tab === 'mine' ? p.addedToMine : p.launchedOn, { date: when }) : when;
    };

    /** The one thing to do with a product here: add it, remove it, or see who has it. */
    const action = (product: ProductRow, compact: boolean) => {
        if (readOnly) return null;

        const taken = product.assignee !== null && product.assignee.id !== member.id;
        const working = busy.has(product.id);

        if (tab === 'mine') {
            return compact ? (
                <Button
                    variant="ghost"
                    size="icon"
                    className="text-muted-foreground hover:text-danger active:text-danger size-11"
                    disabled={working}
                    onClick={() => release([product.id])}
                    aria-label={tr(p.removeOne, { name: product.name })}
                >
                    {working ? <Spinner /> : <X className="size-5" />}
                </Button>
            ) : (
                <Button variant="ghost" size="sm" className="text-muted-foreground hover:text-danger h-8" disabled={working} onClick={() => release([product.id])}>
                    {working ? <Spinner /> : <X className="size-4" />}
                    {p.release}
                </Button>
            );
        }

        if (taken && !asOwner) {
            return (
                <span className="bg-muted text-muted-foreground inline-flex max-w-32 items-center gap-1.5 rounded-full px-2.5 py-1 text-xs whitespace-nowrap">
                    <Lock className="size-3 shrink-0" />
                    <span className="truncate">{tr(p.heldBy, { name: product.assignee?.name ?? '' })}</span>
                </span>
            );
        }

        const label = taken ? tr(p.moveFrom, { name: product.assignee?.name ?? '' }) : asOwner ? p.assign : compact ? p.claimShort : p.claim;

        return (
            <Button
                variant="ghost"
                className={cn('bg-primary/10 text-primary hover:bg-primary/15 hover:text-primary font-semibold', compact ? 'h-11 min-w-11 rounded-xl px-3.5' : 'h-8 px-3')}
                size="sm"
                disabled={working}
                onClick={() => add([product.id])}
                title={taken ? label : undefined}
            >
                {working ? <Spinner /> : <Plus className="size-4" />}
                <span className={cn(compact && taken && 'max-w-20 truncate')}>{label}</span>
            </Button>
        );
    };

    return (
        <PageBody>
            <PageHeader
                title={asOwner ? tr(p.memberTitle, { name: member.name }) : p.myTitle}
                description={asOwner ? p.memberSubtitle : p.mySubtitle}
                icon={PackageCheck}
                eyebrow={
                    asOwner ? (
                        <Link href="/store/team/report" className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1">
                            <ArrowLeft className="size-3 rtl:rotate-180" />
                            {t.nav.teamReport}
                        </Link>
                    ) : undefined
                }
                actions={
                    asOwner ? (
                        <>
                            <PeriodPicker
                                period={{ months: props.months, month: props.month }}
                                available={props.availableMonths}
                                onChange={(next: Period) => visit({ months: next.months, month: next.month }, ['stats', 'products'])}
                            />
                            {pages && (
                                <Button variant="outline" onClick={() => setAccessOpen(true)}>
                                    <ShieldCheck className="size-4" />
                                    {t.team.access.button}
                                    <span className="num text-muted-foreground text-xs font-normal">
                                        {number(pages.granted.length)}/{number(Object.values(pages.groups).flat().length)}
                                    </span>
                                </Button>
                            )}
                        </>
                    ) : undefined
                }
            />
            {pages && (
                <MemberAccessDialog
                    open={accessOpen}
                    onOpenChange={setAccessOpen}
                    member={{ id: member.id, name: member.name, permissions: pages.granted }}
                    sections={pages.groups}
                />
            )}

            {stats && (
                <Stagger className={cn('grid grid-cols-2 gap-3 sm:gap-4 max-sm:[&>*:last-child:nth-child(odd)]:col-span-2', showRevenue ? 'xl:grid-cols-5' : 'xl:grid-cols-4')}>
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

            <section ref={listRef} className={cn('scroll-mt-20 space-y-3', picked.length > 0 && 'pb-24 md:pb-0')}>
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="bg-muted grid w-full grid-cols-2 rounded-xl p-1 sm:inline-flex sm:w-fit">
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
                                    'flex h-10 cursor-pointer items-center justify-center gap-2 rounded-lg px-3 text-sm font-medium transition-colors sm:h-auto sm:py-1.5',
                                    tab === segment.key ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                <segment.icon className="size-4 shrink-0" />
                                <span className="truncate">{segment.label}</span>
                                <span className="num bg-background/70 rounded-md px-1.5 text-xs">{number(segment.count)}</span>
                            </button>
                        ))}
                    </div>
                    <div className="flex w-full flex-wrap gap-2 sm:w-auto sm:flex-nowrap">
                        {/* The manager has two filters, so on a phone the search takes its own row. */}
                        <div className={cn('relative min-w-0 flex-1 sm:w-64 sm:flex-none', asOwner && 'basis-full sm:basis-auto')}>
                            <Search className="text-muted-foreground pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2" />
                            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={p.searchPlaceholder} className="h-10 ps-9 pe-9 sm:h-9" />
                            {search && (
                                <button
                                    type="button"
                                    onClick={() => setSearch('')}
                                    aria-label={t.common.clear}
                                    className="text-muted-foreground hover:text-foreground absolute end-1 top-1/2 flex size-8 -translate-y-1/2 cursor-pointer items-center justify-center rounded-md"
                                >
                                    <X className="size-4" />
                                </button>
                            )}
                        </div>
                        {props.counts.categories.length > 0 && (
                            <Select value={props.filters.category ?? ALL} onValueChange={(v) => visit({ category: v === ALL ? '' : v }, ['products'])}>
                                <SelectTrigger className={cn('h-10 sm:h-9 sm:w-44 sm:max-w-none', asOwner ? 'flex-1' : 'w-auto max-w-[42%]')} aria-label={p.allCategories}>
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
                        {asOwner && (
                            <Select value={props.sort} onValueChange={(v) => visit({ sort: v as Sort }, ['products'])}>
                                <SelectTrigger className="h-10 flex-1 sm:h-9 sm:w-40 sm:flex-none" aria-label={p.sortBy}>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="newest">{p.sort.newest}</SelectItem>
                                    <SelectItem value="units">{p.sort.units}</SelectItem>
                                </SelectContent>
                            </Select>
                        )}
                    </div>
                </div>

                {tab === 'add' && !asOwner && props.filters.search && <p className="text-muted-foreground text-xs">{p.heldHint}</p>}

                {rows.length > 0 && (
                    <div className="text-muted-foreground flex min-h-8 items-center justify-between gap-2 text-xs">
                        <span className="flex items-center gap-1.5">
                            <ArrowDownWideNarrow className="size-3.5" />
                            <span className="num">{tr(p.resultCount, { count: number(props.products.total) })}</span>
                            <span aria-hidden>·</span>
                            <span>{props.sort === 'units' ? p.bestFirst : p.newestFirst}</span>
                        </span>
                        {pickable.length > 0 && (
                            <Button
                                variant="ghost"
                                size="sm"
                                className={cn('text-foreground h-8', cardsOnly)}
                                onClick={() => {
                                    setSelecting((on) => !on);
                                    setSelected(new Set());
                                }}
                            >
                                {selecting ? p.cancelSelect : p.select}
                            </Button>
                        )}
                    </div>
                )}

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
                        <>
                            {/* Phone: one card per product, its action always in reach. */}
                            <ul className={cn('divide-border/70 divide-y', cardsOnly)}>
                                <AnimatePresence initial={false}>
                                    {rows.map((product) => {
                                        const taken = product.assignee !== null && product.assignee.id !== member.id;
                                        const muted = taken && !asOwner;
                                        const canPick = selecting && actionable(product);

                                        return (
                                            <motion.li
                                                key={product.id}
                                                layout="position"
                                                exit={{ opacity: 0, height: 0 }}
                                                transition={{ duration: 0.2 }}
                                                className="overflow-hidden"
                                            >
                                                <div
                                                    className={cn('flex items-center gap-3 p-3', canPick && 'cursor-pointer', canPick && selected.has(product.id) && 'bg-primary/5')}
                                                    onClick={canPick ? () => toggle(product.id, !selected.has(product.id)) : undefined}
                                                >
                                                    {canPick && (
                                                        <Checkbox
                                                            className="size-5 rounded-full"
                                                            checked={selected.has(product.id)}
                                                            onClick={(event) => event.stopPropagation()}
                                                            onCheckedChange={(value) => toggle(product.id, value === true)}
                                                            aria-label={product.name}
                                                        />
                                                    )}
                                                    <Thumb src={product.image_url} alt={product.name} className={cn('size-14 rounded-xl', muted && 'opacity-60')} />
                                                    <div className={cn('min-w-0 flex-1 space-y-0.5', muted && 'opacity-60')}>
                                                        <div className="flex items-start gap-1.5">
                                                            <ProductName product={product} linked={linked && !selecting} className="line-clamp-2 text-sm leading-snug" />
                                                            {isNew(product) && <NewBadge />}
                                                        </div>
                                                        <ProductMeta product={product} />
                                                        <p className="text-muted-foreground text-xs">{rowDate(product)}</p>
                                                        {asOwner && (
                                                            <div className="flex items-center gap-2 pt-1 text-xs">
                                                                <PerformanceBadge value={product.performance} />
                                                                <span className="num font-semibold">{tr(p.unitsCell, { units: number(product.units) })}</span>
                                                            </div>
                                                        )}
                                                    </div>
                                                    {!selecting && <div className="shrink-0">{action(product, true)}</div>}
                                                </div>
                                            </motion.li>
                                        );
                                    })}
                                </AnimatePresence>
                            </ul>

                            {/* Wider screens: the table. */}
                            <div className={cn('overflow-x-auto', tableOnly)}>
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
                                            {asOwner ? (
                                                <>
                                                    <TableHead>{p.columns.class}</TableHead>
                                                    <TableHead className="text-end">{p.columns.units}</TableHead>
                                                    <TableHead className="text-end">{p.columns.orders}</TableHead>
                                                    {showRevenue && <TableHead className="text-end">{p.columns.revenue}</TableHead>}
                                                    <TableHead>{p.columns.trend}</TableHead>
                                                </>
                                            ) : (
                                                <TableHead className="w-44">
                                                    <span className="inline-flex items-center gap-1">
                                                        {tab === 'mine' ? p.columns.addedAt : p.columns.launchedAt}
                                                        <ArrowDownWideNarrow className="size-3.5" aria-label={p.newestFirst} />
                                                    </span>
                                                </TableHead>
                                            )}
                                            <TableHead className="w-44" />
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {rows.map((product) => {
                                            const taken = product.assignee !== null && product.assignee.id !== member.id;
                                            const expanded = open.has(product.id);

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
                                                                    <div className="flex items-center gap-1.5">
                                                                        <ProductName product={product} linked={linked} className="line-clamp-1 max-w-72" />
                                                                        {isNew(product) && <NewBadge />}
                                                                    </div>
                                                                    <ProductMeta product={product} />
                                                                </div>
                                                            </div>
                                                        </TableCell>
                                                        {asOwner ? (
                                                            <>
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
                                                                        <Sparkline data={(product.history ?? []).map((point) => point.units)} width={84} height={26} />
                                                                        <ChevronDown className={cn('text-muted-foreground size-4 transition-transform', expanded && 'rotate-180')} />
                                                                    </button>
                                                                </TableCell>
                                                            </>
                                                        ) : (
                                                            <TableCell className="text-muted-foreground text-sm whitespace-nowrap">{rowDate(product, false)}</TableCell>
                                                        )}
                                                        <TableCell className="text-end">{action(product, false)}</TableCell>
                                                    </TableRow>
                                                    {expanded && (
                                                        <TableRow className="hover:bg-transparent">
                                                            <TableCell colSpan={columns} className="bg-muted/30 pt-0 pb-4">
                                                                <MonthCells history={product.history ?? []} />
                                                            </TableCell>
                                                        </TableRow>
                                                    )}
                                                </Fragment>
                                            );
                                        })}
                                    </TableBody>
                                </Table>
                            </div>
                        </>
                    )}
                </div>

                <Pagination meta={props.products} only={['products']} />
            </section>

            {picked.length > 0 && (
                <div className="bg-card fixed inset-x-3 bottom-[calc(0.75rem+env(safe-area-inset-bottom))] z-30 flex items-center gap-2 rounded-2xl border p-2 shadow-lg md:sticky md:inset-x-auto md:bottom-4 md:mx-auto md:w-fit md:max-w-full md:flex-wrap md:px-3">
                    <span className="num hidden px-1 text-sm font-medium md:inline">{tr(p.selected, { count: number(picked.length) })}</span>
                    {tab === 'mine' ? (
                        <Button variant="destructive" className="h-11 flex-1 md:h-8 md:flex-none" size="sm" onClick={() => setConfirmRelease(true)}>
                            <X className="size-4" />
                            {tr(p.releaseSelected, { count: number(picked.length) })}
                        </Button>
                    ) : (
                        <Button className="h-11 flex-1 md:h-8 md:flex-none" size="sm" disabled={busy.size > 0} onClick={() => add(picked)}>
                            {busy.size > 0 ? <Spinner /> : <Plus className="size-4" />}
                            {tr(asOwner ? p.assignSelected : p.claimSelected, { count: number(picked.length) })}
                        </Button>
                    )}
                    <Button
                        variant="ghost"
                        className="h-11 md:h-8"
                        size="sm"
                        onClick={() => {
                            setSelected(new Set());
                            setSelecting(false);
                        }}
                    >
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

const DAY = 86_400_000;

function ProductName({ product, linked, className }: { product: ProductRow; linked: boolean; className?: string }) {
    return linked ? (
        <Link href={`/store/products/${product.id}`} className={cn('min-w-0 font-medium hover:underline', className)} dir="auto">
            {product.name}
        </Link>
    ) : (
        <span className={cn('min-w-0 font-medium', className)} dir="auto">
            {product.name}
        </span>
    );
}

function ProductMeta({ product }: { product: ProductRow }) {
    const { money } = useI18n();

    return (
        <p className="text-muted-foreground flex flex-wrap gap-x-2 text-xs">
            {product.sku && <span dir="ltr">{product.sku}</span>}
            {product.category && <span>{product.category}</span>}
            {product.price !== null && <span className="num">{money(product.price)}</span>}
        </p>
    );
}

function NewBadge() {
    const { t } = useI18n();

    return <span className="bg-success-soft text-success mt-0.5 shrink-0 rounded-full px-1.5 py-px text-[10px] leading-4 font-semibold">{t.team.products.newBadge}</span>;
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

function Thumb({ src, alt, className }: { src: string | null; alt: string; className?: string }) {
    const [broken, setBroken] = useState(false);

    return src && !broken ? (
        <img src={src} alt={alt} loading="lazy" onError={() => setBroken(true)} className={cn('bg-muted size-11 shrink-0 rounded-lg object-cover', className)} />
    ) : (
        <span className={cn('bg-muted text-muted-foreground/60 flex size-11 shrink-0 items-center justify-center rounded-lg', className)}>
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

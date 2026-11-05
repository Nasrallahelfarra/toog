import { router } from '@inertiajs/react';
import { ArrowLeft, BarChart3, CalendarDays, FolderOpen, ImageOff, LayoutGrid, Package, Search, ShoppingBag, Table2, Trophy, Turtle, Wallet } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { Area, AreaChart, Bar, BarChart, CartesianGrid, ReferenceDot, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { ChartTooltip, useChartAxes } from '@/components/mojo/chart';
import { ColorChips } from '@/components/mojo/color-chips';
import { PerformanceBadge } from '@/components/mojo/decision-badge';
import { EmptyState } from '@/components/mojo/empty-state';
import { Meter } from '@/components/mojo/meter';
import { FadeIn, HoverLift, Stagger, StaggerItem } from '@/components/mojo/motion';
import { PageBody, PageHeader } from '@/components/mojo/page-header';
import { Pagination } from '@/components/mojo/pagination';
import type { Paginated } from '@/components/mojo/pagination';
import { SectionCard } from '@/components/mojo/section-card';
import { Sparkline, StatCard } from '@/components/mojo/stat-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

type Metric = 'revenue' | 'units' | 'orders';
type Range = 3 | 6 | 12;

type TrendPoint = { period: string; revenue: number; units: number; orders: number; partial: boolean; selected: boolean };

type Category = {
    key: string;
    name: string | null;
    products: number;
    revenue: number;
    units: number;
    share: number;
    winners: number;
    stagnant: number;
    images: string[];
    spark: number[];
};

type Product = {
    id: number;
    name: string;
    sku: string | null;
    category: string | null;
    price: number | null;
    image_url: string | null;
    performance: string | null;
    performance_score: number | null;
    units_sold: number;
    revenue: number;
    /** Sales in the chosen month, when one is picked. */
    month_units: number | null;
    month_revenue: number | null;
    stock: number;
    turnover_days: number | null;
    return_rate: number;
    features: { colors?: string[]; pattern?: string; material?: string; style?: string };
    launched_at: string | null;
    history: { period: string; units: number; revenue: number }[];
};

type Filters = { search?: string; class?: string; sort?: string };

type Props = {
    months: Range;
    /** A chosen month (Y-m) that replaces the range, or null. */
    month: string | null;
    availableMonths: string[];
    category: string | null;
    filters: Filters;
    summary: {
        revenue: number;
        units: number;
        orders: number;
        products: number;
        avg_price: number;
        delta: Record<Metric, number | null>;
    };
    trend: TrendPoint[];
    categories: Category[];
    products: Paginated<Product>;
};

const UNCATEGORIZED = '__none__';
const ALL = '__all__';
const CLASSES = ['winner', 'average', 'at_risk', 'stagnant', 'unclassified'] as const;
const SORTS = ['revenue', 'units', 'newest', 'price_low', 'price_high', 'stock'] as const;

type Params = { months: Range; month?: string | null; category: string | null } & Filters;

/** Reload only what a change affects, keeping the other params in the URL. */
function visit(params: Params, only: string[], preserveScroll = true) {
    const query = Object.fromEntries(Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== ''));
    router.get('/store/catalog', query, { preserveState: true, preserveScroll, replace: true, only: [...only, 'months', 'month', 'category', 'filters'] });
}

export default function Catalog(props: Props) {
    const { t, tr, money, number, date } = useI18n();
    const c = t.catalog;
    const [metric, setMetric] = useState<Metric>('revenue');
    const params: Params = { months: props.months, month: props.month, category: props.category, ...props.filters };
    const monthName = (key: string) => date(`${key}-01`, { month: 'long', year: 'numeric' });
    const vsPrevious = props.month ? c.kpi.vsPreviousMonth : c.kpi.vsPrevious;
    // A month changes every number on the page, so everything but the month list reloads.
    const pickMonth = (key: string | null) => visit({ ...params, month: key }, ['summary', 'trend', 'categories', 'products']);
    const current = props.categories.find((cat) => cat.key === props.category) ?? null;
    const categoryName = (cat: { name: string | null } | null) => cat?.name ?? c.uncategorized;
    const scope = props.category ? categoryName(current ?? { name: props.category === UNCATEGORIZED ? null : props.category }) : c.storeScope;

    const openCategory = (key: string | null) =>
        visit({ months: props.months, month: props.month, category: key }, ['summary', 'trend', 'products'], false);

    return (
        <PageBody>
            <PageHeader
                title={props.category ? scope : c.title}
                description={c.description}
                icon={LayoutGrid}
                eyebrow={
                    props.category ? (
                        <button type="button" onClick={() => openCategory(null)} className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1">
                            <ArrowLeft className="size-3 rtl:rotate-180" />
                            {c.allCategories}
                        </button>
                    ) : undefined
                }
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <Tabs
                            value={props.month ? '' : String(props.months)}
                            onValueChange={(v) => visit({ ...params, month: null, months: Number(v) as Range }, ['summary', 'trend', 'categories', 'products'])}
                        >
                            <TabsList>
                                {([3, 6, 12] as Range[]).map((range) => (
                                    <TabsTrigger key={range} value={String(range)} className="px-3">
                                        {c.ranges[range]}
                                    </TabsTrigger>
                                ))}
                            </TabsList>
                        </Tabs>
                        {props.availableMonths.length > 0 && (
                            <Select value={props.month ?? ALL} onValueChange={(v) => pickMonth(v === ALL ? null : v)}>
                                <SelectTrigger className={cn('w-44', props.month && 'border-primary text-primary')} aria-label={c.pickMonth}>
                                    <CalendarDays className="size-4" />
                                    <SelectValue placeholder={c.pickMonth} />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>{c.pickMonth}</SelectItem>
                                    {props.availableMonths.map((key) => (
                                        <SelectItem key={key} value={key}>
                                            {monthName(key)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}
                    </div>
                }
            />

            {props.categories.length === 0 ? (
                <SectionCard>
                    <EmptyState icon={Package} title={c.noCategories} />
                </SectionCard>
            ) : (
                <>
                    <Stagger className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <StaggerItem>
                            <StatCard
                                label={c.kpi.revenue}
                                value={props.summary.revenue}
                                format={(n) => money(n)}
                                icon={Wallet}
                                tone="gold"
                                delta={props.summary.delta.revenue}
                                deltaLabel={vsPrevious}
                                spark={props.trend.map((p) => p.revenue)}
                            />
                        </StaggerItem>
                        <StaggerItem>
                            <StatCard
                                label={c.kpi.units}
                                value={props.summary.units}
                                format={(n) => number(n)}
                                icon={ShoppingBag}
                                tone="navy"
                                delta={props.summary.delta.units}
                                deltaLabel={vsPrevious}
                                spark={props.trend.map((p) => p.units)}
                            />
                        </StaggerItem>
                        <StaggerItem>
                            <StatCard
                                label={c.kpi.orders}
                                value={props.summary.orders}
                                format={(n) => number(n)}
                                icon={BarChart3}
                                tone="info"
                                delta={props.summary.delta.orders}
                                deltaLabel={vsPrevious}
                            />
                        </StaggerItem>
                        <StaggerItem>
                            <StatCard
                                label={c.kpi.products}
                                value={props.summary.products}
                                format={(n) => number(n)}
                                icon={Package}
                                tone="success"
                                hint={props.summary.avg_price ? tr(c.kpi.avgPrice, { price: money(props.summary.avg_price) }) : undefined}
                            />
                        </StaggerItem>
                    </Stagger>

                    <FadeIn delay={0.05}>
                        <SalesChart
                            trend={props.trend}
                            metric={metric}
                            onMetric={setMetric}
                            scope={props.month ? `${scope} · ${monthName(props.month)}` : scope}
                            pickable={props.availableMonths}
                            onPick={pickMonth}
                        />
                    </FadeIn>

                    {!props.category && <CategoryGrid categories={props.categories} onOpen={openCategory} />}

                    <ProductsSection
                        key={props.category ?? ALL}
                        products={props.products}
                        params={params}
                        title={props.category ? tr(c.productsIn, { category: scope }) : c.productsTitle}
                        hint={props.month ? tr(c.productsHintMonth, { month: monthName(props.month) }) : c.productsHint}
                    />
                </>
            )}
        </PageBody>
    );
}

/** Monthly sales for the store or one category: one measure at a time, one axis. */
function SalesChart({
    trend,
    metric,
    onMetric,
    scope,
    pickable,
    onPick,
}: {
    trend: TrendPoint[];
    metric: Metric;
    onMetric: (m: Metric) => void;
    scope: string;
    pickable: string[];
    onPick: (month: string) => void;
}) {
    const { t, tr, money, number, compact, date } = useI18n();
    const c = t.catalog;
    const axes = useChartAxes();
    const [table, setTable] = useState(false);
    const format = (value: number) => (metric === 'revenue' ? money(value) : number(value));
    const empty = trend.every((p) => p[metric] === 0);
    const peak = useMemo(() => trend.reduce((best, p) => (p[metric] > best[metric] ? p : best), trend[0]), [trend, metric]);
    const selected = trend.find((p) => p.selected) ?? null;
    const monthKey = (period: string) => period.slice(0, 7);
    const partial = trend.at(-1)?.partial ?? false;
    const monthLabel = (value: string | number) => date(String(value), { month: 'short', year: '2-digit' });

    return (
        <SectionCard
            title={c.chartTitle}
            description={tr(c.chartScope, { metric: c.metrics[metric], scope })}
            icon={BarChart3}
            action={
                <Tabs value={metric} onValueChange={(v) => onMetric(v as Metric)}>
                    <TabsList>
                        {(['revenue', 'units', 'orders'] as Metric[]).map((m) => (
                            <TabsTrigger key={m} value={m} className="px-3">
                                {c.metrics[m]}
                            </TabsTrigger>
                        ))}
                    </TabsList>
                </Tabs>
            }
        >
            {empty ? (
                <EmptyState icon={BarChart3} title={c.noSales} className="py-10" />
            ) : (
                <div className="space-y-3">
                    <div className="text-muted-foreground flex flex-wrap justify-between gap-x-4 gap-y-1 text-xs">
                        {selected ? (
                            <span className="text-primary font-medium">
                                {tr(c.selectedMonth, { month: date(selected.period, { month: 'long', year: 'numeric' }), value: format(selected[metric]) })}
                            </span>
                        ) : (
                            peak && <span>{tr(c.peak, { month: date(peak.period, { month: 'long', year: 'numeric' }), value: format(peak[metric]) })}</span>
                        )}
                        <span>{c.chartClickHint}</span>
                    </div>
                    <div className="h-72" role="img" aria-label={tr(c.chartScope, { metric: c.metrics[metric], scope })}>
                        <ResponsiveContainer width="100%" height="100%">
                            <AreaChart
                                data={trend}
                                margin={{ top: 12, right: 8, left: 8, bottom: 0 }}
                                className="cursor-pointer"
                                onClick={(state) => {
                                    const point = typeof state.activeTooltipIndex === 'number' ? trend[state.activeTooltipIndex] : undefined;
                                    if (point && pickable.includes(monthKey(point.period))) onPick(monthKey(point.period));
                                }}
                            >
                                <defs>
                                    <linearGradient id="catalog-fill" x1="0" x2="0" y1="0" y2="1">
                                        <stop offset="0%" stopColor="var(--chart-1)" stopOpacity={0.28} />
                                        <stop offset="100%" stopColor="var(--chart-1)" stopOpacity={0.02} />
                                    </linearGradient>
                                </defs>
                                <CartesianGrid {...axes.grid} />
                                <XAxis dataKey="period" {...axes.xAxis} tickFormatter={monthLabel} minTickGap={16} />
                                <YAxis {...axes.yAxis} tickFormatter={(v: number) => compact(v)} width={52} />
                                <Tooltip
                                    cursor={{ stroke: 'var(--muted-foreground)', strokeDasharray: '3 3', strokeWidth: 1 }}
                                    content={
                                        <ChartTooltip
                                            labelFormatter={(label) => date(String(label), { month: 'long', year: 'numeric' })}
                                            formatter={(value) => format(value)}
                                        />
                                    }
                                />
                                <Area
                                    type="monotone"
                                    dataKey={metric}
                                    name={c.metrics[metric]}
                                    stroke="var(--chart-1)"
                                    strokeWidth={2}
                                    fill="url(#catalog-fill)"
                                    activeDot={{ r: 5, stroke: 'var(--card)', strokeWidth: 2, fill: 'var(--chart-1)' }}
                                    animationDuration={700}
                                />
                                {selected ? (
                                    <>
                                        <ReferenceLine x={selected.period} stroke="var(--chart-1)" strokeDasharray="4 4" strokeOpacity={0.6} />
                                        <ReferenceDot x={selected.period} y={selected[metric]} r={7} fill="var(--chart-1)" stroke="var(--card)" strokeWidth={3} />
                                    </>
                                ) : (
                                    peak && peak[metric] > 0 && <ReferenceDot x={peak.period} y={peak[metric]} r={5} fill="var(--chart-1)" stroke="var(--card)" strokeWidth={2} />
                                )}
                            </AreaChart>
                        </ResponsiveContainer>
                    </div>
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="text-muted-foreground text-xs">{partial ? c.partialMonth : ''}</p>
                        <Button variant="ghost" size="sm" className="h-8" onClick={() => setTable((v) => !v)} aria-expanded={table}>
                            <Table2 className="size-4" />
                            {table ? c.hideTable : c.showTable}
                        </Button>
                    </div>
                    {table && (
                        <div className="overflow-x-auto rounded-xl border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>{c.month}</TableHead>
                                        <TableHead className="text-end">{c.metrics.revenue}</TableHead>
                                        <TableHead className="text-end">{c.metrics.units}</TableHead>
                                        <TableHead className="text-end">{c.metrics.orders}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {trend.map((p) => (
                                        <TableRow key={p.period}>
                                            <TableCell>{date(p.period, { month: 'long', year: 'numeric' })}</TableCell>
                                            <TableCell className="num text-end">{money(p.revenue)}</TableCell>
                                            <TableCell className="num text-end">{number(p.units)}</TableCell>
                                            <TableCell className="num text-end">{number(p.orders)}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </div>
            )}
        </SectionCard>
    );
}

/** Categories as small multiples: each card carries its own revenue line, so no colour has to tell them apart. */
function CategoryGrid({ categories, onOpen }: { categories: Category[]; onOpen: (key: string) => void }) {
    const { t, tr, money, number } = useI18n();
    const c = t.catalog;

    return (
        <section className="space-y-3">
            <div className="flex flex-wrap items-end justify-between gap-2">
                <div>
                    <h2 className="text-lg font-semibold">{c.categoriesTitle}</h2>
                    <p className="text-muted-foreground text-sm">{c.categoriesHint}</p>
                </div>
            </div>
            <Stagger className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                {categories.map((cat) => (
                    <StaggerItem key={cat.key}>
                        <HoverLift className="h-full">
                            <button
                                type="button"
                                onClick={() => onOpen(cat.key)}
                                className="bg-card focus-visible:ring-ring group flex h-full w-full flex-col overflow-hidden rounded-2xl border border-border/70 text-start shadow-[0_1px_2px_rgba(16,24,40,0.04)] transition-shadow hover:shadow-md focus-visible:ring-2 focus-visible:outline-none"
                            >
                                <ImageStrip images={cat.images} />
                                <div className="flex flex-1 flex-col gap-3 p-4">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <h3 className="truncate font-semibold">{cat.name ?? c.uncategorized}</h3>
                                            <p className="text-muted-foreground text-xs">{tr(c.productsCount, { count: number(cat.products) })}</p>
                                        </div>
                                        <Sparkline data={cat.spark} width={88} height={32} />
                                    </div>
                                    <div className="flex items-baseline justify-between gap-2">
                                        <span className="num text-xl font-semibold">{money(cat.revenue)}</span>
                                        <span className="text-muted-foreground text-xs">{tr(c.share, { share: number(cat.share, 1) })}</span>
                                    </div>
                                    <Meter value={cat.share} tone="navy" height={5} />
                                    <div className="mt-auto flex flex-wrap gap-2 text-[11px]">
                                        <span className="bg-success-soft text-success inline-flex items-center gap-1 rounded-full px-2 py-0.5">
                                            <Trophy className="size-3" />
                                            {tr(c.winners, { count: number(cat.winners) })}
                                        </span>
                                        <span className="bg-danger-soft text-danger inline-flex items-center gap-1 rounded-full px-2 py-0.5">
                                            <Turtle className="size-3" />
                                            {tr(c.stagnant, { count: number(cat.stagnant) })}
                                        </span>
                                    </div>
                                </div>
                            </button>
                        </HoverLift>
                    </StaggerItem>
                ))}
            </Stagger>
        </section>
    );
}

function ImageStrip({ images }: { images: string[] }) {
    if (images.length === 0) {
        return (
            <div className="bg-muted/60 text-muted-foreground/60 flex h-24 items-center justify-center">
                <FolderOpen className="size-8" />
            </div>
        );
    }

    return (
        <div className="bg-muted/40 grid h-24 grid-cols-3 gap-0.5">
            {[0, 1, 2].map((i) =>
                images[i] ? (
                    <img key={i} src={images[i]} alt="" loading="lazy" className="size-full object-cover transition-transform duration-500 group-hover:scale-105" />
                ) : (
                    <div key={i} className="bg-muted/60" />
                ),
            )}
        </div>
    );
}

function ProductsSection({ products, params, title, hint }: { products: Paginated<Product>; params: Params; title: string; hint: string }) {
    const { t } = useI18n();
    const c = t.catalog;
    const [search, setSearch] = useState(params.search ?? '');
    const [open, setOpen] = useState<Product | null>(null);
    const apply = (next: Partial<Filters>) => visit({ ...params, ...next }, ['products']);

    useEffect(() => {
        if (search === (params.search ?? '')) return;
        const timer = window.setTimeout(() => apply({ search: search || undefined }), 350);
        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    return (
        <section className="space-y-4">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 className="text-lg font-semibold">{title}</h2>
                    <p className="text-muted-foreground text-sm">{hint}</p>
                </div>
                <div className="flex w-full flex-wrap gap-2 sm:w-auto">
                    <div className="relative min-w-0 flex-1 sm:w-64 sm:flex-none">
                        <Search className="text-muted-foreground pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2" />
                        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={c.searchPlaceholder} className="ps-9" />
                    </div>
                    <Select value={params.class ?? ALL} onValueChange={(v) => apply({ class: v === ALL ? undefined : v })}>
                        <SelectTrigger className="w-40">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>{c.allClasses}</SelectItem>
                            {CLASSES.map((cls) => (
                                <SelectItem key={cls} value={cls}>
                                    {cls === 'unclassified' ? c.unclassified : t.common.performanceClass[cls]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select value={params.sort ?? 'revenue'} onValueChange={(v) => apply({ sort: v === 'revenue' ? undefined : v })}>
                        <SelectTrigger className="w-44">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {SORTS.map((sort) => (
                                <SelectItem key={sort} value={sort}>
                                    {c.sort[sort]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            </div>

            {products.data.length === 0 ? (
                <SectionCard>
                    <EmptyState icon={Package} title={c.noProducts} />
                </SectionCard>
            ) : (
                <Stagger key={`${products.current_page}-${params.sort}-${params.class}-${params.search}`} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                    {products.data.map((product) => (
                        <StaggerItem key={product.id}>
                            <ProductCard product={product} onOpen={() => setOpen(product)} />
                        </StaggerItem>
                    ))}
                </Stagger>
            )}
            <Pagination meta={products} />
            <ProductSheet product={open} onClose={() => setOpen(null)} />
        </section>
    );
}

function ProductCard({ product, onOpen }: { product: Product; onOpen: () => void }) {
    const { t, tr, money, number } = useI18n();
    const c = t.catalog;
    const [broken, setBroken] = useState(false);

    return (
        <HoverLift className="h-full">
            <button
                type="button"
                onClick={onOpen}
                aria-haspopup="dialog"
                className="bg-card focus-visible:ring-ring group flex h-full w-full flex-col overflow-hidden rounded-2xl border border-border/70 text-start shadow-[0_1px_2px_rgba(16,24,40,0.04)] transition-shadow hover:shadow-md focus-visible:ring-2 focus-visible:outline-none"
            >
                <div className="bg-muted/50 relative aspect-[4/3] overflow-hidden">
                    {product.image_url && !broken ? (
                        <img
                            src={product.image_url}
                            alt={product.name}
                            loading="lazy"
                            onError={() => setBroken(true)}
                            className="size-full object-cover transition-transform duration-500 group-hover:scale-105"
                        />
                    ) : (
                        <div className="text-muted-foreground/60 flex size-full flex-col items-center justify-center gap-1 text-xs">
                            <ImageOff className="size-7" />
                            {c.noImage}
                        </div>
                    )}
                    <PerformanceBadge value={product.performance} className="absolute start-2 top-2 shadow-sm" />
                </div>
                <div className="flex flex-1 flex-col gap-2 p-4">
                    <h3 className="line-clamp-2 text-sm leading-6 font-medium">{product.name}</h3>
                    <div className="flex items-baseline justify-between gap-2">
                        <span className="num font-semibold">{product.price !== null ? money(product.price) : '—'}</span>
                        <span className="text-muted-foreground text-xs">{tr(c.unitsSold, { n: number(product.month_units ?? product.units_sold) })}</span>
                    </div>
                    <div className="mt-auto flex items-end justify-between gap-2 pt-1">
                        <span className="num text-muted-foreground text-xs">{money(product.month_revenue ?? product.revenue)}</span>
                        <Sparkline data={product.history.map((h) => h.units)} width={84} height={28} />
                    </div>
                </div>
            </button>
        </HoverLift>
    );
}

function ProductSheet({ product, onClose }: { product: Product | null; onClose: () => void }) {
    const { t, tr, money, number, percent, date, compact, isRtl } = useI18n();
    const c = t.catalog;
    const axes = useChartAxes();
    const [metric, setMetric] = useState<'units' | 'revenue'>('units');
    const sold = product?.history.some((h) => h.units > 0) ?? false;
    const facts: [string, string][] = product
        ? [
              [c.detail.price, product.price !== null ? money(product.price) : '—'],
              [c.detail.score, product.performance_score !== null ? `${product.performance_score}/100` : '—'],
              [c.detail.units, number(product.units_sold)],
              [c.detail.revenue, money(product.revenue)],
              [c.detail.stock, number(product.stock)],
              [c.detail.turnover, product.turnover_days !== null ? tr(t.performance.days, { n: number(product.turnover_days) }) : '—'],
              [c.detail.returns, percent(product.return_rate, 1)],
              [c.detail.launched, date(product.launched_at)],
          ]
        : [];

    return (
        <Sheet open={product !== null} onOpenChange={(open) => !open && onClose()}>
            <SheetContent side={isRtl ? 'left' : 'right'} className="w-full overflow-y-auto sm:max-w-lg">
                {product && (
                    <>
                        {product.image_url && <img src={product.image_url} alt={product.name} className="bg-muted aspect-[4/3] w-full object-cover" />}
                        <SheetHeader className="space-y-2 border-b pb-4">
                            <PerformanceBadge value={product.performance} className="w-fit" />
                            <SheetTitle className="text-start text-lg leading-snug">{product.name}</SheetTitle>
                            <SheetDescription className="text-start">
                                {product.category ?? c.uncategorized}
                                {product.sku && ` · ${product.sku}`}
                            </SheetDescription>
                        </SheetHeader>
                        <div className="space-y-6 px-4 pb-6">
                            <section className="space-y-3">
                                <div className="flex items-center justify-between gap-2">
                                    <h3 className="text-sm font-semibold">{c.detail.monthly}</h3>
                                    <Tabs value={metric} onValueChange={(v) => setMetric(v as 'units' | 'revenue')}>
                                        <TabsList className="h-8">
                                            <TabsTrigger value="units" className="px-2.5 text-xs">
                                                {c.metrics.units}
                                            </TabsTrigger>
                                            <TabsTrigger value="revenue" className="px-2.5 text-xs">
                                                {c.metrics.revenue}
                                            </TabsTrigger>
                                        </TabsList>
                                    </Tabs>
                                </div>
                                {sold ? (
                                    <div className="h-48">
                                        <ResponsiveContainer width="100%" height="100%">
                                            <BarChart data={product.history} margin={{ top: 8, right: 4, left: 4, bottom: 0 }}>
                                                <CartesianGrid {...axes.grid} />
                                                <XAxis dataKey="period" {...axes.xAxis} tickFormatter={(v: string) => date(v, { month: 'short' })} interval="preserveStartEnd" minTickGap={8} />
                                                <YAxis {...axes.yAxis} tickFormatter={(v: number) => compact(v)} width={40} />
                                                <Tooltip
                                                    cursor={{ fill: 'var(--muted)', opacity: 0.6 }}
                                                    content={
                                                        <ChartTooltip
                                                            labelFormatter={(label) => date(String(label), { month: 'long', year: 'numeric' })}
                                                            formatter={(value) => (metric === 'revenue' ? money(value) : number(value))}
                                                        />
                                                    }
                                                />
                                                <Bar dataKey={metric} name={c.metrics[metric]} fill="var(--chart-1)" radius={[4, 4, 0, 0]} maxBarSize={22} animationDuration={600} />
                                            </BarChart>
                                        </ResponsiveContainer>
                                    </div>
                                ) : (
                                    <p className="text-muted-foreground rounded-xl border border-dashed p-4 text-sm">{c.detail.noSales}</p>
                                )}
                            </section>

                            <dl className="grid grid-cols-2 gap-2">
                                {facts.map(([label, value]) => (
                                    <div key={label} className="rounded-xl border border-border/60 p-3">
                                        <dt className="text-muted-foreground text-[11px]">{label}</dt>
                                        <dd className="num mt-0.5 text-sm font-semibold">{value}</dd>
                                    </div>
                                ))}
                            </dl>

                            {(product.features.colors?.length ?? 0) > 0 && (
                                <section className="space-y-2">
                                    <h3 className="text-sm font-semibold">{c.detail.attributes}</h3>
                                    <ColorChips colors={product.features.colors ?? []} />
                                </section>
                            )}
                        </div>
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}


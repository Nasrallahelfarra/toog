import { Head, Link, router, useForm, usePage, usePoll } from '@inertiajs/react';
import {
    BarChart3,
    CalendarDays,
    ChevronLeft,
    Coins,
    ImageIcon,
    ImageOff,
    Layers,
    Loader2,
    Minus,
    Package,
    RotateCcw,
    ShoppingCart,
    Sparkles,
    Table2,
    Tag,
    Timer,
    TrendingDown,
    TrendingUp,
    Trophy,
    Wallet,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import { Bar, BarChart, CartesianGrid, Cell, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { ChartTooltip, useChartAxes } from '@/components/mojo/chart';
import { ColorChips } from '@/components/mojo/color-chips';
import { PerformanceBadge } from '@/components/mojo/decision-badge';
import { EmptyState } from '@/components/mojo/empty-state';
import { FadeIn, HoverLift, Stagger, StaggerItem } from '@/components/mojo/motion';
import { PageBody } from '@/components/mojo/page-header';
import { SectionCard } from '@/components/mojo/section-card';
import { RecommendationBody } from '@/components/store/stagnant-products';
import type { Recovery } from '@/components/store/stagnant-products';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useI18n } from '@/hooks/use-i18n';
import { useVocabulary } from '@/hooks/use-vocabulary';
import { cn } from '@/lib/utils';

type ClassKey = 'winner' | 'average' | 'at_risk' | 'stagnant';
type Metric = 'units' | 'revenue' | 'orders';

type Props = {
    product: {
        id: number;
        name: string;
        sku: string | null;
        external_id: string | null;
        category: string | null;
        image_url: string | null;
        performance: ClassKey | null;
        performance_score: number | null;
        price: number | null;
        stock: number;
        units_sold: number;
        revenue: number;
        views: number;
        add_to_cart_rate: number;
        return_rate: number;
        turnover_days: number | null;
        is_novel: boolean;
        launched_at: string | null;
        features: { colors?: string[]; pattern?: string | null; material?: string | null; style?: string | null; season?: string | null };
        classification_reason: 'no_sales_90' | 'slow_turnover' | 'low_performance' | 'early_no_sales' | null;
    };
    history: { period: string; units: number; revenue: number; orders: number; returns: number }[];
    category: {
        key: string;
        name: string | null;
        products: number;
        rank: number;
        median: { price: number | null; units_sold: number | null; revenue: number | null; return_rate: number | null; turnover_days: number | null };
    };
    similar: { id: number; name: string; image_url: string | null; performance: string | null; units_sold: number; price: number | null }[];
    recovery: Recovery | null;
};

const statusTone: Record<ClassKey | 'none', string> = {
    winner: 'bg-success-soft text-success',
    average: 'bg-info-soft text-info',
    at_risk: 'bg-warning-soft text-warning',
    stagnant: 'bg-danger-soft text-danger',
    none: 'bg-muted text-muted-foreground',
};

export default function ProductShow({ product, history, category, similar, recovery }: Props) {
    const { t, tr, money, number, percent, date } = useI18n();
    const c = t.catalog;
    const p = c.product;
    const expertRef = useRef<HTMLDivElement>(null);
    const request = useForm({});
    const [requestError, setRequestError] = useState(false);
    const [networkError, setNetworkError] = useState(false);
    const working = recovery?.status === 'pending' || recovery?.status === 'processing';
    const categoryLabel = category.name ?? c.uncategorized;
    // Opened from a slow-mover card: lead back to that list.
    const fromStagnant = new URLSearchParams(usePage().url.split('?')[1] ?? '').get('from') === 'stagnant';

    const ask = (regenerate = false) => {
        expertRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        setRequestError(false);
        if (recovery && recovery.status !== 'failed' && !regenerate) return;
        request.transform(() => (regenerate && recovery ? { replace_recommendation_id: recovery.id } : {}));
        request.post(`/store/stagnant/${product.id}/recommendation`, {
            preserveScroll: true,
            onError: () => setRequestError(true),
            onHttpException: () => {
                setRequestError(true);
                return false;
            },
            onNetworkError: () => {
                setRequestError(true);
                return false;
            },
        });
    };

    const refresh = () =>
        router.reload({
            only: ['recovery'],
            onSuccess: () => {
                setNetworkError(false);
                setRequestError(false);
            },
            onNetworkError: () => {
                setNetworkError(true);
                return false;
            },
        });

    const status = product.performance ?? 'none';
    const statusText =
        product.performance === 'stagnant' || product.performance === 'at_risk'
            ? (t.stagnant.reasons[product.classification_reason ?? 'low_performance'] ?? '')
            : product.performance
              ? p.status[product.performance]
              : p.status.unclassified;

    const kpis: { icon: LucideIcon; label: string; value: string; hint?: string }[] = [
        { icon: Package, label: p.kpi.units, value: number(product.units_sold) },
        { icon: Wallet, label: p.kpi.revenue, value: money(product.revenue) },
        { icon: Layers, label: p.kpi.stock, value: number(product.stock) },
        { icon: Timer, label: p.kpi.turnover, value: product.turnover_days !== null ? tr(t.performance.days, { n: number(product.turnover_days) }) : '—' },
        { icon: RotateCcw, label: p.kpi.returns, value: percent(product.return_rate, 1) },
        { icon: ShoppingCart, label: p.kpi.cart, value: product.views > 0 ? percent(product.add_to_cart_rate, 1) : '—', hint: product.views > 0 ? undefined : p.kpi.noViews },
        { icon: Trophy, label: p.kpi.rank, value: tr(p.kpi.rankValue, { rank: number(category.rank), total: number(category.products) }) },
        { icon: CalendarDays, label: p.kpi.launched, value: date(product.launched_at) },
    ];

    return (
        <PageBody>
            <Head title={product.name} />
            {working && <RecoveryPoll onNetwork={setNetworkError} />}

            <nav aria-label="breadcrumb" className="text-muted-foreground flex flex-wrap items-center gap-1.5 text-sm">
                {fromStagnant ? (
                    <Link href="/store/stagnant" className="hover:text-foreground">
                        {t.stagnant.breadcrumb}
                    </Link>
                ) : (
                    <>
                        <Link href="/store/catalog" className="hover:text-foreground">
                            {c.title}
                        </Link>
                        <ChevronLeft className="size-3.5 ltr:rotate-180" />
                        <Link href={`/store/catalog?category=${encodeURIComponent(category.key)}`} className="hover:text-foreground">
                            {categoryLabel}
                        </Link>
                    </>
                )}
                <ChevronLeft className="size-3.5 ltr:rotate-180" />
                <span className="text-foreground truncate font-medium">{product.name}</span>
            </nav>

            <FadeIn className="bg-card grid gap-6 overflow-hidden rounded-3xl border border-border/70 p-4 shadow-sm sm:p-6 lg:grid-cols-[minmax(0,420px)_1fr]">
                <ProductImage product={product} />

                <div className="min-w-0 space-y-5">
                    <div className="space-y-3">
                        <div className="flex flex-wrap items-center gap-2">
                            <PerformanceBadge value={product.performance} />
                            {product.is_novel && <span className="bg-gold-soft text-gold-foreground dark:text-gold rounded-full px-2.5 py-0.5 text-[11px] font-medium">{p.novel}</span>}
                            <span className="bg-muted text-muted-foreground inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[11px]">
                                <Tag className="size-3" />
                                {categoryLabel}
                            </span>
                            {product.sku && (
                                <span dir="ltr" className="text-muted-foreground text-[11px]">
                                    SKU {product.sku}
                                </span>
                            )}
                        </div>
                        <h1 className="text-2xl leading-snug font-bold sm:text-3xl">{product.name}</h1>
                        <div className="flex flex-wrap items-end gap-x-6 gap-y-2">
                            <span className="num text-3xl font-semibold">{product.price !== null ? money(product.price) : '—'}</span>
                            {product.performance_score !== null && <ScoreMeter score={product.performance_score} label={p.kpi.score} />}
                        </div>
                    </div>

                    <p className={cn('rounded-xl px-4 py-3 text-sm leading-7', statusTone[status])}>{statusText}</p>

                    <Stagger className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        {kpis.map((kpi) => (
                            <StaggerItem key={kpi.label}>
                                <div className="h-full rounded-xl border border-border/60 p-3">
                                    <p className="text-muted-foreground flex items-center gap-1.5 text-[11px]">
                                        <kpi.icon className="size-3.5 shrink-0" />
                                        {kpi.label}
                                    </p>
                                    <p className="num mt-1 text-sm font-semibold">{kpi.value}</p>
                                    {kpi.hint && <p className="text-muted-foreground mt-0.5 text-[10px]">{kpi.hint}</p>}
                                </div>
                            </StaggerItem>
                        ))}
                    </Stagger>

                    <Attributes features={product.features} />

                    <Button size="lg" className="w-full sm:w-auto" onClick={() => ask()} disabled={request.processing}>
                        {working ? <Loader2 className="size-4 motion-safe:animate-spin" /> : <Sparkles className="size-4" />}
                        {working ? t.stagnant.pending : recovery?.status === 'completed' ? p.viewRecommendation : p.askExpert}
                    </Button>
                </div>
            </FadeIn>

            <div className="grid gap-6 xl:grid-cols-5">
                <FadeIn delay={0.05} className="xl:col-span-3">
                    <SalesChart history={history} />
                </FadeIn>
                <FadeIn delay={0.1} className="xl:col-span-2">
                    <CategoryComparison product={product} category={category} />
                </FadeIn>
            </div>

            <div ref={expertRef} className="scroll-mt-6">
                <SectionCard title={p.expertTitle} description={product.performance ? p.goals[product.performance] : p.goals.none} icon={Sparkles}>
                    {!recovery && !request.processing && !requestError ? (
                        <ExpertIntro onAsk={() => ask()} />
                    ) : (
                        <RecommendationBody
                            recovery={recovery}
                            requestError={requestError}
                            networkError={networkError}
                            sending={request.processing}
                            onRequest={() => ask()}
                            onRegenerate={() => ask(true)}
                            onRefresh={refresh}
                        />
                    )}
                </SectionCard>
            </div>

            {similar.length > 0 && (
                <section className="space-y-3">
                    <h2 className="text-lg font-semibold">{p.similarTitle}</h2>
                    <Stagger className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        {similar.map((item) => (
                            <StaggerItem key={item.id}>
                                <HoverLift className="h-full">
                                    <Link
                                        href={`/store/products/${item.id}`}
                                        className="bg-card group flex h-full items-center gap-3 rounded-2xl border border-border/70 p-3 shadow-sm transition-shadow hover:shadow-md"
                                    >
                                        <span className="bg-muted flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-xl">
                                            {item.image_url ? <img src={item.image_url} alt="" loading="lazy" className="size-full object-cover" /> : <ImageIcon className="text-muted-foreground/50 size-6" />}
                                        </span>
                                        <span className="min-w-0 space-y-1">
                                            <span className="line-clamp-2 block text-sm leading-6 font-medium">{item.name}</span>
                                            <span className="text-muted-foreground flex items-center gap-2 text-xs">
                                                <span className="num">{item.price !== null ? money(item.price) : '—'}</span>
                                                <span>·</span>
                                                <span>{tr(c.unitsSold, { n: number(item.units_sold) })}</span>
                                            </span>
                                        </span>
                                    </Link>
                                </HoverLift>
                            </StaggerItem>
                        ))}
                    </Stagger>
                </section>
            )}
        </PageBody>
    );
}

function RecoveryPoll({ onNetwork }: { onNetwork: (failed: boolean) => void }) {
    usePoll(3000, {
        only: ['recovery'],
        onSuccess: () => onNetwork(false),
        onNetworkError: () => {
            onNetwork(true);
            return false;
        },
    });

    return null;
}

function ProductImage({ product }: { product: Props['product'] }) {
    const { t } = useI18n();
    const [broken, setBroken] = useState(false);

    return (
        <div className="bg-muted/60 relative aspect-square overflow-hidden rounded-2xl">
            {product.image_url && !broken ? (
                <img src={product.image_url} alt={product.name} onError={() => setBroken(true)} className="size-full object-contain transition-transform duration-700 hover:scale-105" />
            ) : (
                <div className="text-muted-foreground/60 flex size-full flex-col items-center justify-center gap-2 text-sm">
                    <ImageOff className="size-10" />
                    {t.catalog.noImage}
                </div>
            )}
        </div>
    );
}

/** The 0-100 performance score as a small bar with its number. */
function ScoreMeter({ score, label }: { score: number; label: string }) {
    const tone = score >= 67 ? 'bg-success' : score <= 33 ? 'bg-danger' : 'bg-info';

    return (
        <div className="min-w-40 space-y-1">
            <div className="text-muted-foreground flex items-baseline justify-between gap-3 text-xs">
                <span>{label}</span>
                <span dir="ltr" className="num text-foreground font-semibold">
                    {score}/100
                </span>
            </div>
            <div className="bg-muted h-1.5 overflow-hidden rounded-full">
                <div className={cn('h-full rounded-full transition-all duration-700', tone)} style={{ width: `${score}%` }} />
            </div>
        </div>
    );
}

function Attributes({ features }: { features: Props['product']['features'] }) {
    const { t } = useI18n();
    const { label } = useVocabulary();
    const p = t.catalog.product;
    const chips = (
        [
            ['patterns', features.pattern],
            ['materials', features.material],
            ['styles', features.style],
            ['seasons', features.season],
        ] as const
    ).filter(([, value]) => value);
    const colors = features.colors ?? [];

    return (
        <div className="space-y-2">
            <h2 className="text-muted-foreground text-xs font-medium">{p.attributes}</h2>
            {colors.length === 0 && chips.length === 0 ? (
                <p className="text-muted-foreground text-sm">{p.noAttributes}</p>
            ) : (
                <div className="flex flex-wrap items-center gap-2">
                    {colors.length > 0 && <ColorChips colors={colors} />}
                    {chips.map(([group, value]) => (
                        <span key={group} className="bg-muted rounded-full px-2.5 py-1 text-xs">
                            {label(group, value)}
                        </span>
                    ))}
                </div>
            )}
        </div>
    );
}

/** Monthly sales: one measure at a time on one axis, the best month emphasised. */
function SalesChart({ history }: { history: Props['history'] }) {
    const { t, tr, money, number, compact, date } = useI18n();
    const p = t.catalog.product;
    const c = t.catalog;
    const axes = useChartAxes();
    const [metric, setMetric] = useState<Metric>('units');
    const [table, setTable] = useState(false);
    const format = (value: number) => (metric === 'revenue' ? money(value) : number(value));
    const best = useMemo(() => history.reduce((top, row) => (row[metric] > top[metric] ? row : top), history[0]), [history, metric]);
    const sold = history.some((row) => row.units > 0);

    return (
        <SectionCard
            title={p.salesTitle}
            description={p.salesHint}
            icon={BarChart3}
            className="h-full"
            action={
                <Tabs value={metric} onValueChange={(v) => setMetric(v as Metric)}>
                    <TabsList>
                        {(['units', 'revenue', 'orders'] as Metric[]).map((m) => (
                            <TabsTrigger key={m} value={m} className="px-3">
                                {c.metrics[m]}
                            </TabsTrigger>
                        ))}
                    </TabsList>
                </Tabs>
            }
        >
            {!sold ? (
                <EmptyState icon={BarChart3} title={p.noSales} className="py-10" />
            ) : (
                <div className="space-y-3">
                    {best && best[metric] > 0 && (
                        <p className="text-muted-foreground text-xs">{tr(p.best, { month: date(best.period, { month: 'long', year: 'numeric' }), value: format(best[metric]) })}</p>
                    )}
                    <div className="h-64">
                        <ResponsiveContainer width="100%" height="100%">
                            <BarChart data={history} margin={{ top: 8, right: 4, left: 4, bottom: 0 }}>
                                <CartesianGrid {...axes.grid} />
                                <XAxis dataKey="period" {...axes.xAxis} tickFormatter={(v: string) => date(v, { month: 'short' })} interval="preserveStartEnd" minTickGap={8} />
                                <YAxis {...axes.yAxis} tickFormatter={(v: number) => compact(v)} width={44} />
                                <Tooltip
                                    cursor={{ fill: 'var(--muted)', opacity: 0.6 }}
                                    content={<ChartTooltip labelFormatter={(label) => date(String(label), { month: 'long', year: 'numeric' })} formatter={(value) => format(value)} />}
                                />
                                <Bar dataKey={metric} name={c.metrics[metric]} radius={[4, 4, 0, 0]} maxBarSize={28} animationDuration={600}>
                                    {history.map((row) => (
                                        <Cell key={row.period} fill="var(--chart-1)" fillOpacity={row.period === best?.period ? 1 : 0.55} />
                                    ))}
                                </Bar>
                            </BarChart>
                        </ResponsiveContainer>
                    </div>
                    <div className="flex justify-end">
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
                                        <TableHead className="text-end">{c.metrics.units}</TableHead>
                                        <TableHead className="text-end">{c.metrics.revenue}</TableHead>
                                        <TableHead className="text-end">{c.metrics.orders}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {history.map((row) => (
                                        <TableRow key={row.period}>
                                            <TableCell>{date(row.period, { month: 'long', year: 'numeric' })}</TableCell>
                                            <TableCell className="num text-end">{number(row.units)}</TableCell>
                                            <TableCell className="num text-end">{money(row.revenue)}</TableCell>
                                            <TableCell className="num text-end">{number(row.orders)}</TableCell>
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

/** The product against its category median on each measure, with a plain-language verdict. */
function CategoryComparison({ product, category }: { product: Props['product']; category: Props['category'] }) {
    const { t, tr, money, number, percent } = useI18n();
    const p = t.catalog.product;
    const rows: { key: keyof Props['category']['median']; value: number | null; better: 'higher' | 'lower' | null; format: (n: number) => string }[] = [
        { key: 'price', value: product.price, better: null, format: (n) => money(n) },
        { key: 'units_sold', value: product.units_sold, better: 'higher', format: (n) => number(n) },
        { key: 'revenue', value: product.revenue, better: 'higher', format: (n) => money(n) },
        { key: 'return_rate', value: product.return_rate, better: 'lower', format: (n) => percent(n, 1) },
        { key: 'turnover_days', value: product.turnover_days, better: 'lower', format: (n) => tr(t.performance.days, { n: number(n) }) },
    ];

    return (
        <SectionCard title={p.compareTitle} description={tr(p.compareHint, { category: category.name ?? t.catalog.uncategorized })} icon={Coins} className="h-full">
            <div className="text-muted-foreground mb-4 flex flex-wrap gap-4 text-[11px]">
                <span className="flex items-center gap-1.5">
                    <span className="size-2.5 rounded-sm" style={{ backgroundColor: 'var(--chart-1)' }} />
                    {p.thisProduct}
                </span>
                <span className="flex items-center gap-1.5">
                    <span className="bg-muted-foreground/40 size-2.5 rounded-sm" />
                    {p.categoryMedian}
                </span>
            </div>
            <ul className="space-y-4">
                {rows.map((row) => {
                    const median = category.median[row.key];
                    const max = Math.max(row.value ?? 0, median ?? 0, 1);
                    const diff = row.value !== null && median ? (row.value - median) / median : null;
                    const verdict = diff === null ? null : Math.abs(diff) <= 0.05 ? 'same' : diff > 0 ? 'above' : 'below';
                    const good = verdict === null || verdict === 'same' || row.better === null ? null : (verdict === 'above') === (row.better === 'higher');
                    const Icon = verdict === 'above' ? TrendingUp : verdict === 'below' ? TrendingDown : Minus;

                    return (
                        <li key={row.key} className="space-y-1.5">
                            <div className="flex items-center justify-between gap-2 text-sm">
                                <span className="font-medium">{p.metrics[row.key]}</span>
                                {verdict && (
                                    <span
                                        className={cn(
                                            'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px]',
                                            good === true ? 'bg-success-soft text-success' : good === false ? 'bg-danger-soft text-danger' : 'bg-muted text-muted-foreground',
                                        )}
                                    >
                                        <Icon className="size-3" />
                                        {p[verdict]}
                                    </span>
                                )}
                            </div>
                            <Bar2 value={row.value ?? 0} max={max} className="bg-[var(--chart-1)]" label={row.value !== null ? row.format(row.value) : '—'} />
                            <Bar2 value={median ?? 0} max={max} className="bg-muted-foreground/40" label={median !== null ? row.format(median) : '—'} />
                        </li>
                    );
                })}
            </ul>
        </SectionCard>
    );
}

function Bar2({ value, max, className, label }: { value: number; max: number; className: string; label: string }) {
    return (
        <div className="flex items-center gap-2">
            <div className="bg-muted/60 h-2 flex-1 overflow-hidden rounded-full">
                <div className={cn('h-full rounded-full transition-all duration-700', className)} style={{ width: `${Math.max(2, (value / max) * 100)}%` }} />
            </div>
            <span className="num text-muted-foreground w-24 shrink-0 text-end text-[11px]">{label}</span>
        </div>
    );
}

function ExpertIntro({ onAsk }: { onAsk: () => void }) {
    const { t } = useI18n();
    const p = t.catalog.product;
    const checks: { icon: LucideIcon; text: string }[] = [
        { icon: ImageIcon, text: p.checks.image },
        { icon: Coins, text: p.checks.price },
        { icon: BarChart3, text: p.checks.sales },
        { icon: CalendarDays, text: p.checks.season },
    ];

    return (
        <div className="border-primary/15 from-primary/5 grid gap-5 rounded-2xl border bg-gradient-to-b to-transparent p-5 md:grid-cols-[1fr_auto] md:items-center">
            <div className="space-y-3">
                <p className="text-sm font-medium">{p.expertChecks}</p>
                <Stagger className="grid gap-2 sm:grid-cols-2">
                    {checks.map((check) => (
                        <StaggerItem key={check.text} className="flex items-center gap-2 text-sm">
                            <span className="bg-primary/10 text-primary flex size-8 shrink-0 items-center justify-center rounded-lg">
                                <check.icon className="size-4" />
                            </span>
                            {check.text}
                        </StaggerItem>
                    ))}
                </Stagger>
                <p className="text-muted-foreground text-xs">{p.expertNote}</p>
            </div>
            <Button size="lg" onClick={onAsk}>
                <Sparkles className="size-4" />
                {p.askExpert}
            </Button>
        </div>
    );
}

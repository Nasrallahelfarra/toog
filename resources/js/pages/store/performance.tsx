import { Link, router } from '@inertiajs/react';
import {
    AlertCircle,
    AlertTriangle,
    BarChart3,
    CheckCircle2,
    ChevronRight,
    Info,
    LineChart as LineChartIcon,
    PackageSearch,
    RefreshCw,
    Search,
    ShieldAlert,
    Sparkles,
    TrendingDown,
    TrendingUp,
    Trophy,
    Turtle,
} from 'lucide-react';
import { useState } from 'react';
import { Area, CartesianGrid, ComposedChart, Line, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { ChartLegend, ChartTooltip, useChartAxes } from '@/components/mojo/chart';
import { ColorChips } from '@/components/mojo/color-chips';
import { PerformanceBadge } from '@/components/mojo/decision-badge';
import { EmptyState } from '@/components/mojo/empty-state';
import { Meter } from '@/components/mojo/meter';
import { FadeIn, Stagger, StaggerItem } from '@/components/mojo/motion';
import { PageBody, PageHeader } from '@/components/mojo/page-header';
import { Pagination } from '@/components/mojo/pagination';
import type { Paginated } from '@/components/mojo/pagination';
import { SectionCard } from '@/components/mojo/section-card';
import { Sparkline } from '@/components/mojo/stat-card';
import { toneClasses } from '@/components/mojo/tone';
import type { Tone } from '@/components/mojo/tone';
import { FeatureExtractionProgress } from '@/components/store/feature-extraction-progress';
import { ClassificationButton, ClassificationProgress } from '@/components/store/classification-refresh';
import type { ClassificationRun } from '@/components/store/classification-refresh';
import type { FeatureExtractionRun } from '@/components/store/feature-extraction-progress';
import { SignalDetails } from '@/components/store/signal-details';
import type { SignalKey, SignalStats } from '@/components/store/signal-details';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Tooltip as UiTooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useI18n } from '@/hooks/use-i18n';
import { useVocabulary } from '@/hooks/use-vocabulary';
import type { VocabularyGroup } from '@/hooks/use-vocabulary';
import { cn } from '@/lib/utils';

type ClassKey = 'winner' | 'average' | 'at_risk' | 'stagnant';
type ProfileGroup = 'colors' | 'patterns' | 'materials' | 'styles' | 'price_tiers';
type ProfileRow = { key: string; winners: number; stagnant: number; lift: number; products: number };
type IssueKey =
    | 'missing_category'
    | 'invalid_price'
    | 'missing_features'
    | 'missing_image'
    | 'duplicate_names'
    | 'duplicate_ids'
    | 'returns_exceed_units'
    | 'negative_stock'
    | 'no_sales_history';

type Product = {
    id: number;
    name: string;
    sku: string | null;
    external_id: string | null;
    category: string | null;
    price: number | null;
    image_url: string | null;
    features: { colors?: string[]; pattern?: string; material?: string; style?: string; season?: string };
    performance: ClassKey | null;
    performance_score: number | null;
    units_sold: number;
    revenue: number;
    views: number;
    add_to_cart_rate: number;
    return_rate: number;
    turnover_days: number | null;
    stock: number;
    is_novel: boolean;
    launched_at: string | null;
    history: { period: string; units: number; revenue: number }[];
};

type Props = {
    summary: {
        classes: Record<ClassKey, { count: number; revenue_share: number }>;
        unclassified: number;
        total: number;
        needs_reclassification: boolean;
        weights: Record<string, number>;
        thresholds: { winner: number; stagnant: number };
    };
    trend: { period: string; revenue: number; units: number; orders: number; returns: number }[];
    categories: { category: string | null; products: number; revenue: number; units: number; winners: number; average: number; at_risk: number; stagnant: number }[];
    profile: Record<ProfileGroup, ProfileRow[]>;
    quality: { key: IssueKey; severity: 'danger' | 'warning' | 'info'; count: number; samples: string[] }[];
    products: Paginated<Product>;
    filters: { class?: string; category?: string; search?: string; sort?: string };
    categoryNames: string[];
    sync: { status: string; last_synced_at: string | null; can_sync: boolean; missing_features: number };
    extraction: FeatureExtractionRun | null;
    classification: ClassificationRun | null;
    /** Loaded on demand when a classification criterion is opened. */
    signals?: Record<SignalKey, SignalStats>;
};

/** Prompt to read the products still missing attributes. */
function ExtractionPrompt({ pending, disabled, onStart }: { pending: number; disabled: boolean; onStart: () => void }) {
    const { t, tr, number } = useI18n();
    const p = t.performance;

    return (
        <FadeIn className="bg-gold-soft flex flex-wrap items-center justify-between gap-3 rounded-2xl px-4 py-3 text-sm">
            <span className="text-gold-foreground dark:text-gold flex items-center gap-2">
                <Sparkles className="size-4 shrink-0" />
                {tr(p.extractHint, { count: number(pending) })}
            </span>
            <Button size="sm" variant="gold" onClick={onStart} disabled={disabled}>
                <Sparkles className="size-4" />
                {p.extractFeatures}
            </Button>
        </FadeIn>
    );
}

const classMeta: Record<ClassKey, { tone: Tone; icon: typeof Trophy }> = {
    winner: { tone: 'success', icon: Trophy },
    average: { tone: 'info', icon: BarChart3 },
    at_risk: { tone: 'warning', icon: AlertTriangle },
    stagnant: { tone: 'danger', icon: Turtle },
};

export default function Performance(props: Props) {
    const { t, tr, relative } = useI18n();
    const p = t.performance;
    const [busy, setBusy] = useState<string | null>(null);

    const post = (url: string, key: string) =>
        router.post(url, {}, { preserveScroll: true, onStart: () => setBusy(key), onFinish: () => setBusy(null) });

    return (
        <PageBody>
            <PageHeader
                title={p.title}
                description={p.description}
                icon={LineChartIcon}
                eyebrow={props.sync.last_synced_at ? tr(p.lastSync, { time: relative(props.sync.last_synced_at) }) : p.neverSynced}
                actions={
                    <>
                        <ClassificationButton run={props.classification} disabled={busy !== null} outline />
                        {props.sync.can_sync ? (
                            <Button onClick={() => post('/store/integrations/zid/sync', 'sync')} disabled={busy !== null}>
                                <RefreshCw className={cn('size-4', busy === 'sync' && 'animate-spin')} />
                                {p.syncNow}
                            </Button>
                        ) : (
                            <Button asChild variant="soft">
                                <Link href="/store/settings/integration">
                                    <RefreshCw className="size-4" />
                                    {p.importData}
                                </Link>
                            </Button>
                        )}
                    </>
                }
            />

            <ClassificationProgress run={props.classification} />

            {props.extraction &&
            (props.extraction.recent ||
                (props.extraction.status === 'failed' && props.sync.missing_features > 0)) ? (
                <FadeIn>
                    <FeatureExtractionProgress
                        run={props.extraction}
                        pending={props.sync.missing_features}
                        disabled={busy !== null}
                        onStart={() => post('/store/performance/extract-features', 'extract')}
                    />
                </FadeIn>
            ) : (
                props.sync.missing_features > 0 && (
                    <ExtractionPrompt
                        pending={props.sync.missing_features}
                        disabled={busy !== null}
                        onStart={() => post('/store/performance/extract-features', 'extract')}
                    />
                )
            )}

            {props.summary.total === 0 ? (
                <SectionCard>
                    <EmptyState
                        icon={PackageSearch}
                        title={p.noProducts}
                        description={p.noProductsHint}
                        action={
                            <Button asChild>
                                <Link href="/store/settings/integration">{p.importData}</Link>
                            </Button>
                        }
                    />
                </SectionCard>
            ) : (
                <>
                    <ClassificationSection summary={props.summary} signals={props.signals} />

                    <div className="grid gap-6 xl:grid-cols-5">
                        <FadeIn delay={0.08} className="xl:col-span-3">
                            <SalesTrend trend={props.trend} />
                        </FadeIn>
                        <FadeIn delay={0.12} className="xl:col-span-2">
                            <CategoryBreakdown categories={props.categories} />
                        </FadeIn>
                    </div>

                    <div className="grid gap-6 xl:grid-cols-5">
                        <FadeIn delay={0.08} className="xl:col-span-3">
                            <SuccessProfile profile={props.profile} />
                        </FadeIn>
                        <FadeIn delay={0.12} className="xl:col-span-2">
                            <DataQuality quality={props.quality} />
                        </FadeIn>
                    </div>

                    <ProductsSection products={props.products} filters={props.filters} categoryNames={props.categoryNames} />
                </>
            )}
        </PageBody>
    );
}

function ClassificationSection({ summary, signals }: { summary: Props['summary']; signals: Props['signals'] }) {
    const { t, tr, number } = useI18n();
    const p = t.performance;
    const total = Math.max(1, summary.total - summary.unclassified);
    const [signal, setSignal] = useState<SignalKey | null>(null);

    const open = (key: SignalKey) => {
        setSignal(key);
        if (!signals) {
            router.reload({ only: ['signals'] });
        }
    };

    return (
        <div className="grid gap-4 lg:grid-cols-4">
            <SignalDetails
                signal={signal}
                weight={signal ? (summary.weights[signal] ?? 0) : 0}
                stats={signal ? signals?.[signal] : undefined}
                onClose={() => setSignal(null)}
            />
            <Stagger className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 lg:col-span-3">
                {summary.needs_reclassification && <p className="rounded-xl bg-warning/10 p-4 text-sm leading-7 sm:col-span-2 xl:col-span-4">{t.stagnant.refreshNeeded}</p>}
                {(['winner', 'average', 'at_risk', 'stagnant'] as ClassKey[]).map((key) => {
                    const meta = classMeta[key];
                    const cls = summary.classes[key];
                    const share = (cls.count / total) * 100;

                    return (
                        <StaggerItem key={key}>
                            <div className="bg-card relative h-full overflow-hidden rounded-2xl border border-border/70 p-5 shadow-[0_1px_2px_rgba(16,24,40,0.04)]">
                                <div className={cn('absolute inset-x-0 top-0 h-1', toneClasses[meta.tone].bg)} />
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <PerformanceBadge value={key} />
                                    <span className={cn('flex size-9 items-center justify-center rounded-xl', toneClasses[meta.tone].soft, toneClasses[meta.tone].text)}>
                                        <meta.icon className="size-[18px]" />
                                    </span>
                                </div>
                                <div className="mt-3 flex items-baseline gap-2">
                                    <span className="num text-3xl font-semibold">{number(cls.count)}</span>
                                    <span className="text-muted-foreground text-sm">{number(share)}%</span>
                                </div>
                                <p className="text-muted-foreground mt-1 text-xs">{tr(p.classShare, { share: cls.revenue_share })}</p>
                                <Meter value={share} tone={meta.tone} height={6} className="mt-3" />
                            </div>
                        </StaggerItem>
                    );
                })}
            </Stagger>

            <FadeIn delay={0.1}>
                <SectionCard title={p.criteriaTitle} icon={Info} className="h-full">
                    <p className="text-muted-foreground mb-3 text-xs leading-relaxed">{p.criteriaHint}</p>
                    <div className="-mx-2 space-y-0.5">
                        {Object.entries(summary.weights).map(([key, weight], i) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() => open(key as SignalKey)}
                                aria-haspopup="dialog"
                                className="group hover:bg-muted/60 focus-visible:ring-ring block w-full rounded-lg px-2 py-1.5 text-start transition-colors focus-visible:ring-2 focus-visible:outline-none"
                            >
                                <Meter
                                    label={
                                        <span className="flex items-center gap-1 text-xs">
                                            {p.criteria[key as keyof typeof p.criteria] ?? key}
                                            <ChevronRight className="text-muted-foreground size-3.5 opacity-40 transition-opacity group-hover:opacity-100 rtl:rotate-180" />
                                        </span>
                                    }
                                    valueLabel={`${weight}%`}
                                    value={weight * 2}
                                    tone="navy"
                                    height={5}
                                    delay={i * 0.05}
                                />
                            </button>
                        ))}
                    </div>
                    <p className="text-muted-foreground mt-2 text-[11px]">{p.criteriaTap}</p>
                    <p className="text-muted-foreground mt-4 border-t pt-3 text-[11px] leading-relaxed">
                        {tr(p.thresholds, { winner: summary.thresholds.winner, stagnant: summary.thresholds.stagnant })}
                    </p>
                    {summary.unclassified > 0 && (
                        <p className="text-muted-foreground mt-2 text-[11px]">{tr(p.unclassifiedCount, { count: summary.unclassified })}</p>
                    )}
                </SectionCard>
            </FadeIn>
        </div>
    );
}

function SalesTrend({ trend }: { trend: Props['trend'] }) {
    const { t, date, compact, money, number } = useI18n();
    const axes = useChartAxes();
    const p = t.performance;
    const data = trend.map((m) => ({ ...m, label: date(m.period, { month: 'short' }) }));

    return (
        <SectionCard
            title={p.trendTitle}
            description={p.trendHint}
            icon={TrendingUp}
            action={
                <ChartLegend
                    items={[
                        { label: p.revenue, color: 'var(--chart-1)' },
                        { label: p.units, color: 'var(--chart-2)' },
                    ]}
                />
            }
            className="h-full"
        >
            <div className="h-72" dir="ltr">
                <ResponsiveContainer width="100%" height="100%">
                    <ComposedChart data={data} margin={{ top: 8, right: 4, left: 4, bottom: 0 }}>
                        <defs>
                            <linearGradient id="perf-revenue" x1="0" x2="0" y1="0" y2="1">
                                <stop offset="0%" stopColor="var(--chart-1)" stopOpacity={0.28} />
                                <stop offset="100%" stopColor="var(--chart-1)" stopOpacity={0} />
                            </linearGradient>
                        </defs>
                        <CartesianGrid {...axes.grid} />
                        <XAxis dataKey="label" {...axes.xAxis} />
                        <YAxis yAxisId="revenue" {...axes.yAxis} width={48} tickFormatter={(v: number) => compact(v)} />
                        <YAxis
                            yAxisId="units"
                            {...axes.yAxis}
                            orientation={axes.yAxis.orientation === 'left' ? 'right' : 'left'}
                            width={36}
                            tickFormatter={(v: number) => compact(v)}
                        />
                        <Tooltip
                            content={
                                <ChartTooltip
                                    formatter={(value, name) => (name === p.revenue ? money(value) : number(value))}
                                />
                            }
                        />
                        <Area
                            yAxisId="revenue"
                            type="monotone"
                            dataKey="revenue"
                            name={p.revenue}
                            stroke="var(--chart-1)"
                            strokeWidth={2.5}
                            fill="url(#perf-revenue)"
                            animationDuration={1000}
                        />
                        <Line
                            yAxisId="units"
                            type="monotone"
                            dataKey="units"
                            name={p.units}
                            stroke="var(--chart-2)"
                            strokeWidth={2}
                            dot={{ r: 3, strokeWidth: 0, fill: 'var(--chart-2)' }}
                            animationDuration={1200}
                        />
                    </ComposedChart>
                </ResponsiveContainer>
            </div>
        </SectionCard>
    );
}

function CategoryBreakdown({ categories }: { categories: Props['categories'] }) {
    const { t, money, number } = useI18n();
    const p = t.performance;
    const maxRevenue = Math.max(1, ...categories.map((c) => c.revenue));

    return (
        <SectionCard title={p.categoriesTitle} icon={BarChart3} className="h-full">
            <Stagger className="space-y-4">
                {categories.map((c) => (
                    <StaggerItem key={c.category ?? '—'} className="space-y-1.5">
                        <div className="flex items-baseline justify-between gap-2 text-sm">
                            <span className="font-medium">{c.category ?? p.uncategorized}</span>
                            <span className="num text-muted-foreground text-xs">
                                {money(c.revenue)} · {number(c.products)}
                            </span>
                        </div>
                        <div className="bg-muted flex h-2.5 overflow-hidden rounded-full" style={{ width: `${Math.max(12, (c.revenue / maxRevenue) * 100)}%` }}>
                            {(['winners', 'average', 'at_risk', 'stagnant'] as const).map((k) => {
                                const tone = k === 'winners' ? 'bg-success' : k === 'average' ? 'bg-info' : k === 'at_risk' ? 'bg-warning' : 'bg-danger';
                                const width = c.products ? (c[k] / c.products) * 100 : 0;

                                return <div key={k} className={cn('h-full transition-all duration-700', tone)} style={{ width: `${width}%` }} />;
                            })}
                        </div>
                        <div className="text-muted-foreground flex flex-wrap gap-3 text-[11px]">
                            <span className="text-success">{t.common.performanceClass.winner} {number(c.winners)}</span>
                            <span className="text-info">{t.common.performanceClass.average} {number(c.average)}</span>
                            <span className="text-warning">{t.common.performanceClass.at_risk} {number(c.at_risk)}</span>
                            <span className="text-danger">{t.common.performanceClass.stagnant} {number(c.stagnant)}</span>
                        </div>
                    </StaggerItem>
                ))}
            </Stagger>
        </SectionCard>
    );
}

function SuccessProfile({ profile }: { profile: Props['profile'] }) {
    const { t } = useI18n();
    const { label } = useVocabulary();
    const p = t.performance;
    const groups: ProfileGroup[] = ['colors', 'patterns', 'materials', 'styles', 'price_tiers'];

    const labelFor = (group: ProfileGroup, key: string) =>
        group === 'price_tiers' ? (p.tiers[key as keyof typeof p.tiers] ?? key) : label(group as VocabularyGroup, key);

    return (
        <SectionCard title={p.profileTitle} description={p.profileHint} icon={Sparkles} className="h-full">
            <Tabs defaultValue="colors">
                <TabsList className="h-auto w-full flex-wrap justify-start">
                    {groups.map((g) => (
                        <TabsTrigger key={g} value={g} className="flex-none">
                            {p.groups[g]}
                        </TabsTrigger>
                    ))}
                </TabsList>
                {groups.map((g) => {
                    const rows = profile[g] ?? [];
                    const best = rows.filter((r) => r.lift > 0).slice(0, 3);
                    const worst = [...rows].reverse().filter((r) => r.lift < 0).slice(0, 3);

                    return (
                        <TabsContent key={g} value={g} className="space-y-4">
                            {rows.length === 0 ? (
                                <EmptyState icon={Sparkles} title={p.noProfile} className="py-8" />
                            ) : (
                                <>
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <AttributeSummary title={p.bestAttributes} tone="success" icon={TrendingUp} rows={best} labelFor={(k) => labelFor(g, k)} group={g} />
                                        <AttributeSummary title={p.worstAttributes} tone="danger" icon={TrendingDown} rows={worst} labelFor={(k) => labelFor(g, k)} group={g} />
                                    </div>
                                    <div className="space-y-3">
                                        {rows.map((row, i) => (
                                            <ProfileBar key={row.key} row={row} label={labelFor(g, row.key)} group={g} delay={i * 0.04} />
                                        ))}
                                    </div>
                                </>
                            )}
                        </TabsContent>
                    );
                })}
            </Tabs>
        </SectionCard>
    );
}

function AttributeSummary({
    title,
    tone,
    icon: Icon,
    rows,
    labelFor,
    group,
}: {
    title: string;
    tone: Tone;
    icon: typeof TrendingUp;
    rows: ProfileRow[];
    labelFor: (key: string) => string;
    group: ProfileGroup;
}) {
    return (
        <div className={cn('rounded-xl p-3', toneClasses[tone].soft)}>
            <div className={cn('mb-2 flex items-center gap-1.5 text-xs font-medium', toneClasses[tone].text)}>
                <Icon className="size-3.5" />
                {title}
            </div>
            <div className="flex flex-wrap gap-1.5">
                {rows.length === 0 && <span className="text-muted-foreground text-xs">—</span>}
                {rows.map((r) => (
                    <span key={r.key} className="bg-card inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs shadow-xs">
                        {group === 'colors' && <ColorChips colors={[r.key]} showLabels={false} />}
                        {labelFor(r.key)}
                        <span className={cn('num font-semibold', toneClasses[tone].text)}>
                            <bdi dir="ltr">
                                {r.lift > 0 ? '+' : ''}
                                {r.lift}
                            </bdi>
                        </span>
                    </span>
                ))}
            </div>
        </div>
    );
}

function ProfileBar({ row, label, group, delay }: { row: ProfileRow; label: string; group: ProfileGroup; delay: number }) {
    const { t, number } = useI18n();
    const p = t.performance;

    return (
        <div className="grid grid-cols-[minmax(0,9rem)_1fr_auto] items-center gap-3">
            <span className="flex min-w-0 items-center gap-1.5 text-sm">
                {group === 'colors' && <ColorChips colors={[row.key]} showLabels={false} className="shrink-0" />}
                <span className="truncate">{label}</span>
            </span>
            <div className="space-y-1">
                <UiTooltip>
                    <TooltipTrigger asChild>
                        <div>
                            <Meter value={row.winners} tone="success" height={6} delay={delay} />
                        </div>
                    </TooltipTrigger>
                    <TooltipContent>
                        {number(row.winners)}% {p.amongWinners}
                    </TooltipContent>
                </UiTooltip>
                <UiTooltip>
                    <TooltipTrigger asChild>
                        <div>
                            <Meter value={row.stagnant} tone="danger" height={6} delay={delay + 0.05} />
                        </div>
                    </TooltipTrigger>
                    <TooltipContent>
                        {number(row.stagnant)}% {p.amongStagnant}
                    </TooltipContent>
                </UiTooltip>
            </div>
            <span
                className={cn(
                    'num w-12 rounded-md px-1.5 py-0.5 text-center text-xs font-semibold',
                    row.lift > 0 ? 'bg-success-soft text-success' : row.lift < 0 ? 'bg-danger-soft text-danger' : 'bg-muted text-muted-foreground',
                )}
            >
                <bdi dir="ltr">
                    {row.lift > 0 ? '+' : ''}
                    {row.lift}
                </bdi>
            </span>
        </div>
    );
}

function DataQuality({ quality }: { quality: Props['quality'] }) {
    const { t, number } = useI18n();
    const p = t.performance;
    const icons = { danger: ShieldAlert, warning: AlertTriangle, info: AlertCircle };
    const tones: Record<string, Tone> = { danger: 'danger', warning: 'warning', info: 'info' };

    return (
        <SectionCard title={p.qualityTitle} description={p.qualityHint} icon={ShieldAlert} className="h-full">
            {quality.length === 0 ? (
                <EmptyState icon={CheckCircle2} title={p.qualityClean} className="py-8" />
            ) : (
                <Stagger className="space-y-2.5">
                    {quality.map((issue) => {
                        const Icon = icons[issue.severity];
                        const tone = toneClasses[tones[issue.severity]];
                        const text = p.issues[issue.key];

                        return (
                            <StaggerItem key={issue.key}>
                                <div className="rounded-xl border border-border/60 p-3">
                                    <div className="flex items-start gap-3">
                                        <span className={cn('mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg', tone.soft, tone.text)}>
                                            <Icon className="size-4" />
                                        </span>
                                        <div className="min-w-0 flex-1 space-y-1">
                                            <div className="flex items-center justify-between gap-2">
                                                <span className="text-sm font-medium">{text?.title ?? issue.key}</span>
                                                <span className={cn('num rounded-full px-2 py-0.5 text-xs font-semibold', tone.soft, tone.text)}>
                                                    {number(issue.count)}
                                                </span>
                                            </div>
                                            <p className="text-muted-foreground text-xs leading-relaxed">{text?.fix}</p>
                                            {issue.samples.length > 0 && (
                                                <p className="text-muted-foreground/80 truncate text-[11px]">
                                                    {p.examples} {issue.samples.join('، ')}
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            </StaggerItem>
                        );
                    })}
                </Stagger>
            )}
        </SectionCard>
    );
}

function ProductsSection({ products, filters, categoryNames }: Pick<Props, 'products' | 'filters' | 'categoryNames'>) {
    const { t, number, money, percent, tr } = useI18n();
    const p = t.performance;
    const [search, setSearch] = useState(filters.search ?? '');
    const [selected, setSelected] = useState<Product | null>(null);

    const apply = (next: Partial<Props['filters']>) =>
        router.get(
            '/store/performance',
            { ...filters, ...next, page: undefined },
            { preserveState: true, preserveScroll: true, only: ['products', 'filters'] },
        );

    const ALL = '__all';

    return (
        <SectionCard
            title={p.productsTitle}
            icon={PackageSearch}
            flush
            action={<span className="text-muted-foreground num text-xs">{number(products.total)}</span>}
        >
            <div className="flex flex-col gap-2 px-5 pb-3 md:flex-row md:items-center">
                <form
                    className="relative flex-1"
                    onSubmit={(e) => {
                        e.preventDefault();
                        apply({ search: search || undefined });
                    }}
                >
                    <Search className="text-muted-foreground absolute start-3 top-1/2 size-4 -translate-y-1/2" />
                    <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={p.searchPlaceholder} className="ps-9" />
                </form>
                <Select value={filters.class ?? ALL} onValueChange={(v) => apply({ class: v === ALL ? undefined : v })}>
                    <SelectTrigger className="md:w-44">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ALL}>{p.allClasses}</SelectItem>
                        <SelectItem value="winner">{t.common.performanceClass.winner}</SelectItem>
                        <SelectItem value="average">{t.common.performanceClass.average}</SelectItem>
                        <SelectItem value="stagnant">{t.common.performanceClass.stagnant}</SelectItem>
                        <SelectItem value="at_risk">{t.common.performanceClass.at_risk}</SelectItem>
                        <SelectItem value="unclassified">{p.unclassified}</SelectItem>
                    </SelectContent>
                </Select>
                <Select value={filters.category ?? ALL} onValueChange={(v) => apply({ category: v === ALL ? undefined : v })}>
                    <SelectTrigger className="md:w-44">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ALL}>{p.allCategories}</SelectItem>
                        {categoryNames.map((c) => (
                            <SelectItem key={c} value={c}>
                                {c}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <Select value={filters.sort ?? 'score'} onValueChange={(v) => apply({ sort: v })}>
                    <SelectTrigger className="md:w-44">
                        <span className="text-muted-foreground text-xs">{p.sortBy}:</span>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {(Object.keys(p.sorts) as (keyof typeof p.sorts)[]).map((key) => (
                            <SelectItem key={key} value={key}>
                                {p.sorts[key]}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            {products.data.length === 0 ? (
                <EmptyState icon={PackageSearch} title={t.common.noResults} className="py-10" />
            ) : (
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="ps-5">{p.columns.product}</TableHead>
                            <TableHead>{p.columns.class}</TableHead>
                            <TableHead>{p.columns.units}</TableHead>
                            <TableHead>{p.columns.revenue}</TableHead>
                            <TableHead>{p.columns.addToCart}</TableHead>
                            <TableHead>{p.columns.returnRate}</TableHead>
                            <TableHead>{p.columns.turnover}</TableHead>
                            <TableHead className="pe-5">{p.columns.features}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {products.data.map((product) => (
                            <TableRow key={product.id} className="cursor-pointer" onClick={() => setSelected(product)}>
                                <TableCell className="ps-5">
                                    <div className="flex items-center gap-3">
                                        <ProductThumb product={product} />
                                        <div className="min-w-0">
                                            <div className="max-w-56 truncate font-medium">{product.name}</div>
                                            <div className="text-muted-foreground text-xs">
                                                {product.category ?? p.uncategorized} · {money(product.price)}
                                            </div>
                                        </div>
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <div className="flex items-center gap-2">
                                        <PerformanceBadge value={product.performance} />
                                        {product.performance_score !== null && <span className="num text-muted-foreground text-xs">{product.performance_score}</span>}
                                    </div>
                                </TableCell>
                                <TableCell className="num">{number(product.units_sold)}</TableCell>
                                <TableCell className="num">{money(product.revenue)}</TableCell>
                                <TableCell className="num">{percent(product.add_to_cart_rate, 1)}</TableCell>
                                <TableCell className={cn('num', product.return_rate >= 15 && 'text-danger')}>{percent(product.return_rate, 1)}</TableCell>
                                <TableCell className={cn('num', (product.turnover_days ?? 0) >= 180 && 'text-warning')}>
                                    {product.turnover_days !== null ? tr(p.days, { n: number(product.turnover_days) }) : '—'}
                                </TableCell>
                                <TableCell className="pe-5">
                                    <ColorChips colors={product.features.colors ?? []} showLabels={false} />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            )}

            <Pagination meta={products} className="border-t px-5 py-3" />

            <ProductDrawer product={selected} onClose={() => setSelected(null)} />
        </SectionCard>
    );
}

function ProductThumb({ product }: { product: Product }) {
    const { hex } = useVocabulary();
    const colors = product.features.colors ?? [];

    if (product.image_url) {
        return <img src={product.image_url} alt="" className="size-10 shrink-0 rounded-lg object-cover ring-1 ring-black/5" loading="lazy" />;
    }

    return (
        <div
            className="size-10 shrink-0 rounded-lg ring-1 ring-black/5"
            style={{
                background: colors.length
                    ? `linear-gradient(135deg, ${hex(colors[0])} 0%, ${hex(colors[1] ?? colors[0])} 100%)`
                    : 'var(--muted)',
            }}
        />
    );
}

function ProductDrawer({ product, onClose }: { product: Product | null; onClose: () => void }) {
    const { t, number, money, percent, date, tr, isRtl } = useI18n();
    const { label } = useVocabulary();
    const p = t.performance;

    const facts = product
        ? [
              [p.performanceScore, product.performance_score ?? '—'],
              [p.columns.units, number(product.units_sold)],
              [p.columns.revenue, money(product.revenue)],
              [p.views, number(product.views)],
              [p.columns.addToCart, percent(product.add_to_cart_rate, 1)],
              [p.columns.returnRate, percent(product.return_rate, 1)],
              [p.columns.turnover, product.turnover_days !== null ? tr(p.days, { n: number(product.turnover_days) }) : '—'],
              [p.columns.stock, number(product.stock)],
              [p.launched, date(product.launched_at)],
          ]
        : [];

    return (
        <Sheet open={product !== null} onOpenChange={(open) => !open && onClose()}>
            <SheetContent side={isRtl ? 'left' : 'right'} className="w-full overflow-y-auto sm:max-w-md">
                {product && (
                    <>
                        <SheetHeader className="space-y-2 border-b pb-4">
                            <div className="flex items-center gap-3">
                                <ProductThumb product={product} />
                                <PerformanceBadge value={product.performance} />
                                <span className="bg-muted text-muted-foreground rounded-full px-2 py-0.5 text-[11px]">
                                    {product.is_novel ? p.novel : p.proven}
                                </span>
                            </div>
                            <SheetTitle className="text-start text-lg leading-snug">{product.name}</SheetTitle>
                            <Link href={`/store/products/${product.id}`} className="text-primary w-fit text-xs font-medium hover:underline">
                                {t.catalog.product.open}
                            </Link>
                            <SheetDescription className="text-start">
                                {product.category ?? p.uncategorized} · {money(product.price)} {product.sku && `· ${product.sku}`}
                            </SheetDescription>
                        </SheetHeader>

                        <div className="space-y-6 px-4 pb-6">
                            <div className="space-y-2">
                                <h3 className="text-sm font-semibold">{p.monthlySales}</h3>
                                {product.history.length > 1 ? (
                                    <div className="bg-muted/40 flex items-end justify-between gap-4 rounded-xl p-4">
                                        <div>
                                            <div className="num text-2xl font-semibold">{number(product.history.at(-1)?.units ?? 0)}</div>
                                            <div className="text-muted-foreground text-xs">
                                                {p.units} · {date(product.history.at(-1)?.period, { month: 'long' })}
                                            </div>
                                        </div>
                                        <Sparkline data={product.history.map((h) => h.units)} width={170} height={56} tone="navy" />
                                    </div>
                                ) : (
                                    <p className="text-muted-foreground text-sm">{p.noHistory}</p>
                                )}
                            </div>

                            <dl className="grid grid-cols-2 gap-2">
                                {facts.map(([k, v]) => (
                                    <div key={String(k)} className="rounded-xl border border-border/60 p-3">
                                        <dt className="text-muted-foreground text-[11px]">{k}</dt>
                                        <dd className="num mt-0.5 text-sm font-semibold">{v}</dd>
                                    </div>
                                ))}
                            </dl>

                            <div className="space-y-3">
                                <h3 className="text-sm font-semibold">{t.performance.columns.features}</h3>
                                <ColorChips colors={product.features.colors ?? []} />
                                <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                    {(
                                        [
                                            [p.pattern, label('patterns', product.features.pattern)],
                                            [p.material, label('materials', product.features.material)],
                                            [p.style, label('styles', product.features.style)],
                                            [p.season, label('seasons', product.features.season)],
                                        ] as const
                                    ).map(([k, v]) => (
                                        <div key={k}>
                                            <dt className="text-muted-foreground text-xs">{k}</dt>
                                            <dd>{v}</dd>
                                        </div>
                                    ))}
                                </dl>
                            </div>
                        </div>
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}

import { Link, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    ArrowRight,
    CalendarClock,
    Flame,
    Gauge,
    Layers,
    LayoutDashboard,
    PlugZap,
    Radar,
    Scale,
    ScanSearch,
    ShieldCheck,
    Sparkles,
    TrendingUp,
    WandSparkles,
} from 'lucide-react';
import { motion } from 'motion/react';
import { SetupHero } from '@/components/store/setup-hero';
import { DemoCatalogCleanup } from '@/components/store/demo-catalog-cleanup';
import type { DemoCatalogPreview } from '@/components/store/demo-catalog-cleanup';
import { ZidSyncProgress } from '@/components/store/zid-sync-progress';
import { StagnantDashboardCard } from '@/components/store/stagnant-products';
import type { StagnantDashboard } from '@/components/store/stagnant-products';
import type { ZidSyncProgressData } from '@/components/store/zid-sync-progress';
import type { SetupState } from '@/components/store/setup-hero';
import { Bar, BarChart, CartesianGrid, Cell, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { ChartLegend, ChartTooltip, semanticChart, useChartAxes } from '@/components/mojo/chart';
import { ColorChips } from '@/components/mojo/color-chips';
import { DecisionBadge } from '@/components/mojo/decision-badge';
import { EmptyState } from '@/components/mojo/empty-state';
import { Meter } from '@/components/mojo/meter';
import { FadeIn, HoverLift, Stagger, StaggerItem } from '@/components/mojo/motion';
import { PageBody, PageHeader } from '@/components/mojo/page-header';
import { ScoreRing } from '@/components/mojo/score-ring';
import { SectionCard } from '@/components/mojo/section-card';
import { StatCard } from '@/components/mojo/stat-card';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/hooks/use-i18n';
import { useVocabulary } from '@/hooks/use-vocabulary';
import { cn } from '@/lib/utils';

type Decisions = { accept: number; modify: number; reject: number };

type Trend = {
    id: number;
    type: string;
    value: string | null;
    name_ar: string;
    name_en: string;
    momentum: number;
    growth: string | number;
    saturation: number;
    in_catalog: boolean;
    platforms: string[] | null;
};

type SeasonEvent = {
    id: number;
    season_key: string | null;
    name_ar: string;
    name_en: string;
    starts_on: string;
    ends_on: string;
    launch_by: string;
    days_until: number;
    prep_weeks: number;
    palette: string[];
};

type AtRisk = {
    id: number;
    reason: 'falling' | 'overstock';
    name: string;
    category: string | null;
    performance: string;
    stock: number;
    decline: number;
    months_of_stock: number;
    colors: string[];
};

type RecentEvaluation = {
    id: number;
    name: string;
    category: string | null;
    score: number | null;
    recommendation: string | null;
    final_decision: string | null;
    status: string;
    version: number;
    colors: string[] | null;
    created_at: string;
    image_url: string | null;
};

type Props = {
    stagnant: StagnantDashboard;
    demoCatalog: DemoCatalogPreview | null;
    sync: ZidSyncProgressData | null;
    kpis: {
        evaluated_this_month: number;
        evaluated_delta: number | null;
        acceptance_rate: number | null;
        diversity: number;
        novel_ratio: number;
        target_novel: number;
        balance_alerts: number;
        at_risk: number;
        revenue: number;
        revenue_delta: number | null;
    };
    revenueTrend: number[];
    evaluationsTrend: number[];
    decisions: Decisions;
    byMonth: ({ period: string } & Decisions)[];
    trends: Trend[];
    events: SeasonEvent[];
    atRisk: AtRisk[];
    recentEvaluations: RecentEvaluation[];
    connection: {
        status: string;
        platform: string;
        last_synced_at: string | null;
        check: { ok: boolean; fail: number; checked_at: string } | null;
    };
    setup: SetupState | null;
    thresholds: { accept: number; reject: number };
};

export default function StoreDashboard(props: Props) {
    const { t, tr, number, percent, isRtl } = useI18n();
    const { auth, currentStore } = usePage().props;
    const d = t.dashboard;
    const Arrow = isRtl ? ArrowLeft : ArrowRight;
    const firstName = auth.user.name.split(' ')[0];

    return (
        <PageBody>
            <PageHeader
                title={tr(d.greeting, { name: firstName })}
                eyebrow={currentStore?.name}
                description={tr(d.subtitle, { store: currentStore?.name ?? '' })}
                icon={LayoutDashboard}
                actions={
                    <>
                        <Button asChild variant="outline">
                            <Link href="/store/studio" prefetch>
                                <WandSparkles className="size-4" />
                                {d.generateDesign}
                            </Link>
                        </Button>
                        <Button asChild>
                            <Link href="/store/evaluations/create" prefetch>
                                <ScanSearch className="size-4" />
                                {d.evaluateProduct}
                            </Link>
                        </Button>
                    </>
                }
            />

            {props.demoCatalog && <DemoCatalogCleanup key={currentStore?.id} data={props.demoCatalog} />}
            {props.sync && <ZidSyncProgress key={`${currentStore?.id}-${props.sync.id}`} sync={props.sync} refresh={['sync', 'setup', 'demoCatalog', 'kpis', 'revenueTrend', 'decisions', 'atRisk', 'stagnant']} />}

            {props.setup ? (
                <SetupHero setup={props.setup} />
            ) : (
                <>
                    <ConnectionStrip connection={props.connection} />

                    <Stagger className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <StaggerItem>
                            <StatCard
                                label={d.kpiEvaluated}
                                value={props.kpis.evaluated_this_month}
                                icon={ScanSearch}
                                delta={props.kpis.evaluated_delta}
                                deltaLabel={d.kpiEvaluatedHint}
                                spark={props.evaluationsTrend}
                                href="/store/evaluations"
                            />
                        </StaggerItem>
                        <StaggerItem>
                            <StatCard
                                label={d.kpiAcceptance}
                                value={props.kpis.acceptance_rate}
                                format={(n) => `${Math.round(n)}%`}
                                icon={ShieldCheck}
                                tone="success"
                                hint={d.kpiAcceptanceHint}
                            />
                        </StaggerItem>
                        <StaggerItem>
                            <StatCard
                                label={d.kpiDiversity}
                                value={props.kpis.diversity}
                                format={(n) => `${Math.round(n)}/100`}
                                icon={Layers}
                                tone={props.kpis.diversity < 45 ? 'warning' : 'gold'}
                                hint={tr(d.kpiDiversityHint, { novel: props.kpis.novel_ratio, target: props.kpis.target_novel })}
                                href="/store/balance"
                            />
                        </StaggerItem>
                        <StaggerItem>
                            <StatCard
                                label={d.kpiAtRisk}
                                value={props.kpis.at_risk}
                                icon={AlertTriangle}
                                tone={props.kpis.at_risk > 0 ? 'danger' : 'neutral'}
                                hint={d.kpiAtRiskHint}
                                href="#at-risk"
                            />
                        </StaggerItem>
                    </Stagger>

                    <StagnantDashboardCard data={props.stagnant} />

                    <div className="grid gap-6 lg:grid-cols-3">
                        <FadeIn delay={0.1} className="lg:col-span-2">
                            <EvaluationsChart byMonth={props.byMonth} />
                        </FadeIn>
                        <FadeIn delay={0.15}>
                            <DecisionDonut decisions={props.decisions} />
                        </FadeIn>
                    </div>

                    <div className="grid gap-6 lg:grid-cols-2">
                        <FadeIn delay={0.1}>
                            <SeasonsCard events={props.events} />
                        </FadeIn>
                        <FadeIn delay={0.15}>
                            <TrendsCard trends={props.trends} />
                        </FadeIn>
                    </div>

                    <div className="grid gap-6 lg:grid-cols-2">
                        <FadeIn delay={0.1} id="at-risk">
                            <AtRiskCard items={props.atRisk} />
                        </FadeIn>
                        <FadeIn delay={0.15}>
                            <RecentEvaluationsCard items={props.recentEvaluations} thresholds={props.thresholds} />
                        </FadeIn>
                    </div>

                    <FadeIn delay={0.1} className="space-y-3">
                        <h2 className="text-[15px] font-semibold">{d.quickActions}</h2>
                        <Stagger className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                            {[
                                { href: '/store/evaluations/create', icon: ScanSearch, title: d.actionEvaluate, hint: d.actionEvaluateHint, tone: 'bg-primary text-primary-foreground' },
                                { href: '/store/studio', icon: WandSparkles, title: d.actionStudio, hint: d.actionStudioHint, tone: 'bg-gold text-gold-foreground' },
                                { href: '/store/market', icon: Radar, title: d.actionMarket, hint: d.actionMarketHint, tone: 'bg-info text-white' },
                                { href: '/store/balance', icon: Scale, title: d.actionBalance, hint: d.actionBalanceHint, tone: 'bg-success text-white' },
                            ].map((action) => (
                                <StaggerItem key={action.href}>
                                    <HoverLift>
                                        <Link
                                            href={action.href}
                                            prefetch
                                            className="bg-card group flex items-center gap-4 rounded-2xl border border-border/70 p-4 shadow-[0_1px_2px_rgba(16,24,40,0.04)] transition-shadow hover:shadow-md"
                                        >
                                            <span className={cn('flex size-11 shrink-0 items-center justify-center rounded-xl shadow-sm', action.tone)}>
                                                <action.icon className="size-5" />
                                            </span>
                                            <span className="min-w-0 flex-1">
                                                <span className="block text-sm font-semibold">{action.title}</span>
                                                <span className="text-muted-foreground block truncate text-xs">{action.hint}</span>
                                            </span>
                                            <Arrow className="text-muted-foreground size-4 shrink-0 transition-transform group-hover:-translate-x-0.5 ltr:group-hover:translate-x-0.5" />
                                        </Link>
                                    </HoverLift>
                                </StaggerItem>
                            ))}
                        </Stagger>
                    </FadeIn>

                    {props.kpis.balance_alerts > 0 && (
                        <p className="text-muted-foreground text-center text-xs">
                            <Link href="/store/balance" className="hover:text-foreground underline-offset-4 hover:underline">
                                {tr(d.balanceAlerts, { count: number(props.kpis.balance_alerts) })}
                            </Link>
                            {' · '}
                            {percent(props.kpis.novel_ratio)} / {percent(props.kpis.target_novel)}
                        </p>
                    )}
                </>
            )}
        </PageBody>
    );
}

function ConnectionStrip({ connection }: { connection: Props['connection'] }) {
    const { t, tr, relative } = useI18n();
    const d = t.dashboard;
    const platform = d.platformNames[connection.platform as keyof typeof d.platformNames] ?? connection.platform;

    if (connection.status === 'connected') {
        return (
            <FadeIn delay={0.05} className="text-muted-foreground -mt-2 flex flex-wrap items-center gap-2 text-xs">
                <span className="relative flex size-2">
                    <span className="bg-success absolute inline-flex size-full animate-ping rounded-full opacity-60" />
                    <span className="bg-success relative inline-flex size-2 rounded-full" />
                </span>
                <span>{tr(d.connectedTo, { platform })}</span>
                {connection.last_synced_at && <span>· {tr(d.lastSync, { time: relative(connection.last_synced_at) })}</span>}
                {connection.check && (
                    <Link
                        href="/store/settings/integration"
                        className={cn(
                            'inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium hover:underline',
                            connection.check.ok ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger',
                        )}
                    >
                        {connection.check.ok ? <ShieldCheck className="size-3" /> : <AlertTriangle className="size-3" />}
                        {connection.check.ok ? t.settings.zidCheck.allGood : t.settings.zidCheck.hasIssues}
                    </Link>
                )}
            </FadeIn>
        );
    }

    return (
        <FadeIn
            delay={0.05}
            className={cn(
                'flex flex-wrap items-center justify-between gap-3 rounded-2xl px-4 py-3 text-sm',
                connection.status === 'error' ? 'bg-danger-soft text-danger' : 'bg-gold-soft text-gold-foreground dark:text-gold',
            )}
        >
            <span className="flex items-center gap-2">
                <PlugZap className="size-4" />
                {connection.status === 'error' ? tr(d.connectionError, { platform }) : d.notConnected}
            </span>
            <Button asChild size="sm" variant="outline" className="h-8 bg-transparent">
                <Link href="/store/settings/integration">{d.connectNow}</Link>
            </Button>
        </FadeIn>
    );
}

function EvaluationsChart({ byMonth }: { byMonth: Props['byMonth'] }) {
    const { t, date } = useI18n();
    const axes = useChartAxes();
    const data = byMonth.map((m) => ({ ...m, label: date(m.period, { month: 'short' }) }));
    const series = [
        { key: 'accept', label: t.common.decision.accept, color: semanticChart.accept },
        { key: 'modify', label: t.common.decision.modify, color: semanticChart.modify },
        { key: 'reject', label: t.common.decision.reject, color: semanticChart.reject },
    ] as const;

    return (
        <SectionCard
            title={t.dashboard.evaluationsByMonth}
            description={t.dashboard.evaluationsByMonthHint}
            icon={TrendingUp}
            action={<ChartLegend items={series.map((s) => ({ label: s.label, color: s.color }))} />}
            className="h-full"
        >
            <div className="h-64" dir="ltr">
                <ResponsiveContainer width="100%" height="100%">
                    <BarChart data={data} barSize={26} margin={{ top: 8, right: 4, left: 4, bottom: 0 }}>
                        <CartesianGrid {...axes.grid} />
                        <XAxis dataKey="label" {...axes.xAxis} />
                        <YAxis allowDecimals={false} {...axes.yAxis} width={28} />
                        <Tooltip cursor={{ fill: 'var(--muted)', opacity: 0.5 }} content={<ChartTooltip />} />
                        {series.map((s, i) => (
                            <Bar
                                key={s.key}
                                dataKey={s.key}
                                name={s.label}
                                stackId="decisions"
                                fill={s.color}
                                radius={i === series.length - 1 ? [6, 6, 0, 0] : [0, 0, 0, 0]}
                                animationDuration={900}
                            />
                        ))}
                    </BarChart>
                </ResponsiveContainer>
            </div>
        </SectionCard>
    );
}

function DecisionDonut({ decisions }: { decisions: Decisions }) {
    const { t, number, percent } = useI18n();
    const total = decisions.accept + decisions.modify + decisions.reject;
    const data = [
        { key: 'accept', label: t.common.decision.accept, value: decisions.accept, color: semanticChart.accept },
        { key: 'modify', label: t.common.decision.modify, value: decisions.modify, color: semanticChart.modify },
        { key: 'reject', label: t.common.decision.reject, value: decisions.reject, color: semanticChart.reject },
    ];

    return (
        <SectionCard title={t.dashboard.decisionMix} description={t.dashboard.decisionMixHint} icon={Gauge} className="h-full">
            {total === 0 ? (
                <EmptyState icon={Gauge} title={t.dashboard.noEvaluations} className="py-8" />
            ) : (
                <div className="flex flex-col items-center gap-5">
                    <div className="relative h-48 w-full">
                        <ResponsiveContainer width="100%" height="100%">
                            <PieChart>
                                <Pie
                                    data={data}
                                    dataKey="value"
                                    nameKey="label"
                                    innerRadius={62}
                                    outerRadius={86}
                                    paddingAngle={3}
                                    cornerRadius={6}
                                    stroke="none"
                                    animationDuration={1000}
                                >
                                    {data.map((entry) => (
                                        <Cell key={entry.key} fill={entry.color} />
                                    ))}
                                </Pie>
                                <Tooltip content={<ChartTooltip />} />
                            </PieChart>
                        </ResponsiveContainer>
                        <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                            <span className="num text-3xl font-semibold">{number(total)}</span>
                            <span className="text-muted-foreground text-xs">{t.dashboard.totalEvaluated}</span>
                        </div>
                    </div>
                    <div className="grid w-full grid-cols-3 gap-2 text-center">
                        {data.map((entry) => (
                            <div key={entry.key} className="bg-muted/50 rounded-xl px-2 py-2">
                                <div className="flex items-center justify-center gap-1.5 text-xs">
                                    <span className="size-2 rounded-full" style={{ backgroundColor: entry.color }} />
                                    {entry.label}
                                </div>
                                <div className="num mt-0.5 text-sm font-semibold">{percent((entry.value / total) * 100)}</div>
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </SectionCard>
    );
}

function SeasonsCard({ events }: { events: SeasonEvent[] }) {
    const { t, tr, field, date } = useI18n();
    const d = t.dashboard;
    const today = new Date().toISOString().slice(0, 10);

    return (
        <SectionCard
            title={d.upcomingSeasons}
            description={d.upcomingSeasonsHint}
            icon={CalendarClock}
            action={
                <Button asChild variant="ghost" size="sm" className="h-8">
                    <Link href="/store/calendar">{t.common.seeAll}</Link>
                </Button>
            }
            className="h-full"
        >
            {events.length === 0 ? (
                <EmptyState icon={CalendarClock} title={d.noEvents} className="py-8" />
            ) : (
                <Stagger className="space-y-3">
                    {events.map((event) => {
                        const ongoing = event.days_until <= 0;
                        const overdue = !ongoing && event.launch_by <= today;
                        const windowDays = event.prep_weeks * 7 + 70;
                        const progress = ongoing ? 100 : Math.max(4, Math.min(100, (1 - event.days_until / windowDays) * 100));

                        return (
                            <StaggerItem key={event.id}>
                                <div className="hover:bg-muted/40 flex items-center gap-4 rounded-xl border border-border/60 p-3 transition-colors">
                                    <div
                                        className={cn(
                                            'flex size-14 shrink-0 flex-col items-center justify-center rounded-xl',
                                            ongoing ? 'bg-success-soft text-success' : 'bg-primary/8 text-primary dark:bg-primary/15',
                                        )}
                                    >
                                        <span className="num text-lg leading-none font-semibold">{ongoing ? '•' : event.days_until}</span>
                                        <span className="mt-0.5 text-[10px]">{date(event.starts_on, { month: 'short' })}</span>
                                    </div>
                                    <div className="min-w-0 flex-1 space-y-1.5">
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="truncate text-sm font-semibold">{field(event, 'name')}</span>
                                            <span className="text-muted-foreground shrink-0 text-xs">
                                                {ongoing ? d.ongoing : tr(d.daysLeft, { days: event.days_until })}
                                            </span>
                                        </div>
                                        <div className="bg-muted h-1.5 overflow-hidden rounded-full">
                                            <motion.div
                                                className={cn('h-full rounded-full', overdue || ongoing ? 'bg-warning' : 'bg-gold')}
                                                initial={{ width: 0 }}
                                                animate={{ width: `${progress}%` }}
                                                transition={{ duration: 1, ease: [0.22, 1, 0.36, 1] }}
                                            />
                                        </div>
                                        <div className="flex items-center justify-between gap-2">
                                            <span className={cn('text-xs', overdue ? 'text-warning font-medium' : 'text-muted-foreground')}>
                                                {overdue ? d.launchOverdue : tr(d.launchBy, { date: date(event.launch_by, { day: 'numeric', month: 'short' }) })}
                                            </span>
                                            <ColorChips colors={event.palette} showLabels={false} />
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

function TrendsCard({ trends }: { trends: Trend[] }) {
    const { t, field, number } = useI18n();
    const { hex } = useVocabulary();
    const d = t.dashboard;

    return (
        <SectionCard
            title={d.risingTrends}
            description={d.risingTrendsHint}
            icon={Flame}
            action={
                <Button asChild variant="ghost" size="sm" className="h-8">
                    <Link href="/store/market">{t.common.seeAll}</Link>
                </Button>
            }
            className="h-full"
        >
            {trends.length === 0 ? (
                <EmptyState icon={Flame} title={d.noTrends} className="py-8" />
            ) : (
                <Stagger className="divide-border/60 divide-y">
                    {trends.map((trend, i) => (
                        <StaggerItem key={trend.id} className="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
                            <span
                                className={cn(
                                    'flex size-10 shrink-0 items-center justify-center rounded-xl ring-1 ring-black/5',
                                    trend.type !== 'color' && 'bg-gold-soft text-gold-foreground dark:text-gold',
                                )}
                                style={trend.type === 'color' && trend.value ? { backgroundColor: hex(trend.value) } : undefined}
                            >
                                {trend.type !== 'color' && <Sparkles className="size-4" />}
                            </span>
                            <div className="min-w-0 flex-1 space-y-1.5">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="truncate text-sm font-semibold">{field(trend, 'name')}</span>
                                    <span className="text-muted-foreground text-[11px]">
                                        {d.trendTypes[trend.type as keyof typeof d.trendTypes] ?? trend.type}
                                    </span>
                                    {!trend.in_catalog && (
                                        <span className="bg-gold-soft text-gold-foreground dark:text-gold rounded-full px-2 py-0.5 text-[10px] font-medium">
                                            {d.gap}
                                        </span>
                                    )}
                                </div>
                                <Meter value={trend.momentum} tone="gold" height={5} delay={i * 0.06} />
                            </div>
                            <div className="shrink-0 text-end">
                                <div className="text-success num text-sm font-semibold"><bdi dir="ltr">+{number(trend.growth, 1)}%</bdi></div>
                                <div className="text-muted-foreground text-[11px]">
                                    {d.saturation} <bdi dir="ltr">{number(trend.saturation)}%</bdi>
                                </div>
                            </div>
                        </StaggerItem>
                    ))}
                </Stagger>
            )}
        </SectionCard>
    );
}

function AtRiskCard({ items }: { items: AtRisk[] }) {
    const { t, tr, number } = useI18n();
    const d = t.dashboard;

    return (
        <SectionCard
            title={d.atRiskTitle}
            description={d.atRiskHint}
            icon={AlertTriangle}
            action={
                <Button asChild variant="ghost" size="sm" className="h-8">
                    <Link href="/store/performance">{t.common.seeAll}</Link>
                </Button>
            }
            className="h-full"
        >
            {items.length === 0 ? (
                <EmptyState icon={ShieldCheck} title={d.noAtRisk} className="py-8" />
            ) : (
                <Stagger className="space-y-2">
                    {items.map((item) => (
                        <StaggerItem key={item.id}>
                            <div className="flex items-center gap-3 rounded-xl border border-border/60 p-3">
                                <ColorChips colors={item.colors.slice(0, 2)} showLabels={false} size="md" className="shrink-0" />
                                <div className="min-w-0 flex-1">
                                    <div className="truncate text-sm font-medium">{item.name}</div>
                                    <div className="text-muted-foreground text-xs">
                                        {item.category ?? '—'} · {t.common.performanceClass[item.performance as 'winner' | 'average'] ?? item.performance}
                                    </div>
                                </div>
                                <div className="shrink-0 space-y-1 text-end">
                                    {item.reason === 'falling' ? (
                                        <span className="bg-danger-soft text-danger num inline-block rounded-full px-2 py-0.5 text-[11px] font-medium">
                                            {tr(d.decline, { value: number(item.decline) })}
                                        </span>
                                    ) : (
                                        <span className="bg-warning-soft text-warning inline-block rounded-full px-2 py-0.5 text-[11px] font-medium">
                                            {d.overstock}
                                        </span>
                                    )}
                                    <div className="text-muted-foreground text-[11px]">{tr(d.monthsOfStock, { value: number(item.months_of_stock, 1) })}</div>
                                </div>
                            </div>
                        </StaggerItem>
                    ))}
                </Stagger>
            )}
        </SectionCard>
    );
}

function RecentEvaluationsCard({ items, thresholds }: { items: RecentEvaluation[]; thresholds: Props['thresholds'] }) {
    const { t, tr, relative } = useI18n();
    const d = t.dashboard;

    return (
        <SectionCard
            title={d.recentEvaluations}
            icon={ScanSearch}
            action={
                <Button asChild variant="ghost" size="sm" className="h-8">
                    <Link href="/store/evaluations">{t.common.seeAll}</Link>
                </Button>
            }
            className="h-full"
        >
            {items.length === 0 ? (
                <EmptyState
                    icon={ScanSearch}
                    title={d.noEvaluations}
                    description={d.noEvaluationsHint}
                    action={
                        <Button asChild>
                            <Link href="/store/evaluations/create">{d.evaluateProduct}</Link>
                        </Button>
                    }
                    className="py-8"
                />
            ) : (
                <Stagger className="space-y-1">
                    {items.map((item) => (
                        <StaggerItem key={item.id}>
                            <Link
                                href={`/store/evaluations/${item.id}`}
                                prefetch
                                className="hover:bg-muted/50 flex items-center gap-3 rounded-xl p-2 transition-colors"
                            >
                                <ScoreRing score={item.score} size={46} stroke={5} accept={thresholds.accept} reject={thresholds.reject} />
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <span className="truncate text-sm font-medium">{item.name}</span>
                                        {item.version > 1 && (
                                            <span className="bg-muted text-muted-foreground shrink-0 rounded-md px-1.5 text-[10px]">
                                                {tr(d.version, { n: item.version })}
                                            </span>
                                        )}
                                    </div>
                                    <div className="text-muted-foreground text-xs">
                                        {item.category ?? '—'} · {relative(item.created_at)}
                                    </div>
                                </div>
                                <div className="flex shrink-0 flex-col items-end gap-1">
                                    <DecisionBadge value={item.status === 'completed' ? item.recommendation : item.status} />
                                    {item.final_decision && <DecisionBadge value={item.final_decision} className="opacity-80" />}
                                </div>
                            </Link>
                        </StaggerItem>
                    ))}
                </Stagger>
            )}
        </SectionCard>
    );
}

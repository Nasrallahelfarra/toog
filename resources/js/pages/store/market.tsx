import { Link, router } from '@inertiajs/react';
import {
    Activity,
    ArrowUpRight,
    Eye,
    Heart,
    MessageCircle,
    Radar,
    RefreshCw,
    Share2,
    ShieldCheck,
    Sparkles,
    Store as StoreIcon,
    Swords,
    Tag,
    Target,
    TrendingUp,
    Trophy,
    WandSparkles,
} from 'lucide-react';
import { motion } from 'motion/react';
import { useState } from 'react';
import {
    CartesianGrid,
    Cell,
    Pie,
    PieChart,
    ReferenceArea,
    ResponsiveContainer,
    Scatter,
    ScatterChart,
    Tooltip,
    XAxis,
    YAxis,
    ZAxis,
} from 'recharts';
import { FilterSelect } from '@/components/market/filter-select';
import { PlatformBadge } from '@/components/market/platform-badge';
import { TrendCard } from '@/components/market/trend-card';
import type { Trend } from '@/components/market/trend-card';
import { ChartLegend, useChartAxes } from '@/components/mojo/chart';
import { ColorChips } from '@/components/mojo/color-chips';
import { EmptyState } from '@/components/mojo/empty-state';
import { FadeIn, Stagger, StaggerItem } from '@/components/mojo/motion';
import { PageBody, PageHeader } from '@/components/mojo/page-header';
import { SectionCard } from '@/components/mojo/section-card';
import { StatCard } from '@/components/mojo/stat-card';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

type FeedItem = {
    id: string;
    kind: 'new' | 'price_drop' | 'best_seller';
    name: string;
    competitor: string | null;
    platform: string | null;
    price: number | null;
    previous_price: number | null;
    url: string | null;
    colors: string[];
    date: string | null;
};

type Post = {
    id: number;
    platform: string;
    competitor: string | null;
    caption: string | null;
    url: string | null;
    views: number;
    likes: number;
    shares: number;
    comments: number;
    engagement_rate: number;
    colors: string[];
    posted_at: string | null;
};

type Review = {
    id: number;
    text: string;
    rating: number | null;
    sentiment: 'positive' | 'neutral' | 'negative' | null;
    topics: string[];
    source: string;
    language: string | null;
};

type Props = {
    filters: { type?: string; platform?: string; status?: string };
    trends: Trend[];
    gaps: Trend[];
    stats: { rising: number; gaps: number; competitors: number; avg_engagement: number; tracked_products: number };
    activity: FeedItem[];
    posts: Post[];
    reviews: {
        total: number;
        pending: number;
        sentiment: { positive: number; neutral: number; negative: number };
        topics: { positive: Record<string, number>; negative: Record<string, number> };
        recent: Review[];
    };
    platforms: string[];
    lastAnalysis: string | null;
};

/**
 * Query string that pre-fills the evaluation form with a trend's attribute.
 */
function evaluateHref(trend: Trend): string {
    const field = { color: 'colors[]', pattern: 'pattern', style: 'style', material: 'material', theme: 'season', shape: 'style' }[trend.type];

    return `/store/evaluations/create?${field}=${encodeURIComponent(trend.value ?? '')}`;
}

function studioHref(trend: Trend): string {
    return `/store/studio?prompt=${encodeURIComponent(`${trend.name_en} (${trend.name_ar})`)}`;
}

export default function Market(props: Props) {
    const { t, tr, relative, compact } = useI18n();
    const [refreshing, setRefreshing] = useState(false);
    const [showAll, setShowAll] = useState(false);
    const visibleTrends = showAll ? props.trends : props.trends.slice(0, 9);

    const applyFilter = (key: keyof Props['filters'], value: string | null) => {
        router.get('/store/market', { ...props.filters, [key]: value ?? undefined }, { preserveState: true, preserveScroll: true, replace: true, only: ['trends', 'filters'] });
    };

    const refresh = () =>
        router.post('/store/market/trends/refresh', {}, { preserveScroll: true, onStart: () => setRefreshing(true), onFinish: () => setRefreshing(false) });

    return (
        <PageBody>
            <PageHeader
                title={t.market.title}
                description={t.market.description}
                icon={Radar}
                actions={
                    <>
                        <span className="text-muted-foreground text-xs">
                            {props.lastAnalysis ? tr(t.market.lastAnalysis, { time: relative(props.lastAnalysis) }) : t.market.neverAnalyzed}
                        </span>
                        <Button variant="outline" asChild>
                            <Link href="/store/competitors">
                                <Swords className="size-4" />
                                {t.market.competitors.title}
                            </Link>
                        </Button>
                        <Button onClick={refresh} disabled={refreshing}>
                            <RefreshCw className={cn('size-4', refreshing && 'animate-spin')} />
                            {t.market.refresh}
                        </Button>
                    </>
                }
            />

            <Stagger className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StaggerItem>
                    <StatCard label={t.market.stats.rising} value={props.stats.rising} icon={TrendingUp} tone="success" />
                </StaggerItem>
                <StaggerItem>
                    <StatCard label={t.market.stats.gaps} value={props.stats.gaps} icon={Target} tone="gold" />
                </StaggerItem>
                <StaggerItem>
                    <StatCard
                        label={t.market.stats.competitors}
                        value={props.stats.competitors}
                        icon={Swords}
                        hint={`${compact(props.stats.tracked_products)} ${t.market.stats.tracked}`}
                        href="/store/competitors"
                    />
                </StaggerItem>
                <StaggerItem>
                    <StatCard
                        label={t.market.stats.engagement}
                        value={props.stats.avg_engagement}
                        format={(n) => `${n.toFixed(1)}%`}
                        icon={Activity}
                        tone="info"
                    />
                </StaggerItem>
            </Stagger>

            <GapOpportunities gaps={props.gaps} />

            <div className="grid gap-6 xl:grid-cols-[1fr_380px]">
                <SectionCard
                    title={t.market.trends}
                    description={t.market.trendsHint}
                    icon={Sparkles}
                    action={
                        <div className="hidden flex-wrap gap-2 lg:flex">
                            <TrendFilters props={props} onChange={applyFilter} />
                        </div>
                    }
                >
                    <div className="mb-4 flex flex-wrap gap-2 lg:hidden">
                        <TrendFilters props={props} onChange={applyFilter} />
                    </div>
                    {props.trends.length === 0 ? (
                        <EmptyState icon={Sparkles} title={t.market.noTrends} description={t.market.noTrendsHint} />
                    ) : (
                        <>
                            <Stagger key={JSON.stringify(props.filters)} className="grid gap-4 sm:grid-cols-2 2xl:grid-cols-3">
                                {visibleTrends.map((trend, i) => (
                                    <StaggerItem key={trend.id}>
                                        <TrendCard trend={trend} delay={Math.min(i * 0.04, 0.4)} />
                                    </StaggerItem>
                                ))}
                            </Stagger>
                            {props.trends.length > 9 && (
                                <div className="mt-4 flex justify-center">
                                    <Button variant="ghost" size="sm" onClick={() => setShowAll((v) => !v)}>
                                        {showAll ? t.common.less : `${t.common.seeAll} (${props.trends.length})`}
                                    </Button>
                                </div>
                            )}
                        </>
                    )}
                </SectionCard>

                <OpportunityMap trends={props.trends} />
            </div>

            <div className="grid gap-6 lg:grid-cols-2">
                <ActivityFeed items={props.activity} />
                <TopPosts posts={props.posts} />
            </div>

            <ReviewInsights reviews={props.reviews} />

            <p className="text-muted-foreground flex items-center justify-center gap-1.5 text-center text-xs">
                <ShieldCheck className="size-3.5" />
                {t.market.publicDataNote}
            </p>
        </PageBody>
    );
}

function TrendFilters({
    props: p,
    onChange,
}: {
    props: Props;
    onChange: (key: keyof Props['filters'], value: string | null) => void;
}) {
    const { t } = useI18n();

    return (
        <>
            <FilterSelect
                value={p.filters.type}
                onChange={(v) => onChange('type', v)}
                allLabel={t.market.filters.allTypes}
                options={Object.entries(t.market.types).map(([value, label]) => ({ value, label }))}
            />
            <FilterSelect
                value={p.filters.platform}
                onChange={(v) => onChange('platform', v)}
                allLabel={t.market.filters.allPlatforms}
                options={p.platforms.map((value) => ({ value, label: t.market.platforms[value as keyof typeof t.market.platforms] ?? value }))}
            />
            <FilterSelect
                value={p.filters.status}
                onChange={(v) => onChange('status', v)}
                allLabel={t.market.filters.allStatuses}
                options={Object.entries(t.market.status).map(([value, label]) => ({ value, label }))}
                className="min-w-28"
            />
        </>
    );
}

function GapOpportunities({ gaps }: { gaps: Trend[] }) {
    const { t, field, number } = useI18n();

    return (
        <FadeIn delay={0.1}>
            <section className="relative overflow-hidden rounded-2xl border border-gold/30 bg-gradient-to-br from-gold-soft via-card to-card p-5 shadow-[0_1px_2px_rgba(16,24,40,0.04)]">
                <div className="bg-sadu text-gold/[0.07] pointer-events-none absolute inset-0" />
                <div className="relative mb-4 flex items-start gap-2.5">
                    <span className="bg-gold text-gold-foreground flex size-9 shrink-0 items-center justify-center rounded-xl">
                        <Target className="size-[18px]" />
                    </span>
                    <div>
                        <h2 className="text-[15px] font-semibold">{t.market.gaps}</h2>
                        <p className="text-muted-foreground text-xs">{t.market.gapsHint}</p>
                    </div>
                </div>
                {gaps.length === 0 ? (
                    <p className="text-muted-foreground relative py-4 text-center text-sm">{t.market.noGaps}</p>
                ) : (
                    <Stagger className="relative grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        {gaps.map((gap) => (
                            <StaggerItem key={gap.id}>
                                <div className="bg-card/90 flex h-full flex-col gap-3 rounded-xl border p-4 backdrop-blur">
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="flex items-center gap-2.5">
                                            {gap.type === 'color' && gap.value ? (
                                                <ColorChips colors={[gap.value]} showLabels={false} size="md" />
                                            ) : (
                                                <span className="bg-gold-soft text-gold-foreground dark:text-gold flex size-6 items-center justify-center rounded-full">
                                                    <Sparkles className="size-3.5" />
                                                </span>
                                            )}
                                            <div>
                                                <div className="font-semibold">{field(gap, 'name')}</div>
                                                <div className="text-muted-foreground text-xs">{t.market.types[gap.type]}</div>
                                            </div>
                                        </div>
                                        <span className="bg-success-soft text-success num rounded-full px-2 py-0.5 text-xs font-semibold">
                                            +{number(gap.growth)}%
                                        </span>
                                    </div>
                                    <div className="text-muted-foreground flex gap-4 text-xs">
                                        <span>
                                            {t.market.momentum} <strong className="text-foreground num">{gap.momentum}</strong>
                                        </span>
                                        <span>
                                            {t.market.saturation} <strong className="text-foreground num">{gap.saturation}</strong>
                                        </span>
                                    </div>
                                    <div className="mt-auto flex gap-2">
                                        <Button size="sm" asChild className="flex-1">
                                            <Link href={evaluateHref(gap)}>{t.market.evaluateIdea}</Link>
                                        </Button>
                                        <Button size="sm" variant="outline" asChild className="flex-1">
                                            <Link href={studioHref(gap)}>
                                                <WandSparkles className="size-3.5" />
                                                {t.market.designIdea}
                                            </Link>
                                        </Button>
                                    </div>
                                </div>
                            </StaggerItem>
                        ))}
                    </Stagger>
                )}
            </section>
        </FadeIn>
    );
}

const statusColor: Record<Trend['status'], string> = {
    rising: 'var(--success)',
    stable: 'var(--info)',
    declining: 'var(--danger)',
};

function OpportunityMap({ trends }: { trends: Trend[] }) {
    const { t, field } = useI18n();
    const axes = useChartAxes();
    const data = trends.map((trend) => ({ ...trend, x: trend.saturation, y: trend.momentum, label: field(trend, 'name') }));

    return (
        <SectionCard title={t.market.map} description={t.market.mapHint} icon={Target} className="h-fit xl:sticky xl:top-20">
            <div className="h-72" dir="ltr">
                <ResponsiveContainer width="100%" height="100%">
                    <ScatterChart margin={{ top: 8, right: 8, bottom: 16, left: 0 }}>
                        <CartesianGrid {...axes.grid} vertical />
                        <ReferenceArea
                            x1={0}
                            x2={50}
                            y1={60}
                            y2={100}
                            fill="var(--gold)"
                            fillOpacity={0.12}
                            stroke="var(--gold)"
                            strokeOpacity={0.4}
                            strokeDasharray="4 4"
                            label={{ value: t.market.opportunityZone, position: 'insideTop', fill: 'var(--muted-foreground)', fontSize: 11 }}
                        />
                        <XAxis
                            type="number"
                            dataKey="x"
                            domain={[0, 100]}
                            {...axes.xAxis}
                            label={{ value: t.market.saturation, position: 'insideBottom', offset: -10, fill: 'var(--muted-foreground)', fontSize: 11 }}
                        />
                        <YAxis type="number" dataKey="y" domain={[0, 100]} {...axes.yAxis} width={32} />
                        <ZAxis range={[70, 70]} />
                        <Tooltip
                            cursor={{ strokeDasharray: '3 3' }}
                            content={({ active, payload }) => {
                                const p = payload?.[0]?.payload as (Trend & { label: string }) | undefined;

                                return active && p ? (
                                    <div className="bg-popover/95 rounded-xl border px-3 py-2 text-xs shadow-lg backdrop-blur" dir="auto">
                                        <div className="mb-1 font-semibold">{p.label}</div>
                                        <div className="text-muted-foreground">
                                            {t.market.momentum} {p.momentum} · {t.market.saturation} {p.saturation}
                                        </div>
                                    </div>
                                ) : null;
                            }}
                        />
                        <Scatter data={data} isAnimationActive animationDuration={900}>
                            {data.map((d) => (
                                <Cell key={d.id} fill={statusColor[d.status]} fillOpacity={0.85} stroke="var(--card)" strokeWidth={1.5} />
                            ))}
                        </Scatter>
                    </ScatterChart>
                </ResponsiveContainer>
            </div>
            <ChartLegend
                items={(['rising', 'stable', 'declining'] as const).map((s) => ({ label: t.market.status[s], color: statusColor[s] }))}
            />
        </SectionCard>
    );
}

const kindStyle = {
    new: { icon: Sparkles, className: 'bg-info-soft text-info' },
    price_drop: { icon: Tag, className: 'bg-danger-soft text-danger' },
    best_seller: { icon: Trophy, className: 'bg-gold-soft text-gold-foreground dark:text-gold' },
};

function ActivityFeed({ items }: { items: FeedItem[] }) {
    const { t, money, relative } = useI18n();

    return (
        <SectionCard title={t.market.activity} description={t.market.activityHint} icon={StoreIcon} flush>
            {items.length === 0 ? (
                <EmptyState icon={StoreIcon} title={t.market.noActivity} className="py-10" />
            ) : (
                <ul className="divide-y divide-border/60">
                    {items.map((item, i) => {
                        const style = kindStyle[item.kind];

                        return (
                            <motion.li
                                key={item.id}
                                initial={{ opacity: 0, y: 6 }}
                                animate={{ opacity: 1, y: 0 }}
                                transition={{ delay: Math.min(i * 0.03, 0.3) }}
                                className="flex items-center gap-3 px-5 py-3"
                            >
                                <span className={cn('flex size-9 shrink-0 items-center justify-center rounded-xl', style.className)}>
                                    <style.icon className="size-4" />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <span className="truncate text-sm font-medium">{item.name}</span>
                                    </div>
                                    <div className="text-muted-foreground mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                                        <span>{t.market.kinds[item.kind]}</span>
                                        <span>·</span>
                                        <span>{item.competitor}</span>
                                        {item.platform && <PlatformBadge platform={item.platform} withLabel={false} />}
                                        {item.colors.length > 0 && <ColorChips colors={item.colors} showLabels={false} />}
                                    </div>
                                </div>
                                <div className="shrink-0 text-end">
                                    <div className="num text-sm font-semibold">{money(item.price)}</div>
                                    {item.kind === 'price_drop' && item.previous_price !== null && (
                                        <div className="text-muted-foreground num text-xs line-through">{money(item.previous_price)}</div>
                                    )}
                                    {item.kind !== 'price_drop' && <div className="text-muted-foreground text-xs">{relative(item.date)}</div>}
                                </div>
                                {item.url && (
                                    <a href={item.url} target="_blank" rel="noreferrer" className="text-muted-foreground hover:text-foreground shrink-0">
                                        <ArrowUpRight className="size-4 rtl:-scale-x-100" />
                                    </a>
                                )}
                            </motion.li>
                        );
                    })}
                </ul>
            )}
        </SectionCard>
    );
}

function TopPosts({ posts }: { posts: Post[] }) {
    const { t, compact, relative } = useI18n();
    const max = Math.max(1, ...posts.map((p) => p.engagement_rate));

    return (
        <SectionCard title={t.market.posts} description={t.market.postsHint} icon={TrendingUp} flush>
            {posts.length === 0 ? (
                <EmptyState icon={TrendingUp} title={t.market.noPosts} className="py-10" />
            ) : (
                <ul className="divide-y divide-border/60">
                    {posts.map((post, i) => (
                        <motion.li
                            key={post.id}
                            initial={{ opacity: 0, y: 6 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{ delay: Math.min(i * 0.03, 0.3) }}
                            className="space-y-2 px-5 py-3"
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div className="flex min-w-0 items-start gap-2.5">
                                    <PlatformBadge platform={post.platform} withLabel={false} className="mt-0.5" />
                                    <div className="min-w-0">
                                        <p className="line-clamp-2 text-sm leading-relaxed">{post.caption}</p>
                                        <div className="text-muted-foreground mt-0.5 text-xs">
                                            {post.competitor} · {relative(post.posted_at)}
                                        </div>
                                    </div>
                                </div>
                                <span className="bg-primary/8 text-primary dark:bg-primary/15 num shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold">
                                    {post.engagement_rate.toFixed(1)}%
                                </span>
                            </div>
                            <div className="flex items-center gap-4 ps-8">
                                <div className="bg-muted h-1.5 flex-1 overflow-hidden rounded-full">
                                    <motion.div
                                        className="bg-chart-2 h-full rounded-full"
                                        initial={{ width: 0 }}
                                        animate={{ width: `${(post.engagement_rate / max) * 100}%` }}
                                        transition={{ duration: 0.8, delay: 0.1 + i * 0.04 }}
                                    />
                                </div>
                                <div className="text-muted-foreground num flex shrink-0 items-center gap-3 text-xs">
                                    <span className="flex items-center gap-1">
                                        <Eye className="size-3.5" />
                                        {compact(post.views)}
                                    </span>
                                    <span className="flex items-center gap-1">
                                        <Heart className="size-3.5" />
                                        {compact(post.likes)}
                                    </span>
                                    <span className="hidden items-center gap-1 sm:flex">
                                        <Share2 className="size-3.5" />
                                        {compact(post.shares)}
                                    </span>
                                </div>
                            </div>
                        </motion.li>
                    ))}
                </ul>
            )}
        </SectionCard>
    );
}

const sentimentColor = {
    positive: 'var(--success)',
    neutral: 'var(--info)',
    negative: 'var(--danger)',
};

function ReviewInsights({ reviews }: { reviews: Props['reviews'] }) {
    const { t, tr, number } = useI18n();
    const [busy, setBusy] = useState(false);
    const analyzed = reviews.sentiment.positive + reviews.sentiment.neutral + reviews.sentiment.negative;
    const data = (['positive', 'neutral', 'negative'] as const).map((key) => ({ key, name: t.market.sentiment[key], value: reviews.sentiment[key] }));
    const positiveShare = analyzed ? Math.round((reviews.sentiment.positive / analyzed) * 100) : 0;

    const analyze = () =>
        router.post('/store/market/reviews/analyze', {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });

    return (
        <SectionCard
            title={t.market.reviews}
            description={t.market.reviewsHint}
            icon={MessageCircle}
            action={
                <Button size="sm" variant={reviews.pending > 0 ? 'gold' : 'outline'} onClick={analyze} disabled={busy || reviews.pending === 0}>
                    <Sparkles className={cn('size-4', busy && 'animate-pulse')} />
                    {t.market.analyze}
                </Button>
            }
        >
            {reviews.total === 0 ? (
                <EmptyState icon={MessageCircle} title={t.market.noReviews} className="py-8" />
            ) : (
                <div className="grid gap-6 lg:grid-cols-[220px_1fr_1.2fr]">
                    <div className="flex flex-col items-center gap-3">
                        <div className="relative size-44">
                            <ResponsiveContainer width="100%" height="100%">
                                <PieChart>
                                    <Pie data={data} dataKey="value" innerRadius="68%" outerRadius="100%" paddingAngle={2} stroke="none" animationDuration={900}>
                                        {data.map((d) => (
                                            <Cell key={d.key} fill={sentimentColor[d.key]} />
                                        ))}
                                    </Pie>
                                </PieChart>
                            </ResponsiveContainer>
                            <div className="absolute inset-0 flex flex-col items-center justify-center">
                                <span className="text-success num text-2xl font-semibold">{positiveShare}%</span>
                                <span className="text-muted-foreground text-xs">{t.market.sentiment.positive}</span>
                            </div>
                        </div>
                        <ChartLegend items={data.map((d) => ({ label: `${d.name} (${number(d.value)})`, color: sentimentColor[d.key] }))} />
                        <p className={cn('text-xs', reviews.pending ? 'text-gold-foreground dark:text-gold font-medium' : 'text-muted-foreground')}>
                            {reviews.pending ? tr(t.market.pendingReviews, { count: reviews.pending }) : t.market.allAnalyzed}
                        </p>
                    </div>

                    <div className="space-y-5">
                        <TopicList title={t.market.likesTopics} topics={reviews.topics.positive} tone="success" />
                        <TopicList title={t.market.dislikesTopics} topics={reviews.topics.negative} tone="danger" />
                    </div>

                    <div className="space-y-2">
                        <h3 className="text-muted-foreground text-xs font-medium">{t.market.recentReviews}</h3>
                        <ul className="space-y-2">
                            {reviews.recent.map((review) => (
                                <li key={review.id} className="bg-muted/40 rounded-xl px-3 py-2.5">
                                    <p className="text-sm leading-relaxed" dir="auto">
                                        {review.text}
                                    </p>
                                    <div className="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs">
                                        {review.sentiment ? (
                                            <span
                                                className="rounded-full px-2 py-0.5 font-medium"
                                                style={{ color: sentimentColor[review.sentiment], backgroundColor: `color-mix(in oklch, ${sentimentColor[review.sentiment]} 12%, transparent)` }}
                                            >
                                                {t.market.sentiment[review.sentiment]}
                                            </span>
                                        ) : (
                                            <span className="bg-muted text-muted-foreground rounded-full px-2 py-0.5">{t.common.decision.pending}</span>
                                        )}
                                        {review.topics.map((topic) => (
                                            <span key={topic} className="text-muted-foreground">
                                                #{t.market.topics[topic as keyof typeof t.market.topics] ?? topic}
                                            </span>
                                        ))}
                                        <span className="text-muted-foreground ms-auto">{t.market.sources[review.source as 'store' | 'competitor'] ?? review.source}</span>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            )}
        </SectionCard>
    );
}

function TopicList({ title, topics, tone }: { title: string; topics: Record<string, number>; tone: 'success' | 'danger' }) {
    const { t } = useI18n();
    const entries = Object.entries(topics);
    const max = Math.max(1, ...entries.map(([, v]) => v));

    return (
        <div className="space-y-2">
            <h3 className="text-muted-foreground text-xs font-medium">{title}</h3>
            {entries.length === 0 ? (
                <p className="text-muted-foreground text-xs">—</p>
            ) : (
                <ul className="space-y-1.5">
                    {entries.map(([topic, count], i) => (
                        <li key={topic} className="flex items-center gap-3 text-sm">
                            <span className="w-24 shrink-0 truncate">{t.market.topics[topic as keyof typeof t.market.topics] ?? topic}</span>
                            <div className="bg-muted h-2 flex-1 overflow-hidden rounded-full">
                                <motion.div
                                    className={cn('h-full rounded-full', tone === 'success' ? 'bg-success' : 'bg-danger')}
                                    initial={{ width: 0 }}
                                    animate={{ width: `${(count / max) * 100}%` }}
                                    transition={{ duration: 0.8, delay: i * 0.06 }}
                                />
                            </div>
                            <span className="num text-muted-foreground w-6 text-end text-xs">{count}</span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

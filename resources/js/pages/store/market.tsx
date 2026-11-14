import { Link, router } from '@inertiajs/react';
import {
    Activity,
    Flame,
    MessageCircle,
    Radar,
    RefreshCw,
    ShieldCheck,
    Snowflake,
    Sparkles,
    Target,
    TrendingUp,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { motion } from 'motion/react';
import { useState } from 'react';
import { Cell, Pie, PieChart, ResponsiveContainer } from 'recharts';
import { SectionHead } from '@/components/market/radar/parts';
import {
    FocusCard,
    MoverRow,
    OpportunityCard,
    PulseList,
    RadarLink,
    SourcesBar,
} from '@/components/market/trends/board';
import type { Board } from '@/components/market/trends/types';
import { ChartLegend } from '@/components/mojo/chart';
import { EmptyState } from '@/components/mojo/empty-state';
import { Stagger, StaggerItem } from '@/components/mojo/motion';
import { PageBody, PageHeader } from '@/components/mojo/page-header';
import { SectionCard } from '@/components/mojo/section-card';
import { toneClasses } from '@/components/mojo/tone';
import type { Tone } from '@/components/mojo/tone';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

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
    board: Board;
    reviews: {
        total: number;
        pending: number;
        sentiment: { positive: number; neutral: number; negative: number };
        topics: {
            positive: Record<string, number>;
            negative: Record<string, number>;
        };
        recent: Review[];
    };
    lastAnalysis: string | null;
};

/**
 * Market trends: the market (what active competitors carry and push)
 * against the store's own Zid sales, in four answers: opportunities, what
 * to focus on, what is rising and what is cooling in your sales.
 */
export default function Market({ board, reviews, lastAnalysis }: Props) {
    const { t, tr, relative } = useI18n();
    const b = t.market.board;
    const [refreshing, setRefreshing] = useState(false);

    const refresh = () =>
        router.post(
            '/store/market/trends/refresh',
            {},
            {
                preserveScroll: true,
                onStart: () => setRefreshing(true),
                onFinish: () => setRefreshing(false),
            },
        );

    return (
        <PageBody>
            <PageHeader
                title={t.market.title}
                description={t.market.description}
                icon={TrendingUp}
                actions={
                    <>
                        <span className="text-xs text-muted-foreground">
                            {lastAnalysis
                                ? tr(t.market.lastAnalysis, {
                                      time: relative(lastAnalysis),
                                  })
                                : t.market.neverAnalyzed}
                        </span>
                        <Button variant="outline" asChild>
                            <Link href="/store/competitors">
                                <Radar className="size-4" />
                                {t.market.competitors.title}
                            </Link>
                        </Button>
                        <Button onClick={refresh} disabled={refreshing}>
                            <RefreshCw
                                className={cn(
                                    'size-4',
                                    refreshing && 'animate-spin',
                                )}
                            />
                            {t.market.refresh}
                        </Button>
                    </>
                }
            />

            <SourcesBar board={board} />

            <SummaryNav board={board} />

            <section id="opportunities" className="scroll-mt-24 space-y-4">
                <SectionHead
                    title={b.opportunities.title}
                    hint={b.opportunities.hint}
                    icon={Target}
                />
                {board.opportunities.length > 0 ? (
                    <Stagger className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {board.opportunities.map((item) => (
                            <StaggerItem key={item.key}>
                                <OpportunityCard item={item} />
                            </StaggerItem>
                        ))}
                    </Stagger>
                ) : (
                    <EmptyLine text={b.opportunities.empty} />
                )}
            </section>

            <div className="grid gap-6 xl:grid-cols-[1fr_360px]">
                <section id="focus" className="min-w-0 scroll-mt-24 space-y-4">
                    <SectionHead
                        title={b.focus.title}
                        hint={b.focus.hint}
                        icon={Flame}
                    />
                    {board.focus.length > 0 ? (
                        <Stagger className="grid gap-4 sm:grid-cols-2">
                            {board.focus.map((item) => (
                                <StaggerItem key={item.key}>
                                    <FocusCard item={item} />
                                </StaggerItem>
                            ))}
                        </Stagger>
                    ) : (
                        <EmptyLine text={b.focus.empty} />
                    )}
                </section>

                <SectionCard
                    title={b.pulse.title}
                    description={b.pulse.hint}
                    icon={Activity}
                    className="self-start"
                >
                    {board.pulse.length > 0 ? (
                        <PulseList items={board.pulse} />
                    ) : (
                        <p className="py-6 text-center text-sm text-muted-foreground">
                            {b.pulse.empty}
                        </p>
                    )}
                </SectionCard>
            </div>

            <div className="grid gap-6 lg:grid-cols-2">
                <div id="rising" className="scroll-mt-24">
                    <SectionCard
                        title={b.rising.title}
                        description={b.rising.hint}
                        icon={TrendingUp}
                        className="h-full"
                    >
                        {board.mine_rising.length > 0 ? (
                            <ul className="space-y-2.5">
                                {board.mine_rising.map((item) => (
                                    <MoverRow
                                        key={item.key}
                                        item={item}
                                        kind="rising"
                                        hasMarket={board.basis !== 'none'}
                                    />
                                ))}
                            </ul>
                        ) : (
                            <EmptyLine text={b.rising.empty} />
                        )}
                    </SectionCard>
                </div>
                <div id="cooling" className="scroll-mt-24">
                    <SectionCard
                        title={b.cooling.title}
                        description={b.cooling.hint}
                        icon={Snowflake}
                        className="h-full"
                    >
                        {board.cooling.length > 0 ? (
                            <ul className="space-y-2.5">
                                {board.cooling.map((item) => (
                                    <MoverRow
                                        key={item.key}
                                        item={item}
                                        kind="cooling"
                                        hasMarket={board.basis !== 'none'}
                                    />
                                ))}
                            </ul>
                        ) : (
                            <EmptyLine text={b.cooling.empty} />
                        )}
                    </SectionCard>
                </div>
            </div>

            {board.basis !== 'none' && <RadarLink board={board} />}

            <ReviewInsights reviews={reviews} />

            <p className="flex items-center justify-center gap-1.5 text-center text-xs text-muted-foreground">
                <ShieldCheck className="size-3.5" />
                {t.market.publicDataNote}
            </p>
        </PageBody>
    );
}

/** Four counts that jump to their section. */
function SummaryNav({ board }: { board: Board }) {
    const { t, number } = useI18n();
    const b = t.market.board.nav;
    const tiles: {
        id: string;
        label: string;
        count: number;
        icon: LucideIcon;
        tone: Tone;
    }[] = [
        {
            id: 'opportunities',
            label: b.opportunities,
            count: board.opportunities.length,
            icon: Target,
            tone: 'gold',
        },
        {
            id: 'focus',
            label: b.focus,
            count: board.focus.length,
            icon: Flame,
            tone: 'success',
        },
        {
            id: 'rising',
            label: b.rising,
            count: board.mine_rising.length,
            icon: TrendingUp,
            tone: 'navy',
        },
        {
            id: 'cooling',
            label: b.cooling,
            count: board.cooling.length,
            icon: Snowflake,
            tone: 'warning',
        },
    ];

    return (
        <Stagger className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            {tiles.map((tile) => (
                <StaggerItem key={tile.id}>
                    <a
                        href={`#${tile.id}`}
                        onClick={(e) => {
                            e.preventDefault();
                            document.getElementById(tile.id)?.scrollIntoView({
                                behavior: 'smooth',
                                block: 'start',
                            });
                        }}
                        className="flex h-full items-center gap-3 rounded-2xl border border-border/70 bg-card p-4 shadow-[0_1px_2px_rgba(16,24,40,0.04)] transition-shadow hover:shadow-md"
                    >
                        <span
                            className={cn(
                                'flex size-10 shrink-0 items-center justify-center rounded-xl',
                                toneClasses[tile.tone].soft,
                                toneClasses[tile.tone].text,
                            )}
                        >
                            <tile.icon className="size-[18px]" />
                        </span>
                        <span className="min-w-0">
                            <span className="num block text-2xl leading-none font-semibold">
                                {number(tile.count)}
                            </span>
                            <span className="mt-1 block truncate text-xs text-muted-foreground">
                                {tile.label}
                            </span>
                        </span>
                    </a>
                </StaggerItem>
            ))}
        </Stagger>
    );
}

function EmptyLine({ text }: { text: string }) {
    return (
        <div className="flex items-center justify-center gap-2 rounded-2xl border border-dashed border-border/70 px-4 py-8 text-center text-sm text-muted-foreground">
            <Sparkles className="size-4 shrink-0" />
            {text}
        </div>
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
    const analyzed =
        reviews.sentiment.positive +
        reviews.sentiment.neutral +
        reviews.sentiment.negative;
    const data = (['positive', 'neutral', 'negative'] as const).map((key) => ({
        key,
        name: t.market.sentiment[key],
        value: reviews.sentiment[key],
    }));
    const positiveShare = analyzed
        ? Math.round((reviews.sentiment.positive / analyzed) * 100)
        : 0;

    const analyze = () =>
        router.post(
            '/store/market/reviews/analyze',
            {},
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
            },
        );

    return (
        <SectionCard
            title={t.market.reviews}
            description={t.market.reviewsHint}
            icon={MessageCircle}
            action={
                <Button
                    size="sm"
                    variant={reviews.pending > 0 ? 'gold' : 'outline'}
                    onClick={analyze}
                    disabled={busy || reviews.pending === 0}
                >
                    <Sparkles
                        className={cn('size-4', busy && 'animate-pulse')}
                    />
                    {t.market.analyze}
                </Button>
            }
        >
            {reviews.total === 0 ? (
                <EmptyState
                    icon={MessageCircle}
                    title={t.market.noReviews}
                    className="py-8"
                />
            ) : (
                <div className="grid gap-6 lg:grid-cols-[220px_1fr_1.2fr]">
                    <div className="flex flex-col items-center gap-3">
                        <div className="relative size-44">
                            <ResponsiveContainer width="100%" height="100%">
                                <PieChart>
                                    <Pie
                                        data={data}
                                        dataKey="value"
                                        innerRadius="68%"
                                        outerRadius="100%"
                                        paddingAngle={2}
                                        stroke="none"
                                        animationDuration={900}
                                    >
                                        {data.map((d) => (
                                            <Cell
                                                key={d.key}
                                                fill={sentimentColor[d.key]}
                                            />
                                        ))}
                                    </Pie>
                                </PieChart>
                            </ResponsiveContainer>
                            <div className="absolute inset-0 flex flex-col items-center justify-center">
                                <span className="num text-2xl font-semibold text-success">
                                    {positiveShare}%
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {t.market.sentiment.positive}
                                </span>
                            </div>
                        </div>
                        <ChartLegend
                            items={data.map((d) => ({
                                label: `${d.name} (${number(d.value)})`,
                                color: sentimentColor[d.key],
                            }))}
                        />
                        <p
                            className={cn(
                                'text-xs',
                                reviews.pending
                                    ? 'font-medium text-gold-foreground dark:text-gold'
                                    : 'text-muted-foreground',
                            )}
                        >
                            {reviews.pending
                                ? tr(t.market.pendingReviews, {
                                      count: reviews.pending,
                                  })
                                : t.market.allAnalyzed}
                        </p>
                    </div>

                    <div className="space-y-5">
                        <TopicList
                            title={t.market.likesTopics}
                            topics={reviews.topics.positive}
                            tone="success"
                        />
                        <TopicList
                            title={t.market.dislikesTopics}
                            topics={reviews.topics.negative}
                            tone="danger"
                        />
                    </div>

                    <div className="space-y-2">
                        <h3 className="text-xs font-medium text-muted-foreground">
                            {t.market.recentReviews}
                        </h3>
                        <ul className="space-y-2">
                            {reviews.recent.map((review) => (
                                <li
                                    key={review.id}
                                    className="rounded-xl bg-muted/40 px-3 py-2.5"
                                >
                                    <p
                                        className="text-sm leading-relaxed"
                                        dir="auto"
                                    >
                                        {review.text}
                                    </p>
                                    <div className="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs">
                                        {review.sentiment ? (
                                            <span
                                                className="rounded-full px-2 py-0.5 font-medium"
                                                style={{
                                                    color: sentimentColor[
                                                        review.sentiment
                                                    ],
                                                    backgroundColor: `color-mix(in oklch, ${sentimentColor[review.sentiment]} 12%, transparent)`,
                                                }}
                                            >
                                                {
                                                    t.market.sentiment[
                                                        review.sentiment
                                                    ]
                                                }
                                            </span>
                                        ) : (
                                            <span className="rounded-full bg-muted px-2 py-0.5 text-muted-foreground">
                                                {t.common.decision.pending}
                                            </span>
                                        )}
                                        {review.topics.map((topic) => (
                                            <span
                                                key={topic}
                                                className="text-muted-foreground"
                                            >
                                                #
                                                {t.market.topics[
                                                    topic as keyof typeof t.market.topics
                                                ] ?? topic}
                                            </span>
                                        ))}
                                        <span className="ms-auto text-muted-foreground">
                                            {t.market.sources[
                                                review.source as
                                                    | 'store'
                                                    | 'competitor'
                                            ] ?? review.source}
                                        </span>
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

function TopicList({
    title,
    topics,
    tone,
}: {
    title: string;
    topics: Record<string, number>;
    tone: 'success' | 'danger';
}) {
    const { t } = useI18n();
    const entries = Object.entries(topics);
    const max = Math.max(1, ...entries.map(([, v]) => v));

    return (
        <div className="space-y-2">
            <h3 className="text-xs font-medium text-muted-foreground">
                {title}
            </h3>
            {entries.length === 0 ? (
                <p className="text-xs text-muted-foreground">—</p>
            ) : (
                <ul className="space-y-1.5">
                    {entries.map(([topic, count], i) => (
                        <li
                            key={topic}
                            className="flex items-center gap-3 text-sm"
                        >
                            <span className="w-24 shrink-0 truncate">
                                {t.market.topics[
                                    topic as keyof typeof t.market.topics
                                ] ?? topic}
                            </span>
                            <div className="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                                <motion.div
                                    className={cn(
                                        'h-full rounded-full',
                                        tone === 'success'
                                            ? 'bg-success'
                                            : 'bg-danger',
                                    )}
                                    initial={{ width: 0 }}
                                    animate={{
                                        width: `${(count / max) * 100}%`,
                                    }}
                                    transition={{
                                        duration: 0.8,
                                        delay: i * 0.06,
                                    }}
                                />
                            </div>
                            <span className="num w-6 text-end text-xs text-muted-foreground">
                                {count}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

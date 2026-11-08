import {
    ArrowUpRight,
    CalendarClock,
    CheckCircle2,
    Eye,
    Hash,
    Heart,
    ImageOff,
    Info,
    Layers,
    MessageCircle,
    Play,
    Share2,
    Sparkles,
    TrendingUp,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/mojo/empty-state';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';
import type { SocialAnalysisData, SocialPost } from './competitor-types';

function PostImage({
    post,
    className,
}: {
    post: SocialPost;
    className?: string;
}) {
    const { t } = useI18n();
    const [failed, setFailed] = useState(false);
    const w = t.market.competitors.workspace;

    return (
        <div className={cn('relative overflow-hidden bg-muted', className)}>
            {post.thumbnail_url && !failed ? (
                <img
                    src={post.thumbnail_url}
                    alt={post.caption?.slice(0, 120) ?? w.postImage}
                    loading="lazy"
                    referrerPolicy="no-referrer"
                    onError={() => setFailed(true)}
                    className="size-full object-cover"
                />
            ) : (
                <div className="flex size-full items-center justify-center text-muted-foreground/60">
                    <ImageOff className="size-6" aria-label={w.noPostImage} />
                </div>
            )}
            {post.type === 'video' && (
                <span className="absolute start-2 top-2 flex size-7 items-center justify-center rounded-full bg-black/55 text-white">
                    <Play className="size-3.5 fill-current" />
                </span>
            )}
            {post.type === 'carousel' && (
                <span className="absolute start-2 top-2 flex size-7 items-center justify-center rounded-full bg-black/55 text-white">
                    <Layers className="size-3.5" />
                </span>
            )}
        </div>
    );
}

function Engagement({ post }: { post: SocialPost }) {
    const { t, number } = useI18n();
    const w = t.market.competitors.workspace;

    return post.engagement_rate === null ? (
        <span title={w.engagementUnknown}>— {w.postEngagement}</span>
    ) : (
        <span
            title={
                post.engagement_basis === 'followers'
                    ? w.basisFollowers
                    : w.basisViews
            }
            className="font-medium text-foreground"
        >
            {number(post.engagement_rate, 1)}% {w.postEngagement}
        </span>
    );
}

export function SocialPostCard({ post }: { post: SocialPost }) {
    const { t, compact, relative, dateTime } = useI18n();
    const w = t.market.competitors.workspace;
    const stats = [
        {
            icon: Eye,
            value: post.views,
            label: t.market.views,
            show: post.views > 0,
        },
        {
            icon: Heart,
            value: post.likes,
            label: t.market.likes,
            show: !post.likes_hidden,
        },
        {
            icon: MessageCircle,
            value: post.comments,
            label: t.market.comments,
            show: true,
        },
        {
            icon: Share2,
            value: post.shares,
            label: t.market.shares,
            show: post.shares > 0,
        },
    ];

    return (
        <article className="flex flex-col overflow-hidden rounded-2xl border bg-card">
            <PostImage post={post} className="aspect-[4/5] w-full" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <p className="line-clamp-4 flex-1 text-sm leading-7" dir="auto">
                    {post.caption ?? w.noCaption}
                </p>
                <div className="num flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-muted-foreground">
                    {stats
                        .filter((s) => s.show)
                        .map(({ icon: Icon, value, label }) => (
                            <span
                                key={label}
                                className="flex items-center gap-1"
                                title={label}
                            >
                                <Icon className="size-4" />
                                {compact(value)}
                            </span>
                        ))}
                    {post.likes_hidden && <span>{w.likesHidden}</span>}
                </div>
                <div className="flex items-center justify-between gap-3 border-t pt-3 text-xs text-muted-foreground">
                    <Engagement post={post} />
                    <span className="flex items-center gap-3">
                        {post.posted_at && (
                            <time
                                dateTime={post.posted_at}
                                title={dateTime(post.posted_at)}
                            >
                                {relative(post.posted_at)}
                            </time>
                        )}
                        {post.url && (
                            <a
                                href={post.url}
                                target="_blank"
                                rel="noreferrer"
                                className="inline-flex items-center gap-1 text-primary hover:underline"
                            >
                                {t.common.open}
                                <ArrowUpRight className="size-4 rtl:-scale-x-100" />
                            </a>
                        )}
                    </span>
                </div>
            </div>
        </article>
    );
}

export function SocialPostGrid({ posts }: { posts: SocialPost[] }) {
    const { t } = useI18n();
    const w = t.market.competitors.workspace;
    const [order, setOrder] = useState<'latest' | 'engagement'>('latest');
    const sorted = useMemo(
        () =>
            [...posts].sort((a, b) =>
                order === 'engagement'
                    ? (b.engagement_rate ?? -1) - (a.engagement_rate ?? -1) ||
                      b.likes +
                          b.comments +
                          b.shares -
                          (a.likes + a.comments + a.shares)
                    : Date.parse(b.posted_at ?? '0') -
                      Date.parse(a.posted_at ?? '0'),
            ),
        [posts, order],
    );

    if (!posts.length) {
        return (
            <EmptyState
                icon={MessageCircle}
                title={w.noPosts}
                description={w.noPostsHint}
            />
        );
    }

    return (
        <div className="space-y-4">
            <div
                className="flex flex-wrap items-center gap-2"
                role="group"
                aria-label={w.sortPosts}
            >
                {(['latest', 'engagement'] as const).map((value) => (
                    <Button
                        key={value}
                        size="sm"
                        variant={order === value ? 'soft' : 'ghost'}
                        aria-pressed={order === value}
                        onClick={() => setOrder(value)}
                    >
                        {value === 'latest' ? w.latestPosts : w.topPosts}
                    </Button>
                ))}
            </div>
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                {sorted.map((post) => (
                    <SocialPostCard key={post.id} post={post} />
                ))}
            </div>
        </div>
    );
}

export function CompetitorSocialOverview({
    analysis,
    posts,
    onPosts,
}: {
    analysis: SocialAnalysisData;
    posts: SocialPost[];
    onPosts: () => void;
}) {
    const { t, number, compact, date, dateTime, tr } = useI18n();
    const c = t.market.competitors;
    const w = c.workspace;
    const { facts, ai, crawl } = analysis;
    const byId = new Map(posts.map((p) => [p.id, p]));
    const top = facts.top_post_ids
        .map((id) => byId.get(id))
        .filter((p): p is SocialPost => !!p);
    const hashtags = Object.entries(facts.hashtags).slice(0, 8);
    const formats = Object.entries(facts.formats);
    const basis = facts.engagement_basis.includes('followers')
        ? w.basisFollowers
        : w.basisViews;
    const metrics = [
        {
            label: w.postsRead,
            value: number(facts.posts),
            hint: tr(w.since, { date: date(crawl.since) }),
            icon: MessageCircle,
            tone: 'bg-info-soft/70 text-info',
        },
        {
            label: w.avgEngagement,
            value:
                facts.avg_engagement === null
                    ? '—'
                    : `${number(facts.avg_engagement, 1)}%`,
            hint: facts.avg_engagement === null ? w.engagementUnknown : basis,
            icon: TrendingUp,
            tone: 'bg-success-soft/70 text-success',
        },
        {
            label: w.perWeek,
            value: facts.per_week === null ? '—' : number(facts.per_week, 1),
            hint: w.perWeekHint,
            icon: CalendarClock,
            tone: 'bg-gold-soft/70 text-gold-foreground dark:text-gold',
        },
        {
            label: facts.followers ? w.followers : w.totalViews,
            value: compact(facts.followers ?? facts.views),
            hint: facts.followers ? w.followersHint : w.totalViewsHint,
            icon: Eye,
            tone: 'bg-muted text-muted-foreground',
        },
    ];

    return (
        <div className="space-y-7">
            <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
                {metrics.map(({ label, value, hint, icon: Icon, tone }) => (
                    <div
                        key={label}
                        className="rounded-2xl border border-border/70 bg-card p-4 sm:p-5"
                    >
                        <span
                            className={`mb-4 flex size-9 items-center justify-center rounded-xl ${tone}`}
                        >
                            <Icon className="size-4" />
                        </span>
                        <div className="num text-3xl font-semibold tracking-tight">
                            <bdi dir="ltr">{value}</bdi>
                        </div>
                        <div className="mt-1 text-sm font-medium">{label}</div>
                        <div className="mt-1 text-xs text-muted-foreground">
                            {hint}
                        </div>
                    </div>
                ))}
            </div>

            <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,1.65fr)_minmax(0,1fr)]">
                <section className="overflow-hidden rounded-2xl border border-primary/15 bg-card">
                    <div className="flex items-center gap-3 border-b border-primary/10 bg-gradient-to-l from-gold-soft/35 to-transparent p-5 sm:p-6">
                        <span className="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-primary/10 text-primary">
                            <Sparkles className="size-5" />
                        </span>
                        <div>
                            <h2 className="font-semibold">{w.aiTitle}</h2>
                            <p className="mt-1 text-xs text-muted-foreground">
                                {w.socialAiSubtitle}
                            </p>
                        </div>
                        {ai.status === 'completed' && (
                            <CheckCircle2
                                className="ms-auto size-5 shrink-0 text-success"
                                aria-label={c.geminiAnalysis}
                            />
                        )}
                    </div>
                    <div className="space-y-5 p-5 sm:p-6">
                        {ai.status === 'completed' ? (
                            <>
                                {ai.summary && (
                                    <p className="text-sm leading-8">
                                        {ai.summary}
                                    </p>
                                )}
                                {ai.insights.map((insight, i) => (
                                    <div
                                        key={i}
                                        className="flex gap-3 border-t border-border/60 pt-4"
                                    >
                                        <span className="num mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">
                                            {number(i + 1)}
                                        </span>
                                        <div className="min-w-0 space-y-3">
                                            <h3 className="text-sm font-semibold">
                                                {insight.title}
                                            </h3>
                                            <p className="text-sm leading-7 text-muted-foreground">
                                                {insight.description}
                                            </p>
                                            <div className="flex flex-wrap gap-2">
                                                {insight.post_ids.map((id) => {
                                                    const post = byId.get(id);
                                                    return post ? (
                                                        <a
                                                            key={id}
                                                            href={
                                                                post.url ??
                                                                undefined
                                                            }
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            title={
                                                                post.caption ??
                                                                undefined
                                                            }
                                                            className="block size-14 overflow-hidden rounded-lg border hover:border-primary"
                                                        >
                                                            <PostImage
                                                                post={post}
                                                                className="size-full"
                                                            />
                                                        </a>
                                                    ) : null;
                                                })}
                                            </div>
                                        </div>
                                    </div>
                                ))}
                                <p className="border-t border-border/60 pt-4 text-xs leading-relaxed text-muted-foreground">
                                    {c.socialHypotheses}
                                </p>
                            </>
                        ) : (
                            <>
                                <div>
                                    <h3 className="text-sm font-medium">
                                        {ai.status === 'processing'
                                            ? c.scanProgress.socialAiProcessing
                                            : w.aiWaiting}
                                    </h3>
                                    <p className="mt-2 text-sm leading-7 text-muted-foreground">
                                        {w.socialAiWaitingHint}
                                    </p>
                                </div>
                                {ai.status !== 'processing' && (
                                    <p className="text-xs leading-relaxed text-muted-foreground">
                                        {ai.status === 'not_configured'
                                            ? c.socialAiNotConfigured
                                            : c.aiUnavailable}
                                    </p>
                                )}
                            </>
                        )}
                        <Button variant="soft" onClick={onPosts}>
                            {w.browsePosts}
                            <ArrowUpRight className="size-4 rtl:-scale-x-100" />
                        </Button>
                    </div>
                </section>

                <aside className="space-y-5">
                    <section className="rounded-2xl border border-border/70 bg-card p-5 sm:p-6">
                        <h2 className="flex items-center gap-2 font-semibold">
                            <Hash className="size-4 text-muted-foreground" />
                            {w.hashtags}
                        </h2>
                        {hashtags.length ? (
                            <div className="mt-4 flex flex-wrap gap-2">
                                {hashtags.map(([tag, count]) => (
                                    <span
                                        key={tag}
                                        className="rounded-md bg-muted/60 px-2 py-1 text-xs"
                                        dir="auto"
                                    >
                                        #{tag}{' '}
                                        <span className="num text-muted-foreground">
                                            {number(count)}
                                        </span>
                                    </span>
                                ))}
                            </div>
                        ) : (
                            <p className="mt-3 text-sm text-muted-foreground">
                                {t.common.noData}
                            </p>
                        )}
                        {formats.length > 0 && (
                            <>
                                <h3 className="mt-6 text-sm font-semibold">
                                    {w.formats}
                                </h3>
                                <div className="mt-3 space-y-3">
                                    {formats.map(([format, count]) => (
                                        <div key={format}>
                                            <div className="mb-1.5 flex justify-between text-xs">
                                                <span>
                                                    {w.postTypes[
                                                        format as keyof typeof w.postTypes
                                                    ] ?? format}
                                                </span>
                                                <span className="num text-muted-foreground">
                                                    {number(count)}
                                                </span>
                                            </div>
                                            <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                                                <div
                                                    className="h-full rounded-full bg-primary/60"
                                                    style={{
                                                        width: `${Math.min(100, (count / Math.max(facts.posts, 1)) * 100)}%`,
                                                    }}
                                                />
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </>
                        )}
                    </section>
                    <section className="space-y-3 rounded-2xl border bg-card p-5 text-xs leading-relaxed text-muted-foreground">
                        <h2 className="flex items-center gap-2 text-sm font-semibold text-foreground">
                            <Info className="size-4 text-info" />
                            {w.scopeTitle}
                        </h2>
                        <p>{c.socialEvidenceNote}</p>
                        {crawl.limited && (
                            <p>
                                {tr(c.socialSampleNote, {
                                    count: number(facts.posts),
                                })}
                            </p>
                        )}
                        {crawl.warnings.length > 0 && (
                            <p className="rounded-xl bg-warning-soft/50 p-3 text-warning">
                                {crawl.warnings
                                    .map(
                                        (x) =>
                                            c.socialWarnings[
                                                x.code as keyof typeof c.socialWarnings
                                            ] ?? x.message,
                                    )
                                    .join(' ')}
                            </p>
                        )}
                        <div className="border-t pt-3">
                            {w.recordedAt}
                            <div className="mt-1 font-medium text-foreground">
                                {dateTime(crawl.observed_at)}
                            </div>
                        </div>
                    </section>
                </aside>
            </div>

            {top.length > 0 && (
                <section className="space-y-4">
                    <div>
                        <h2 className="text-lg font-semibold">{w.topPosts}</h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {w.topPostsHint}
                        </p>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {top.map((post) => (
                            <SocialPostCard key={post.id} post={post} />
                        ))}
                    </div>
                </section>
            )}
        </div>
    );
}

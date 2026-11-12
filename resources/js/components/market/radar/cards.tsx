import { Link, router } from '@inertiajs/react';
import {
    Bookmark,
    BookmarkCheck,
    ChevronDown,
    ExternalLink,
    Eye,
    Flame,
    Heart,
    Images,
    MessageCircle,
    PlayCircle,
    WandSparkles,
} from 'lucide-react';
import { AnimatePresence, motion } from 'motion/react';
import { useState } from 'react';
import { PlatformBadge } from '@/components/market/platform-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';
import { LiftPill, PostTagList, Visual } from './parts';
import { designHref } from './types';
import type { PostReading, RadarPost, Recommendation } from './types';

/**
 * A competitor post that beat its account's usual: the image, the lift,
 * what it shows and how it is pitched, its numbers, and — once read by AI —
 * what product it is and why it likely engaged.
 */
export function TrendingPostCard({
    post,
    rank,
    reading,
}: {
    post: RadarPost;
    rank: number;
    reading?: PostReading;
}) {
    const { t, compact, relative, money } = useI18n();
    const r = t.radar;
    const [open, setOpen] = useState(false);
    const FormatIcon =
        post.tags.format === 'video'
            ? PlayCircle
            : post.tags.format === 'carousel'
              ? Images
              : null;

    return (
        <article
            id={`radar-post-${post.id}`}
            className="group flex h-full flex-col overflow-hidden rounded-2xl border border-border/70 bg-card shadow-[0_1px_2px_rgba(16,24,40,0.04)] transition-shadow hover:shadow-md"
        >
            <div className="relative">
                <a
                    href={post.url ?? undefined}
                    target="_blank"
                    rel="noreferrer"
                    className="block"
                >
                    <Visual
                        src={post.image_url}
                        garment={post.tags.garment}
                        alt={post.caption}
                        className="aspect-[4/5]"
                    />
                </a>
                <span className="pointer-events-none absolute start-3 top-2 font-display text-2xl font-semibold text-white drop-shadow-[0_1px_4px_rgba(0,0,0,0.5)]">
                    {String(rank).padStart(2, '0')}
                </span>
                <span className="absolute end-2.5 top-2.5">
                    <PlatformBadge platform={post.platform} withLabel={false} />
                </span>
                {FormatIcon && (
                    <span className="absolute start-2.5 bottom-5 flex size-7 items-center justify-center rounded-full bg-black/55 text-white">
                        <FormatIcon className="size-4" />
                    </span>
                )}
            </div>
            <div className="-mt-3.5 flex items-center gap-1.5 px-3">
                <LiftPill post={post} />
                {post.tier && post.tier !== 'above' && (
                    <Badge
                        variant={
                            post.tier === 'exceptional' ? 'gold' : 'neutral'
                        }
                        className="mt-3 text-[10px]"
                    >
                        {post.tier === 'exceptional' && <Flame />}
                        {r.posts.tiers[post.tier]}
                    </Badge>
                )}
                {post.early && (
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <span className="mt-3">
                                <Badge variant="info" className="text-[10px]">
                                    {r.posts.early}
                                </Badge>
                            </span>
                        </TooltipTrigger>
                        <TooltipContent>{r.posts.earlyTip}</TooltipContent>
                    </Tooltip>
                )}
            </div>
            <div className="flex flex-1 flex-col gap-2.5 p-4 pt-2.5">
                <div className="flex items-center justify-between gap-2 text-xs text-muted-foreground">
                    <Link
                        href={`/store/competitors?competitor=${post.competitor_id}`}
                        className="truncate font-medium text-foreground/80 hover:underline"
                    >
                        {post.competitor}
                    </Link>
                    <span className="shrink-0" suppressHydrationWarning>
                        {relative(post.posted_at)}
                    </span>
                </div>
                {reading?.product ? (
                    <p className="font-display text-[15px] leading-snug font-semibold">
                        {reading.product}
                    </p>
                ) : (
                    post.caption && (
                        <p
                            dir="auto"
                            className="line-clamp-2 text-xs leading-relaxed text-foreground/80"
                        >
                            {post.caption}
                        </p>
                    )
                )}
                {post.product && (
                    <a
                        href={post.product.url ?? undefined}
                        target="_blank"
                        rel="noreferrer"
                        className="flex items-center gap-2.5 rounded-xl border border-gold/30 bg-gold-soft/40 p-2 transition-colors hover:bg-gold-soft/70"
                    >
                        <Visual
                            src={post.product.image_url}
                            garment={post.tags.garment}
                            className="size-11 shrink-0 rounded-lg"
                        />
                        <span className="min-w-0 flex-1">
                            <span className="block text-[10px] text-muted-foreground">
                                {r.posts.inStore}
                            </span>
                            <span className="block truncate text-xs font-semibold">
                                {post.product.name}
                            </span>
                            <span className="flex flex-wrap items-center gap-x-2 text-[10px] text-muted-foreground">
                                {post.product.price !== null && (
                                    <span className="num">
                                        {money(post.product.price)}
                                    </span>
                                )}
                                {post.product.published_sales && (
                                    <span>{post.product.published_sales}</span>
                                )}
                                {post.product.best_seller && (
                                    <span className="font-medium text-gold-foreground dark:text-gold">
                                        {r.signals.best_seller_label}
                                    </span>
                                )}
                            </span>
                        </span>
                    </a>
                )}
                <PostTagList tags={post.tags} />
                {post.tags.flags.length > 0 && (
                    <div className="flex flex-wrap gap-1">
                        {post.tags.flags.map((f) => (
                            <Badge
                                key={f}
                                variant="warning"
                                className="text-[10px]"
                            >
                                {r.tags.flags[f]}
                            </Badge>
                        ))}
                    </div>
                )}
                <div className="mt-auto flex items-center gap-3 pt-1 text-[11px] text-muted-foreground">
                    {post.views > 0 && (
                        <span className="inline-flex items-center gap-1">
                            <Eye className="size-3.5" />
                            <span className="num">{compact(post.views)}</span>
                        </span>
                    )}
                    {!post.tags.flags.includes('comments_only') && (
                        <span className="inline-flex items-center gap-1">
                            <Heart className="size-3.5" />
                            <span className="num">{compact(post.likes)}</span>
                        </span>
                    )}
                    <span className="inline-flex items-center gap-1">
                        <MessageCircle className="size-3.5" />
                        <span className="num">{compact(post.comments)}</span>
                    </span>
                </div>
                {reading && (reading.why || reading.design_takeaway) && (
                    <div className="rounded-xl bg-gold-soft/50">
                        <button
                            type="button"
                            onClick={() => setOpen((v) => !v)}
                            className="flex w-full cursor-pointer items-center justify-between px-3 py-2 text-xs font-medium"
                        >
                            {r.whyItWorked}
                            <ChevronDown
                                className={cn(
                                    'size-3.5 transition-transform',
                                    open && 'rotate-180',
                                )}
                            />
                        </button>
                        <AnimatePresence initial={false}>
                            {open && (
                                <motion.div
                                    initial={{ height: 0, opacity: 0 }}
                                    animate={{ height: 'auto', opacity: 1 }}
                                    exit={{ height: 0, opacity: 0 }}
                                    className="overflow-hidden"
                                >
                                    <div className="space-y-1.5 px-3 pb-3 text-xs leading-relaxed">
                                        {reading.why && <p>{reading.why}</p>}
                                        {reading.design_takeaway && (
                                            <p>
                                                <span className="text-muted-foreground">
                                                    {r.reading.designTakeaway}
                                                    :{' '}
                                                </span>
                                                {reading.design_takeaway}
                                            </p>
                                        )}
                                        {reading.content_takeaway && (
                                            <p>
                                                <span className="text-muted-foreground">
                                                    {r.reading.contentTakeaway}
                                                    :{' '}
                                                </span>
                                                {reading.content_takeaway}
                                            </p>
                                        )}
                                    </div>
                                </motion.div>
                            )}
                        </AnimatePresence>
                    </div>
                )}
                <div className="flex gap-1.5 pt-1">
                    <Button
                        size="sm"
                        variant="soft"
                        className="h-8 flex-1 text-xs"
                        asChild
                    >
                        <Link href={designHref(`post:${post.id}`)}>
                            <WandSparkles className="size-3.5" />
                            {r.designLike}
                        </Link>
                    </Button>
                    {post.url && (
                        <Button
                            size="icon"
                            variant="ghost"
                            className="size-8"
                            asChild
                        >
                            <a
                                href={post.url}
                                target="_blank"
                                rel="noreferrer"
                                aria-label={r.openPost}
                            >
                                <ExternalLink className="size-3.5" />
                            </a>
                        </Button>
                    )}
                </div>
            </div>
        </article>
    );
}

/**
 * A competitor design worth adding: why (its signals), whether the store
 * already has something like it, and where its price sits against the
 * store's own band.
 */
export function RecommendCard({
    rec,
    rank,
}: {
    rec: Recommendation;
    rank: number;
}) {
    const { t, tr, money, number } = useI18n();
    const r = t.radar;
    const verbTone = {
        add_now: 'bg-gold text-gold-foreground',
        price_fit: 'bg-navy text-white dark:text-background',
        test_small: 'bg-info-soft text-info',
        restock: 'bg-success-soft text-success',
        watch: 'bg-muted text-muted-foreground',
    }[rec.verb];
    const sar =
        rec.currency === 'SAR' ||
        (rec.currency === null &&
            ['salla', 'zid'].includes(rec.platform ?? ''));
    const price = (n: number | null) =>
        n === null
            ? '—'
            : sar
              ? money(n)
              : `${number(n)} ${rec.currency ?? ''}`;
    const watch = () =>
        router.post(
            `/store/competitors/products/${rec.id}/watch`,
            {},
            { preserveScroll: true, only: ['radar'] },
        );

    return (
        <article
            id={`radar-${rec.kind}-${rec.id}`}
            className="group flex h-full flex-col overflow-hidden rounded-2xl border border-border/70 bg-card shadow-[0_1px_2px_rgba(16,24,40,0.04)] transition-shadow hover:shadow-md"
        >
            <div className="relative">
                <Visual
                    src={rec.image_url}
                    garment={rec.garment}
                    alt={rec.name}
                    className="aspect-[4/5]"
                />
                <span className="pointer-events-none absolute start-3 top-2 font-display text-2xl font-semibold text-white drop-shadow-[0_1px_4px_rgba(0,0,0,0.5)]">
                    {String(rank).padStart(2, '0')}
                </span>
                {rec.platform && (
                    <span className="absolute end-2.5 top-2.5">
                        <PlatformBadge
                            platform={rec.platform}
                            withLabel={false}
                        />
                    </span>
                )}
                <span
                    className={cn(
                        'absolute start-2.5 bottom-2.5 rounded-full px-2.5 py-1 text-[11px] font-semibold shadow-sm',
                        verbTone,
                    )}
                >
                    {r.recs.verbs[rec.verb]}
                </span>
            </div>
            <div className="flex flex-1 flex-col gap-2.5 p-4">
                <div>
                    <p className="text-xs text-muted-foreground">
                        {rec.competitor}
                    </p>
                    <h3 className="line-clamp-2 font-display text-[15px] leading-snug font-semibold">
                        {rec.name}
                    </h3>
                </div>
                <div className="flex flex-wrap gap-1">
                    {rec.signals.map((s) =>
                        s === 'best_seller_label' ? (
                            <Tooltip key={s}>
                                <TooltipTrigger asChild>
                                    <span>
                                        <Badge
                                            variant="gold"
                                            className="text-[10px]"
                                        >
                                            {r.signals[s]}
                                        </Badge>
                                    </span>
                                </TooltipTrigger>
                                <TooltipContent>{r.claim}</TooltipContent>
                            </Tooltip>
                        ) : (
                            <Badge
                                key={s}
                                variant={
                                    s === 'price_drop' ? 'warning' : 'neutral'
                                }
                                className="text-[10px]"
                            >
                                {r.signals[s as keyof typeof r.signals] ?? s}
                                {s === 'price_drop' && rec.drop_pct
                                    ? ` ${rec.drop_pct}%`
                                    : ''}
                            </Badge>
                        ),
                    )}
                    {rec.brands >= 2 && (
                        <Badge variant="gold" className="text-[10px]">
                            <Flame />
                            {tr(r.recs.brands, { n: rec.brands })}
                        </Badge>
                    )}
                    {rec.social_lift && (
                        <Badge variant="neutral" className="text-[10px]">
                            {tr(r.recs.socialLift, { x: rec.social_lift })}
                        </Badge>
                    )}
                </div>

                <div
                    className={cn(
                        'flex items-center gap-2 rounded-xl px-2.5 py-2 text-xs',
                        rec.novelty === 'none'
                            ? 'bg-gold-soft/60'
                            : 'bg-muted/60',
                    )}
                >
                    {rec.similar?.image_url && (
                        <img
                            src={rec.similar.image_url}
                            alt=""
                            className="size-8 shrink-0 rounded-md object-cover"
                        />
                    )}
                    <div className="min-w-0">
                        <p className="font-medium">
                            {r.recs.novelty[rec.novelty]}
                        </p>
                        {rec.similar && (
                            <Link
                                href={`/store/products/${rec.similar.id}`}
                                className="block truncate text-[11px] text-muted-foreground hover:underline"
                            >
                                {rec.similar.name}
                                {rec.similar.differs &&
                                    ` · ${r.recs.differs[rec.similar.differs]}`}
                            </Link>
                        )}
                        {rec.new_garment && (
                            <p className="text-[11px] text-muted-foreground">
                                {r.recs.newGarment}
                            </p>
                        )}
                    </div>
                </div>

                {rec.price !== null && (
                    <div className="space-y-1.5">
                        <div className="flex items-baseline justify-between gap-2 text-xs">
                            <span className="num font-semibold">
                                {price(rec.price)}
                                {rec.previous_price &&
                                    rec.previous_price > rec.price && (
                                        <s className="ms-1.5 font-normal text-muted-foreground">
                                            {price(rec.previous_price)}
                                        </s>
                                    )}
                            </span>
                            {rec.position.status && (
                                <span
                                    className={cn(
                                        'text-[11px]',
                                        rec.position.status === 'within'
                                            ? 'text-success'
                                            : 'text-warning',
                                    )}
                                >
                                    {tr(r.recs.price[rec.position.status], {
                                        p: rec.position.pct ?? 0,
                                    })}
                                </span>
                            )}
                        </div>
                        {rec.band && sar && (
                            <PriceBand band={rec.band} price={rec.price} />
                        )}
                    </div>
                )}

                <div className="mt-auto flex gap-1.5 pt-1">
                    <Button
                        size="sm"
                        variant="gold"
                        className="h-8 flex-1 text-xs"
                        asChild
                    >
                        <Link
                            href={designHref(
                                `${rec.kind === 'post' ? 'post' : 'product'}:${rec.id}`,
                            )}
                        >
                            <WandSparkles className="size-3.5" />
                            {r.designLike}
                        </Link>
                    </Button>
                    {rec.kind === 'product' && (
                        <Button
                            size="icon"
                            variant="ghost"
                            className={cn(
                                'size-8',
                                rec.watched &&
                                    'text-gold-foreground dark:text-gold',
                            )}
                            onClick={watch}
                            aria-label={
                                rec.watched ? r.recs.watching : r.recs.watch
                            }
                            title={rec.watched ? r.recs.watching : r.recs.watch}
                        >
                            {rec.watched ? (
                                <BookmarkCheck className="size-4" />
                            ) : (
                                <Bookmark className="size-4" />
                            )}
                        </Button>
                    )}
                    {rec.url && (
                        <Button
                            size="icon"
                            variant="ghost"
                            className="size-8"
                            asChild
                        >
                            <a
                                href={rec.url}
                                target="_blank"
                                rel="noreferrer"
                                aria-label={r.openProduct}
                            >
                                <ExternalLink className="size-3.5" />
                            </a>
                        </Button>
                    )}
                </div>
            </div>
        </article>
    );
}

/** The store's own p25–p75 band (navy) with the competitor's price as a gold dot. */
export function PriceBand({
    band,
    price,
}: {
    band: { min: number; max: number };
    price: number;
}) {
    const { t, tr, number } = useI18n();
    const lo = Math.min(band.min, price) * 0.85;
    const hi = Math.max(band.max, price) * 1.15;
    const at = (v: number) => `${((v - lo) / (hi - lo)) * 100}%`;

    return (
        <div className="space-y-1">
            <div className="relative h-2 rounded-full bg-muted" dir="ltr">
                <div
                    className="absolute inset-y-0 rounded-full bg-navy/70"
                    style={{
                        left: at(band.min),
                        width: `calc(${at(band.max)} - ${at(band.min)})`,
                    }}
                />
                <span
                    className="absolute top-1/2 size-3 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-card bg-gold shadow"
                    style={{ left: at(price) }}
                />
            </div>
            <p className="num text-[10px] text-muted-foreground">
                {tr(t.radar.recs.band, {
                    min: number(band.min),
                    max: number(band.max),
                })}
            </p>
        </div>
    );
}

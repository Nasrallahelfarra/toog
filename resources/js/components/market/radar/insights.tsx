import { Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    Flame,
    Info,
    Lightbulb,
    Loader2,
    RefreshCw,
    ScanEye,
    Sparkles,
    WandSparkles,
} from 'lucide-react';
import { motion } from 'motion/react';
import { useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';
import { focusItem, SectionHead, Visual } from './parts';
import { designHref } from './types';
import type {
    Advice,
    Driver,
    Idea,
    RadarAlert,
    RadarData,
    RadarPost,
    Reading,
} from './types';

/** The five-second answer: the idea, post or design that stands out most. */
export function TrendHero({ hero }: { hero: RadarData['hero'] }) {
    const { t, tr } = useI18n();
    const h = t.radar.hero;

    if (!hero) {
        return (
            <section className="relative overflow-hidden rounded-2xl border border-border/70 bg-card px-6 py-10 text-center">
                <div
                    className="bg-sadu pointer-events-none absolute inset-0 text-gold opacity-[0.05]"
                    aria-hidden
                />
                <Sparkles className="mx-auto size-8 text-gold" />
                <h2 className="mt-3 font-display text-xl font-semibold">
                    {h.empty}
                </h2>
                <p className="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
                    {h.emptyBody}
                </p>
            </section>
        );
    }

    const view =
        hero.kind === 'idea'
            ? {
                  images: hero.idea.images,
                  garment: hero.idea.garment,
                  title: hero.idea.label,
                  big: hero.idea.avg_lift ? `${hero.idea.avg_lift}×` : null,
                  line: [
                      hero.idea.cross_platform_only
                          ? h.ideaPlatforms
                          : tr(h.idea, { n: hero.idea.brands }),
                      hero.idea.posts
                          ? tr(h.posts, { n: hero.idea.posts })
                          : null,
                      hero.idea.products
                          ? tr(h.products, { n: hero.idea.products })
                          : null,
                  ]
                      .filter(Boolean)
                      .join(' · '),
                  inspire: hero.idea.inspire,
                  focus: hero.idea.post_ids[0]
                      ? `post:${hero.idea.post_ids[0]}`
                      : null,
              }
            : hero.kind === 'post'
              ? {
                    images: [hero.post.image_url].filter(Boolean) as string[],
                    garment: hero.post.tags.garment,
                    title: hero.post.caption || hero.post.competitor,
                    big: hero.post.lift ? `${hero.post.lift}×` : null,
                    line: `${hero.post.competitor} · ${tr(h.postUsual, { x: hero.post.lift ?? '' })}`,
                    inspire: `post:${hero.post.id}`,
                    focus: `post:${hero.post.id}`,
                }
              : {
                    images: [hero.recommendation.image_url].filter(
                        Boolean,
                    ) as string[],
                    garment: hero.recommendation.garment,
                    title: hero.recommendation.name,
                    big: null,
                    line: hero.recommendation.text,
                    inspire: `${hero.recommendation.kind === 'post' ? 'post' : 'product'}:${hero.recommendation.id}`,
                    focus: `${hero.recommendation.kind}:${hero.recommendation.id}`,
                };

    return (
        <motion.section
            initial={{ opacity: 0, y: 10 }}
            animate={{ opacity: 1, y: 0 }}
            className="relative overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm"
        >
            <div
                className="bg-sadu pointer-events-none absolute inset-0 text-gold opacity-[0.05]"
                aria-hidden
            />
            <div className="relative grid gap-5 p-5 sm:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] sm:p-6">
                <div className="grid grid-cols-3 grid-rows-2 gap-1.5">
                    <Visual
                        src={view.images[0] ?? null}
                        garment={view.garment}
                        className="col-span-2 row-span-2 aspect-[4/5] rounded-xl"
                    />
                    <Visual
                        src={view.images[1] ?? null}
                        garment={view.garment}
                        className="rounded-xl"
                    />
                    <Visual
                        src={view.images[2] ?? null}
                        garment={view.garment}
                        className="rounded-xl"
                    />
                </div>
                <div className="flex flex-col justify-center gap-3">
                    <p className="flex items-center gap-1.5 text-xs font-medium text-gold-foreground dark:text-gold">
                        <Flame className="size-3.5" />
                        {h.eyebrow}
                    </p>
                    <h2
                        dir="auto"
                        className="line-clamp-3 font-display text-2xl leading-snug font-semibold text-balance sm:text-[28px]"
                    >
                        {view.title}
                    </h2>
                    {view.big && (
                        <p className="font-display text-5xl font-semibold text-gold-foreground dark:text-gold">
                            <bdi dir="ltr" className="num">
                                {view.big}
                            </bdi>
                        </p>
                    )}
                    <p className="text-sm leading-relaxed text-muted-foreground">
                        {view.line}
                    </p>
                    <div className="flex flex-wrap gap-2 pt-1">
                        {view.inspire && (
                            <Button variant="gold" asChild>
                                <Link href={designHref(view.inspire)}>
                                    <WandSparkles className="size-4" />
                                    {t.radar.designLike}
                                </Link>
                            </Button>
                        )}
                        {view.focus && (
                            <Button
                                variant="outline"
                                onClick={() => focusItem(view.focus)}
                            >
                                {h.seePosts}
                            </Button>
                        )}
                    </div>
                </div>
            </div>
        </motion.section>
    );
}

type Dim = 'all' | 'product' | 'hook' | 'format';

/**
 * What lifts or weakens engagement, around the usual (1×) on a log scale,
 * so 0.5× and 2× sit the same distance from the centre. Lifts in gold,
 * weakeners in grey; never red or green.
 */
export function DriverBars({
    drivers,
    label,
}: {
    drivers: Driver[];
    label: (d: Driver) => string;
}) {
    const { t, tr } = useI18n();
    const d = t.radar.drivers;
    const [dim, setDim] = useState<Dim>('all');
    const shown = useMemo(() => {
        const of = (x: Driver) =>
            ['garment', 'color', 'pattern', 'occasion'].includes(x.dimension)
                ? 'product'
                : x.dimension;
        const list = drivers.filter((x) => dim === 'all' || of(x) === dim);

        return [
            ...list.filter((x) => x.effect > 1).slice(0, 5),
            ...list.filter((x) => x.effect < 1).slice(0, 3),
        ];
    }, [drivers, dim]);
    const max = Math.max(
        Math.log2(3),
        ...drivers.map((x) => Math.abs(Math.log2(x.effect))),
    );

    return (
        <section className="space-y-4 rounded-2xl border border-border/70 bg-card p-5 shadow-[0_1px_2px_rgba(16,24,40,0.04)]">
            <SectionHead
                title={d.title}
                hint={d.hint}
                action={
                    <div className="flex rounded-lg bg-muted p-0.5">
                        {(['all', 'product', 'hook', 'format'] as Dim[]).map(
                            (k) => (
                                <button
                                    key={k}
                                    type="button"
                                    onClick={() => setDim(k)}
                                    className={cn(
                                        'cursor-pointer rounded-md px-2.5 py-1 text-[11px] font-medium',
                                        dim === k
                                            ? 'bg-card shadow-sm'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {d.dimensions[k]}
                                </button>
                            ),
                        )}
                    </div>
                }
            />
            {shown.length === 0 ? (
                <p className="rounded-xl bg-muted/50 px-4 py-6 text-center text-xs leading-relaxed text-muted-foreground">
                    {d.empty}
                </p>
            ) : (
                <div className="space-y-3">
                    <div className="flex justify-between text-[10px] text-muted-foreground">
                        <span>{d.weakens}</span>
                        <span>{d.axis}</span>
                        <span>{d.lifts}</span>
                    </div>
                    {shown.map((x) => {
                        const width =
                            (Math.abs(Math.log2(x.effect)) / max) * 50;
                        const thin = x.posts < 8 || x.brands < 2;

                        return (
                            <div
                                key={`${x.dimension}:${x.key}`}
                                className={cn(
                                    'space-y-1',
                                    thin && 'opacity-70',
                                )}
                            >
                                <div className="flex items-baseline justify-between gap-2 text-xs">
                                    <span className="font-medium">
                                        {label(x)}
                                    </span>
                                    <span className="flex items-center gap-1.5">
                                        {x.conflicting && (
                                            <Badge
                                                variant="neutral"
                                                className="px-1.5 py-0 text-[10px]"
                                            >
                                                {d.conflicting}
                                            </Badge>
                                        )}
                                        {x.likely_format && (
                                            <Badge
                                                variant="neutral"
                                                className="px-1.5 py-0 text-[10px]"
                                            >
                                                {d.likelyFormat}
                                            </Badge>
                                        )}
                                        <bdi
                                            dir="ltr"
                                            className={cn(
                                                'num font-semibold',
                                                x.effect > 1
                                                    ? 'text-gold-foreground dark:text-gold'
                                                    : 'text-muted-foreground',
                                            )}
                                        >
                                            {x.effect}×
                                        </bdi>
                                    </span>
                                </div>
                                <div className="relative h-2.5 rounded-full bg-muted/60">
                                    <span className="absolute inset-y-0 start-1/2 w-px bg-border" />
                                    <motion.span
                                        initial={{ width: 0 }}
                                        animate={{ width: `${width}%` }}
                                        transition={{
                                            duration: 0.9,
                                            ease: [0.22, 1, 0.36, 1],
                                        }}
                                        className={cn(
                                            'absolute inset-y-0 rounded-full',
                                            x.effect > 1
                                                ? 'start-1/2 bg-gold'
                                                : 'end-1/2 bg-muted-foreground/40',
                                            thin && 'bg-dots',
                                        )}
                                    />
                                </div>
                                <p className="text-[10px] text-muted-foreground">
                                    {tr(x.effect > 1 ? d.meta : d.metaWeak, {
                                        p: x.posts,
                                        a: x.agree,
                                        n: x.accounts,
                                    })}
                                </p>
                            </div>
                        );
                    })}
                </div>
            )}
        </section>
    );
}

export function CrossIdeas({ ideas }: { ideas: Idea[] }) {
    const { t, tr } = useI18n();
    const i = t.radar.ideas;

    return (
        <section className="space-y-4 rounded-2xl border border-border/70 bg-card p-5 shadow-[0_1px_2px_rgba(16,24,40,0.04)]">
            <SectionHead title={i.title} />
            {ideas.length === 0 ? (
                <p className="rounded-xl bg-muted/50 px-4 py-6 text-center text-xs text-muted-foreground">
                    {i.empty}
                </p>
            ) : (
                <ol className="space-y-3">
                    {ideas.map((idea, n) => (
                        <li
                            key={idea.look}
                            className="flex items-center gap-3 rounded-xl border p-3"
                        >
                            <span className="font-display text-xl font-semibold text-gold-foreground dark:text-gold">
                                {String(n + 1).padStart(2, '0')}
                            </span>
                            <div className="flex -space-x-2 rtl:space-x-reverse">
                                {(idea.images.length ? idea.images : [null])
                                    .slice(0, 3)
                                    .map((src, k) => (
                                        <Visual
                                            key={k}
                                            src={src}
                                            garment={idea.garment}
                                            className="size-10 rounded-lg ring-2 ring-card"
                                        />
                                    ))}
                            </div>
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-medium">
                                    {idea.label}
                                </p>
                                <p className="text-[11px] text-muted-foreground">
                                    {idea.cross_platform_only
                                        ? i.crossPlatform
                                        : tr(i.meta, {
                                              b: idea.brands,
                                              p: idea.posts,
                                              q: idea.products,
                                          })}
                                    {idea.avg_lift
                                        ? ` · ${tr(i.avg, { x: idea.avg_lift })}`
                                        : ''}
                                    {idea.in_catalog === false
                                        ? ` · ${i.notInCatalog}`
                                        : ''}
                                </p>
                            </div>
                            {idea.inspire && (
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    className="size-8 shrink-0"
                                    asChild
                                >
                                    <Link
                                        href={designHref(idea.inspire)}
                                        aria-label={t.radar.designLike}
                                        title={t.radar.designLike}
                                    >
                                        <WandSparkles className="size-4" />
                                    </Link>
                                </Button>
                            )}
                        </li>
                    ))}
                </ol>
            )}
        </section>
    );
}

/** Has a post's engagement moved enough since it was read to refresh its analysis? */
function movedSince(
    then: number | null | undefined,
    now: number | null,
): boolean {
    if (!then || now === null) return true;
    const tier = (l: number) => (l >= 3 ? 3 : l >= 2 ? 2 : l >= 1.5 ? 1 : 0);

    return tier(then) !== tier(now) || Math.abs(now - then) / then >= 0.25;
}

/**
 * The AI reading: idle (explains and offers to read), reading, ready (a
 * summary and insights that cite the posts), stale, or failed.
 */
export function RadarReading({
    reading,
    posts,
    days,
    demo,
}: {
    reading: Reading;
    posts: RadarPost[];
    days: number;
    demo: boolean;
}) {
    const { t, tr, relative } = useI18n();
    const r = t.radar.reading;
    const [busy, setBusy] = useState(false);
    const byId = Object.fromEntries(posts.map((p) => [p.id, p]));
    // Each post's image is read once; after that only its analysis is
    // refreshed, when its engagement moved noticeably.
    const leading = posts.slice(0, 4);
    const ready = Boolean(reading?.from_model);
    const fresh = ready
        ? leading.filter((p) => !reading!.posts[String(p.id)]).length
        : leading.length;
    const moved = ready
        ? leading.filter((p) => {
              const read = reading!.posts[String(p.id)];

              return read && movedSince(read.lift_at_read, p.lift);
          }).length
        : 0;
    const sameSet =
        ready &&
        [...reading!.hit_ids].sort((a, b) => a - b).join() ===
            leading
                .map((p) => p.id)
                .sort((a, b) => a - b)
                .join();
    const upToDate = ready && fresh === 0 && moved === 0 && sameSet;
    const stale = ready && !upToDate;
    const label = !ready
        ? r.read
        : fresh > 0
          ? tr(r.readNew, { n: fresh })
          : upToDate
            ? r.upToDate
            : r.refreshChanged;
    const read = () =>
        router.post(
            '/store/competitors/reading',
            { days },
            {
                preserveScroll: true,
                only: ['reading', 'radar'],
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
            },
        );

    return (
        <section className="relative overflow-hidden rounded-2xl border border-border/70 bg-card p-5 shadow-[0_1px_2px_rgba(16,24,40,0.04)] sm:p-6">
            <SectionHead
                title={r.title}
                icon={ScanEye}
                action={
                    posts.length > 0 &&
                    !demo && (
                        <div className="flex flex-col items-end gap-1">
                            <Button
                                size="sm"
                                variant={upToDate ? 'outline' : 'gold'}
                                onClick={read}
                                disabled={busy || upToDate}
                            >
                                {busy ? (
                                    <Loader2 className="size-3.5 animate-spin" />
                                ) : upToDate ? (
                                    <CheckCircle2 className="size-3.5" />
                                ) : ready ? (
                                    <RefreshCw className="size-3.5" />
                                ) : (
                                    <Sparkles className="size-3.5" />
                                )}
                                {label}
                            </Button>
                            <span className="text-[10px] text-muted-foreground">
                                {upToDate
                                    ? r.upToDateHint
                                    : ready
                                      ? r.costNote
                                      : null}
                            </span>
                        </div>
                    )
                }
            />
            <div className="mt-4">
                {busy ? (
                    <div className="space-y-2">
                        <p className="flex items-center gap-2 text-xs text-muted-foreground">
                            <Loader2 className="size-3.5 animate-spin" />
                            {r.reading}
                        </p>
                        {[0, 1, 2].map((k) => (
                            <div
                                key={k}
                                className="shimmer h-12 rounded-xl bg-muted/60"
                            />
                        ))}
                    </div>
                ) : demo ? (
                    <p className="rounded-xl bg-muted/50 px-4 py-3 text-xs text-muted-foreground">
                        {r.demo}
                    </p>
                ) : !reading ? (
                    <p className="max-w-3xl text-sm leading-relaxed text-muted-foreground">
                        {r.idle}
                    </p>
                ) : !reading.from_model ? (
                    <p className="rounded-xl bg-warning-soft px-4 py-3 text-xs text-warning">
                        {r.failed}
                    </p>
                ) : (
                    <div className="space-y-4">
                        {stale && (
                            <p className="inline-flex items-center gap-1.5 rounded-full bg-warning-soft px-2.5 py-1 text-[11px] text-warning">
                                <Info className="size-3.5" />
                                {r.stale}
                            </p>
                        )}
                        {reading.images === 0 && (
                            <p className="text-[11px] text-muted-foreground">
                                {r.noImages}
                            </p>
                        )}
                        {reading.summary && (
                            <p className="border-s-2 border-gold ps-4 font-display text-lg leading-relaxed sm:text-xl">
                                {reading.summary}
                            </p>
                        )}
                        <ol className="grid gap-3 md:grid-cols-2">
                            {reading.insights.map((ins, n) => (
                                <li
                                    key={n}
                                    className="flex gap-3 rounded-xl border p-3.5"
                                >
                                    <span className="font-display text-lg font-semibold text-gold-foreground dark:text-gold">
                                        {String(n + 1).padStart(2, '0')}
                                    </span>
                                    <div className="min-w-0 flex-1 space-y-1.5">
                                        <div className="flex flex-wrap items-center gap-1.5">
                                            <p className="text-sm font-medium">
                                                {ins.title}
                                            </p>
                                            <Badge
                                                variant="neutral"
                                                className="px-1.5 py-0 text-[10px]"
                                            >
                                                {r.kinds[ins.kind]}
                                            </Badge>
                                            {ins.single && (
                                                <Badge
                                                    variant="warning"
                                                    className="px-1.5 py-0 text-[10px]"
                                                >
                                                    {r.single}
                                                </Badge>
                                            )}
                                        </div>
                                        <p className="text-xs leading-relaxed text-muted-foreground">
                                            {ins.body}
                                        </p>
                                        <div className="flex gap-1.5 pt-1">
                                            {ins.post_ids.map((id) => (
                                                <button
                                                    key={id}
                                                    type="button"
                                                    onClick={() =>
                                                        focusItem(`post:${id}`)
                                                    }
                                                    className="cursor-pointer"
                                                >
                                                    <Visual
                                                        src={
                                                            byId[id]
                                                                ?.image_url ??
                                                            null
                                                        }
                                                        garment={
                                                            byId[id]?.tags
                                                                .garment
                                                        }
                                                        className="size-12 rounded-lg ring-1 ring-border hover:ring-gold"
                                                    />
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                </li>
                            ))}
                        </ol>
                        <p
                            className="text-[10px] text-muted-foreground"
                            suppressHydrationWarning
                        >
                            {r.footer}
                            {reading.created_at &&
                                ` · ${relative(reading.created_at)}`}
                        </p>
                    </div>
                )}
            </div>
        </section>
    );
}

export function RadarAlerts({
    alerts,
    limit,
}: {
    alerts: RadarAlert[];
    limit?: number;
}) {
    const { t } = useI18n();
    const a = t.radar.alerts;
    const tone = {
        danger: 'bg-danger-soft text-danger',
        warning: 'bg-warning-soft text-warning',
        info: 'bg-info-soft text-info',
    } as const;
    const list = limit ? alerts.slice(0, limit) : alerts;

    return (
        <section className="space-y-3">
            <SectionHead title={a.title} icon={AlertTriangle} />
            {list.length === 0 ? (
                <p className="text-xs text-muted-foreground">{a.empty}</p>
            ) : (
                <ul className="space-y-2">
                    {list.map((x, i) => (
                        <li
                            key={i}
                            className="flex gap-2.5 rounded-xl border bg-card p-2.5"
                        >
                            <span
                                className={cn(
                                    'flex size-7 shrink-0 items-center justify-center rounded-lg',
                                    tone[x.severity],
                                )}
                            >
                                {x.type === 'exceptional_post' ? (
                                    <Flame className="size-3.5" />
                                ) : (
                                    <AlertTriangle className="size-3.5" />
                                )}
                            </span>
                            {x.image_url && (
                                <img
                                    src={x.image_url}
                                    alt=""
                                    className="size-10 shrink-0 rounded-md object-cover"
                                />
                            )}
                            <div className="min-w-0 flex-1 space-y-1">
                                <p className="text-xs leading-relaxed">
                                    {x.text}
                                </p>
                                <div className="flex gap-2 text-[11px]">
                                    {x.prefill && x.focus && (
                                        <Link
                                            href={designHref(x.focus)}
                                            className="font-medium text-primary hover:underline"
                                        >
                                            {t.radar.designLike}
                                        </Link>
                                    )}
                                    {x.focus && (
                                        <button
                                            type="button"
                                            onClick={() => focusItem(x.focus)}
                                            className="cursor-pointer text-muted-foreground hover:underline"
                                        >
                                            {a.open}
                                        </button>
                                    )}
                                    {!x.focus && x.href && (
                                        <Link
                                            href={x.href}
                                            className="text-muted-foreground hover:underline"
                                        >
                                            {a.open}
                                        </Link>
                                    )}
                                </div>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

export function RadarAdvice({ advice }: { advice: Advice[] }) {
    const { t } = useI18n();

    if (advice.length === 0) return null;

    return (
        <section className="space-y-3">
            <SectionHead title={t.radar.advice.title} icon={Lightbulb} />
            <ol className="space-y-2">
                {advice.map((x, i) => (
                    <li
                        key={i}
                        className="flex gap-3 rounded-xl border bg-card p-3"
                    >
                        <span className="font-display text-lg font-semibold text-gold-foreground dark:text-gold">
                            {String(i + 1).padStart(2, '0')}
                        </span>
                        <div className="min-w-0 flex-1 space-y-1">
                            <p className="font-display text-[15px] font-semibold">
                                {x.title}
                            </p>
                            <p className="text-xs leading-relaxed text-muted-foreground">
                                {x.body}
                            </p>
                            <div className="flex gap-2 pt-0.5 text-[11px]">
                                {x.prefill && x.focus && (
                                    <Link
                                        href={designHref(x.focus)}
                                        className="font-medium text-primary hover:underline"
                                    >
                                        {t.radar.designLike}
                                    </Link>
                                )}
                                {x.focus && (
                                    <button
                                        type="button"
                                        onClick={() => focusItem(x.focus)}
                                        className="cursor-pointer text-muted-foreground hover:underline"
                                    >
                                        {t.radar.alerts.open}
                                    </button>
                                )}
                                {x.href && (
                                    <Link
                                        href={x.href}
                                        className="text-muted-foreground hover:underline"
                                    >
                                        {t.radar.alerts.open}
                                    </Link>
                                )}
                            </div>
                        </div>
                    </li>
                ))}
            </ol>
        </section>
    );
}

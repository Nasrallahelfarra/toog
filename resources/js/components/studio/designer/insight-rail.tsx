import { Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowDownRight,
    ArrowUpRight,
    CalendarClock,
    ExternalLink,
    Flame,
    Info,
    Layers,
    Lightbulb,
    Loader2,
    PackageSearch,
    Sparkles,
    Swords,
    TrendingUp,
    WandSparkles,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import { Meter } from '@/components/mojo/meter';
import { Stagger, StaggerItem } from '@/components/mojo/motion';
import { Sparkline } from '@/components/mojo/stat-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';
import type { Briefing, Insights, Prefill } from './types';

type Tab = 'sales' | 'competitors' | 'gaps' | 'alerts';

/**
 * Store intelligence beside the brief: what sells for the store, what works
 * for competitors, the gaps between them, and the designer's alerts and
 * advice. Every item can fill the brief ("design this").
 */
export function InsightRail({
    insights,
    briefing,
    onDesign,
    className,
}: {
    insights: Insights | undefined;
    briefing: Briefing;
    onDesign: (prefill: Prefill) => void;
    className?: string;
}) {
    const { t } = useI18n();
    const r = t.designer.rail;
    const urgent =
        insights?.alerts.filter((a) => a.severity !== 'info').length ?? 0;
    const [tab, setTab] = useState<Tab>('sales');

    return (
        <aside
            className={cn(
                'flex flex-col overflow-hidden rounded-2xl border border-border/70 bg-card shadow-[0_1px_2px_rgba(16,24,40,0.04)]',
                className,
            )}
        >
            <header className="flex items-center gap-2 border-b px-4 pt-4 pb-3">
                <span className="h-3.5 w-0.5 rounded-full bg-navy" />
                <h2 className="text-[15px] font-semibold">{r.title}</h2>
            </header>
            {!insights ? (
                <div className="space-y-3 p-4">
                    <p className="flex items-center gap-2 text-xs text-muted-foreground">
                        <Loader2 className="size-3.5 animate-spin" />
                        {r.loading}
                    </p>
                    {[0, 1, 2, 3].map((i) => (
                        <div
                            key={i}
                            className="shimmer h-16 rounded-xl bg-muted/60"
                        />
                    ))}
                </div>
            ) : (
                <Tabs
                    value={tab}
                    onValueChange={(v) => setTab(v as Tab)}
                    className="gap-0"
                >
                    <TabsList className="mx-3 mt-3 grid h-auto w-auto grid-cols-4 p-1">
                        {(
                            ['sales', 'competitors', 'gaps', 'alerts'] as Tab[]
                        ).map((key) => (
                            <TabsTrigger
                                key={key}
                                value={key}
                                className="relative px-1 py-1.5 text-[11px]"
                            >
                                {r.tabs[key]}
                                {key === 'alerts' && urgent > 0 && (
                                    <span className="absolute end-0.5 -top-1 size-2 rounded-full bg-danger" />
                                )}
                            </TabsTrigger>
                        ))}
                    </TabsList>
                    <div className="p-4">
                        <TabsContent value="sales">
                            <SalesTab insights={insights} onDesign={onDesign} />
                        </TabsContent>
                        <TabsContent value="competitors">
                            <CompetitorsTab
                                insights={insights}
                                onDesign={onDesign}
                            />
                        </TabsContent>
                        <TabsContent value="gaps">
                            <GapsTab insights={insights} onDesign={onDesign} />
                        </TabsContent>
                        <TabsContent value="alerts">
                            <AlertsTab
                                insights={insights}
                                briefing={briefing}
                                onDesign={onDesign}
                            />
                        </TabsContent>
                    </div>
                </Tabs>
            )}
        </aside>
    );
}

function SalesTab({
    insights,
    onDesign,
}: {
    insights: Insights;
    onDesign: (p: Prefill) => void;
}) {
    const { t, tr, number } = useI18n();
    const r = t.designer.rail;

    if (insights.bestsellers.length === 0) {
        return (
            <Empty
                icon={PackageSearch}
                text={r.empty.sales}
                href="/store/settings/integration"
                action={r.connect}
            />
        );
    }

    return (
        <div className="space-y-5">
            {insights.dna.length > 0 && (
                <Section title={r.dna} hint={r.dnaHint} icon={Sparkles}>
                    <div className="flex flex-wrap gap-1.5">
                        {insights.dna.map((d) => (
                            <span
                                key={`${d.attribute}:${d.value}`}
                                className="inline-flex items-center gap-1.5 rounded-full bg-muted/70 py-1 ps-1.5 pe-2.5 text-[11px]"
                            >
                                {d.hex ? (
                                    <span
                                        className="size-3.5 rounded-full ring-1 ring-black/10"
                                        style={{ backgroundColor: d.hex }}
                                    />
                                ) : (
                                    <span className="size-1.5 rounded-full bg-gold" />
                                )}
                                <span className="font-medium">{d.label}</span>
                                <span className="num text-success">
                                    {tr(r.dnaIndex, { n: d.index })}
                                </span>
                            </span>
                        ))}
                    </div>
                </Section>
            )}

            <Stagger className="space-y-2">
                {insights.bestsellers.map((p, i) => (
                    <StaggerItem key={p.id}>
                        <Row
                            image={p.image_url}
                            rank={i + 1}
                            title={p.name}
                            href={`/store/products/${p.id}`}
                            meta={
                                <>
                                    <span className="num">
                                        {tr(r.units3m, { n: number(p.units) })}
                                    </span>
                                    {p.growth_pct !== null && (
                                        <span
                                            className={cn(
                                                'num inline-flex items-center',
                                                p.growth_pct >= 0
                                                    ? 'text-success'
                                                    : 'text-danger',
                                            )}
                                        >
                                            {p.growth_pct >= 0 ? (
                                                <ArrowUpRight className="size-3" />
                                            ) : (
                                                <ArrowDownRight className="size-3" />
                                            )}
                                            {Math.abs(p.growth_pct)}%
                                        </span>
                                    )}
                                </>
                            }
                            aside={
                                <Sparkline
                                    data={p.spark}
                                    width={56}
                                    height={22}
                                    tone="navy"
                                />
                            }
                            onDesign={() => onDesign(p.prefill)}
                        />
                    </StaggerItem>
                ))}
            </Stagger>

            {insights.rising.length > 0 && (
                <Section title={r.rising} icon={TrendingUp}>
                    <div className="space-y-2">
                        {insights.rising.map((p) => (
                            <Row
                                key={p.id}
                                image={p.image_url}
                                title={p.name}
                                href={`/store/products/${p.id}`}
                                meta={
                                    <span className="num text-success">
                                        +{tr(r.share, { n: p.change_pct })}
                                    </span>
                                }
                                onDesign={() => onDesign(p.prefill)}
                            />
                        ))}
                    </div>
                </Section>
            )}

            {insights.fading.length > 0 && (
                <Section title={r.fading} icon={ArrowDownRight}>
                    <div className="space-y-2">
                        {insights.fading.map((p) => (
                            <Row
                                key={p.id}
                                image={p.image_url}
                                title={p.name}
                                href={`/store/products/${p.id}`}
                                meta={
                                    <span className="num text-danger">
                                        {tr(r.share, { n: p.change_pct })}
                                    </span>
                                }
                            />
                        ))}
                    </div>
                </Section>
            )}

            {insights.coverage.features_pct !== null && (
                <p className="text-[10px] text-muted-foreground">
                    {tr(r.coverage, { n: insights.coverage.features_pct })}
                </p>
            )}
        </div>
    );
}

function CompetitorsTab({
    insights,
    onDesign,
}: {
    insights: Insights;
    onDesign: (p: Prefill) => void;
}) {
    const { t, tr, number, money } = useI18n();
    const r = t.designer.rail;

    if (insights.competitor_hits.length === 0 && insights.social.length === 0) {
        return (
            <Empty
                icon={Swords}
                text={r.empty.competitors}
                href="/store/competitors"
                action={r.addCompetitors}
            />
        );
    }

    return (
        <div className="space-y-5">
            <p className="flex gap-2 rounded-lg bg-muted/50 px-3 py-2 text-[11px] leading-relaxed text-muted-foreground">
                <Info className="mt-0.5 size-3.5 shrink-0" />
                {r.claimNote}
            </p>
            <Stagger className="space-y-2">
                {insights.competitor_hits.map((h) => (
                    <StaggerItem key={h.id}>
                        <Row
                            image={h.image_url}
                            title={h.name}
                            external={h.url}
                            meta={
                                <>
                                    <span className="font-medium text-foreground/70">
                                        {h.competitor}
                                    </span>
                                    {h.price !== null && (
                                        <span className="num">
                                            {h.currency && h.currency !== 'SAR'
                                                ? `${number(h.price)} ${h.currency}`
                                                : money(h.price)}
                                        </span>
                                    )}
                                </>
                            }
                            below={
                                <div className="flex flex-wrap gap-1 pt-1">
                                    {h.signals.map((s) => (
                                        <Badge
                                            key={s}
                                            variant={
                                                s === 'price_drop'
                                                    ? 'warning'
                                                    : s === 'new_arrival'
                                                      ? 'info'
                                                      : 'neutral'
                                            }
                                            className="px-1.5 py-0 text-[10px]"
                                        >
                                            {r.signals[s]}
                                            {s === 'price_drop' && h.drop_pct
                                                ? ` ${h.drop_pct}%`
                                                : ''}
                                        </Badge>
                                    ))}
                                    {h.echo && (
                                        <Badge
                                            variant="gold"
                                            className="px-1.5 py-0 text-[10px]"
                                        >
                                            <Flame />
                                            {r.echo}
                                        </Badge>
                                    )}
                                    {h.similar_to && (
                                        <span className="text-[10px] text-muted-foreground">
                                            {tr(r.similarTo, {
                                                name: h.similar_to.name,
                                            })}
                                        </span>
                                    )}
                                </div>
                            }
                            onDesign={() => onDesign(h.prefill)}
                        />
                    </StaggerItem>
                ))}
            </Stagger>

            {insights.social.length > 0 && (
                <Section title={r.social} icon={Flame}>
                    <div className="grid grid-cols-2 gap-2">
                        {insights.social.map((s) => (
                            <div
                                key={s.id}
                                className="group overflow-hidden rounded-xl border"
                            >
                                <a
                                    href={s.url ?? undefined}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="relative block aspect-square bg-muted"
                                >
                                    {s.thumbnail_url && (
                                        <img
                                            src={s.thumbnail_url}
                                            alt=""
                                            loading="lazy"
                                            className="size-full object-cover"
                                        />
                                    )}
                                    <span className="absolute start-1.5 top-1.5 rounded-full bg-black/60 px-1.5 py-0.5 text-[10px] font-medium text-white">
                                        {tr(r.timesUsual, {
                                            n: s.times_median,
                                        })}
                                    </span>
                                </a>
                                <div className="flex items-center justify-between gap-1 p-1.5">
                                    <span className="truncate text-[10px] text-muted-foreground">
                                        {s.competitor}
                                    </span>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        className="h-6 px-1.5 text-[10px]"
                                        onClick={() => onDesign(s.prefill)}
                                    >
                                        <WandSparkles className="size-3" />
                                    </Button>
                                </div>
                            </div>
                        ))}
                    </div>
                </Section>
            )}
            <p className="text-[10px] text-muted-foreground">
                {tr(r.competitorCoverage, {
                    n: insights.coverage.competitors,
                    p: number(insights.coverage.competitor_products),
                })}
            </p>
        </div>
    );
}

function GapsTab({
    insights,
    onDesign,
}: {
    insights: Insights;
    onDesign: (p: Prefill) => void;
}) {
    const { t, tr, date } = useI18n();
    const r = t.designer.rail;
    const seasonTone = {
        ready: 'success',
        upcoming: 'neutral',
        at_risk: 'warning',
        late: 'danger',
    } as const;

    return (
        <div className="space-y-5">
            {insights.gaps.length === 0 ? (
                <Empty icon={Lightbulb} text={r.empty.gaps} />
            ) : (
                <Stagger className="space-y-2.5">
                    {insights.gaps.map((g) => (
                        <StaggerItem
                            key={`${g.attribute}:${g.value}`}
                            className="space-y-2 rounded-xl border p-3"
                        >
                            <div className="flex items-start justify-between gap-2">
                                <div className="flex min-w-0 items-center gap-2">
                                    {g.hex ? (
                                        <span
                                            className="size-6 shrink-0 rounded-full ring-1 ring-black/10"
                                            style={{ backgroundColor: g.hex }}
                                        />
                                    ) : (
                                        <Layers className="size-4 shrink-0 text-muted-foreground" />
                                    )}
                                    <span className="truncate text-sm font-medium">
                                        {g.label}
                                    </span>
                                </div>
                                <Badge
                                    variant={
                                        g.source === 'trend'
                                            ? 'success'
                                            : 'neutral'
                                    }
                                    className="text-[10px]"
                                >
                                    {g.source === 'trend'
                                        ? r.trendGap
                                        : r.tabs.competitors}
                                </Badge>
                            </div>
                            {g.market_pct !== null ? (
                                <div className="space-y-1.5">
                                    <Meter
                                        value={g.market_pct}
                                        tone="navy"
                                        height={5}
                                    />
                                    <Meter
                                        value={Math.max(g.mine_pct, 1)}
                                        tone="gold"
                                        height={5}
                                    />
                                    <p className="num text-[11px] text-muted-foreground">
                                        {tr(r.marketVsMine, {
                                            m: g.market_pct,
                                            s: g.mine_pct,
                                        })}
                                    </p>
                                </div>
                            ) : (
                                g.trend && (
                                    <p className="text-[11px] text-muted-foreground">
                                        {tr(r.momentum, {
                                            n: g.trend.momentum,
                                        })}
                                    </p>
                                )
                            )}
                            <Button
                                size="sm"
                                variant="soft"
                                className="h-7 w-full text-xs"
                                onClick={() => onDesign(g.prefill)}
                            >
                                <WandSparkles className="size-3.5" />
                                {r.designThis}
                            </Button>
                        </StaggerItem>
                    ))}
                </Stagger>
            )}

            {insights.seasons.length > 0 && (
                <Section title={r.seasons} icon={CalendarClock}>
                    <div className="space-y-2">
                        {insights.seasons.map((s) => (
                            <div
                                key={s.id}
                                className="flex items-center gap-3 rounded-xl border p-2.5"
                            >
                                <div className="flex size-11 shrink-0 flex-col items-center justify-center rounded-lg bg-muted leading-none">
                                    <span className="num text-sm font-semibold">
                                        {s.days_until}
                                    </span>
                                    <span className="text-[9px] text-muted-foreground">
                                        {r.daysShort}
                                    </span>
                                </div>
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-1.5">
                                        <span className="truncate text-sm font-medium">
                                            {s.name}
                                        </span>
                                        <Badge
                                            variant={seasonTone[s.status]}
                                            className="px-1.5 py-0 text-[10px]"
                                        >
                                            {r.seasonStatus[s.status]}
                                        </Badge>
                                    </div>
                                    <p className="text-[11px] text-muted-foreground">
                                        {date(s.starts_on)} ·{' '}
                                        {tr(r.readyProducts, {
                                            n: s.ready_products,
                                        })}
                                    </p>
                                </div>
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    className="size-8"
                                    onClick={() => onDesign(s.prefill)}
                                    aria-label={r.designThis}
                                >
                                    <WandSparkles className="size-4" />
                                </Button>
                            </div>
                        ))}
                    </div>
                </Section>
            )}

            {insights.saturation.length > 0 && (
                <Section title={r.saturation} icon={AlertTriangle}>
                    <ul className="space-y-1.5">
                        {insights.saturation.map((s, i) => (
                            <li
                                key={i}
                                className="rounded-lg bg-warning-soft/60 px-3 py-2 text-[11px] leading-relaxed"
                            >
                                <span className="font-medium">
                                    {s.category ?? s.look}
                                </span>
                                {s.category && (
                                    <span className="text-muted-foreground">
                                        {' '}
                                        · {s.look}
                                    </span>
                                )}
                                <span className="num ms-1 text-warning">
                                    {s.share}%
                                </span>
                            </li>
                        ))}
                    </ul>
                </Section>
            )}
        </div>
    );
}

function AlertsTab({
    insights,
    briefing,
    onDesign,
}: {
    insights: Insights;
    briefing: Briefing;
    onDesign: (p: Prefill) => void;
}) {
    const { t, field, relative } = useI18n();
    const r = t.designer.rail;
    const [asking, setAsking] = useState(false);
    const severity = {
        danger: { icon: AlertTriangle, cls: 'text-danger bg-danger-soft' },
        warning: { icon: AlertTriangle, cls: 'text-warning bg-warning-soft' },
        info: { icon: Info, cls: 'text-info bg-info-soft' },
    } as const;

    const ask = () =>
        router.post(
            '/store/studio/designer/advice',
            {},
            {
                preserveScroll: true,
                only: ['designer', 'flash'],
                onStart: () => setAsking(true),
                onFinish: () => setAsking(false),
            },
        );

    return (
        <div className="space-y-5">
            <Section
                title={r.advice}
                icon={Lightbulb}
                action={
                    <Button
                        size="sm"
                        variant={briefing ? 'outline' : 'gold'}
                        className="h-7 text-xs"
                        onClick={ask}
                        disabled={asking}
                    >
                        {asking ? (
                            <Loader2 className="size-3.5 animate-spin" />
                        ) : (
                            <Sparkles className="size-3.5" />
                        )}
                        {briefing ? r.refreshAdvice : r.askAdvice}
                    </Button>
                }
            >
                {asking ? (
                    <div className="space-y-2">
                        <p className="text-xs text-muted-foreground">
                            {r.asking}
                        </p>
                        {[0, 1, 2].map((i) => (
                            <div
                                key={i}
                                className="shimmer h-14 rounded-xl bg-muted/60"
                            />
                        ))}
                    </div>
                ) : briefing?.items.length ? (
                    <ol className="space-y-2">
                        {briefing.items.map((item, i) => (
                            <li
                                key={i}
                                className="flex gap-3 rounded-xl border p-3"
                            >
                                <span className="num font-semibold text-gold-foreground dark:text-gold">
                                    {String(i + 1).padStart(2, '0')}
                                </span>
                                <div className="min-w-0 flex-1 space-y-1">
                                    <p className="text-sm font-medium">
                                        {field(item, 'title')}
                                    </p>
                                    <p className="text-xs leading-relaxed text-muted-foreground">
                                        {field(item, 'body')}
                                    </p>
                                    <div className="flex gap-1.5 pt-1">
                                        {item.prefill && (
                                            <Button
                                                size="sm"
                                                variant="soft"
                                                className="h-7 text-xs"
                                                onClick={() =>
                                                    onDesign(item.prefill!)
                                                }
                                            >
                                                <WandSparkles className="size-3.5" />
                                                {r.designThis}
                                            </Button>
                                        )}
                                        {item.href && (
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                className="h-7 text-xs"
                                                asChild
                                            >
                                                <Link href={item.href}>
                                                    {r.open}
                                                </Link>
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            </li>
                        ))}
                        <p
                            className="text-[10px] text-muted-foreground"
                            suppressHydrationWarning
                        >
                            {briefing.from_model
                                ? r.adviceByModel
                                : r.adviceByTemplate}
                            {briefing.created_at &&
                                ` · ${relative(briefing.created_at)}`}
                        </p>
                    </ol>
                ) : (
                    <p className="rounded-xl bg-muted/50 px-3 py-3 text-xs leading-relaxed text-muted-foreground">
                        {r.adviceEmpty}
                    </p>
                )}
            </Section>

            <Section title={r.alerts} icon={AlertTriangle}>
                {insights.alerts.length === 0 ? (
                    <p className="text-xs text-muted-foreground">
                        {r.noAlerts}
                    </p>
                ) : (
                    <ul className="space-y-2">
                        {insights.alerts.map((a, i) => {
                            const meta = severity[a.severity];

                            return (
                                <li
                                    key={i}
                                    className="flex gap-2.5 rounded-xl border p-2.5"
                                >
                                    <span
                                        className={cn(
                                            'flex size-7 shrink-0 items-center justify-center rounded-lg',
                                            meta.cls,
                                        )}
                                    >
                                        <meta.icon className="size-3.5" />
                                    </span>
                                    <div className="min-w-0 flex-1 space-y-1.5">
                                        <p className="text-xs leading-relaxed">
                                            {a.text}
                                        </p>
                                        {(a.prefill || a.href) && (
                                            <div className="flex gap-1.5">
                                                {a.prefill && (
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            onDesign(a.prefill!)
                                                        }
                                                        className="cursor-pointer text-[11px] font-medium text-primary hover:underline"
                                                    >
                                                        {r.designThis}
                                                    </button>
                                                )}
                                                {a.href &&
                                                    (a.href.startsWith(
                                                        'http',
                                                    ) ? (
                                                        <a
                                                            href={a.href}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="inline-flex items-center gap-0.5 text-[11px] text-muted-foreground hover:underline"
                                                        >
                                                            {r.open}
                                                            <ExternalLink className="size-3" />
                                                        </a>
                                                    ) : (
                                                        <Link
                                                            href={a.href}
                                                            className="text-[11px] text-muted-foreground hover:underline"
                                                        >
                                                            {r.open}
                                                        </Link>
                                                    ))}
                                            </div>
                                        )}
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </Section>
        </div>
    );
}

function Row({
    image,
    rank,
    title,
    href,
    external,
    meta,
    below,
    aside,
    onDesign,
}: {
    image: string | null;
    rank?: number;
    title: string;
    href?: string;
    external?: string | null;
    meta?: React.ReactNode;
    below?: React.ReactNode;
    aside?: React.ReactNode;
    onDesign?: () => void;
}) {
    const { t } = useI18n();
    const [broken, setBroken] = useState(false);
    const titleNode = href ? (
        <Link href={href} className="hover:underline">
            {title}
        </Link>
    ) : external ? (
        <a
            href={external}
            target="_blank"
            rel="noreferrer"
            className="hover:underline"
        >
            {title}
        </a>
    ) : (
        title
    );

    return (
        <div className="group flex gap-3 rounded-xl p-1.5 transition-colors hover:bg-muted/40">
            <div className="relative size-14 shrink-0 overflow-hidden rounded-lg bg-muted">
                {image && !broken && (
                    <img
                        src={image}
                        alt=""
                        loading="lazy"
                        onError={() => setBroken(true)}
                        className="size-full object-cover"
                    />
                )}
                {rank !== undefined && (
                    <span className="num absolute start-0.5 top-0.5 rounded bg-black/55 px-1 text-[9px] font-semibold text-white">
                        {rank}
                    </span>
                )}
            </div>
            <div className="min-w-0 flex-1">
                <p className="line-clamp-2 text-[13px] leading-snug font-medium">
                    {titleNode}
                </p>
                <div className="mt-0.5 flex flex-wrap items-center gap-x-2 text-[11px] text-muted-foreground">
                    {meta}
                </div>
                {below}
            </div>
            <div className="flex shrink-0 flex-col items-end justify-between gap-1">
                {aside}
                {onDesign && (
                    <Button
                        size="sm"
                        variant="ghost"
                        className="h-6 px-1.5 text-[11px] text-primary opacity-80 group-hover:opacity-100"
                        onClick={onDesign}
                    >
                        <WandSparkles className="size-3" />
                        {t.designer.rail.designThis}
                    </Button>
                )}
            </div>
        </div>
    );
}

function Section({
    title,
    hint,
    icon: Icon,
    action,
    children,
}: {
    title: string;
    hint?: string;
    icon: LucideIcon;
    action?: React.ReactNode;
    children: React.ReactNode;
}) {
    return (
        <section className="space-y-2.5">
            <div className="flex items-center justify-between gap-2">
                <div>
                    <h3 className="flex items-center gap-1.5 text-xs font-semibold">
                        <Icon className="size-3.5 text-muted-foreground" />
                        {title}
                    </h3>
                    {hint && (
                        <p className="mt-0.5 text-[10px] text-muted-foreground">
                            {hint}
                        </p>
                    )}
                </div>
                {action}
            </div>
            {children}
        </section>
    );
}

function Empty({
    icon: Icon,
    text,
    href,
    action,
}: {
    icon: LucideIcon;
    text: string;
    href?: string;
    action?: string;
}) {
    return (
        <div className="flex flex-col items-center gap-3 px-2 py-8 text-center">
            <span className="flex size-11 items-center justify-center rounded-full bg-muted text-muted-foreground">
                <Icon className="size-5" />
            </span>
            <p className="text-xs leading-relaxed text-muted-foreground">
                {text}
            </p>
            {href && action && (
                <Button size="sm" variant="outline" asChild>
                    <Link href={href}>{action}</Link>
                </Button>
            )}
        </div>
    );
}

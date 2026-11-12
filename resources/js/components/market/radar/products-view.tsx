import { Link } from '@inertiajs/react';
import {
    BarChart3,
    ExternalLink,
    Layers,
    Tag,
    TrendingDown,
} from 'lucide-react';
import { useState } from 'react';
import { PlatformBadge } from '@/components/market/platform-badge';
import { Stagger, StaggerItem } from '@/components/mojo/motion';
import { Sparkline } from '@/components/mojo/stat-card';
import { useI18n } from '@/hooks/use-i18n';
import { useVocabulary } from '@/hooks/use-vocabulary';
import { cn } from '@/lib/utils';
import { RecommendCard } from './cards';
import { GarmentName, SectionHead, Visual } from './parts';
import type { GarmentKey } from '@/components/studio/designer/types';
import type { Analysis, RadarData } from './types';

type Verb = RadarData['recommendations'][number]['verb'] | 'all';

/**
 * Their products: every competitor design worth adding, with filters;
 * price cuts and new arrivals that are activity rather than demand; and
 * how their prices and range compare with the store's own.
 */
export function ProductsView({ radar }: { radar: RadarData }) {
    const { t, tr } = useI18n();
    const r = t.radar;
    const [verb, setVerb] = useState<Verb>('all');
    const verbs = (
        [
            'all',
            'add_now',
            'price_fit',
            'test_small',
            'restock',
            'watch',
        ] as Verb[]
    ).filter(
        (v) => v === 'all' || radar.recommendations.some((x) => x.verb === v),
    );
    const list = radar.recommendations.filter(
        (x) => verb === 'all' || x.verb === verb,
    );

    return (
        <div className="space-y-10">
            <section className="space-y-4">
                <SectionHead
                    title={r.recs.title}
                    hint={r.recs.hint}
                    action={
                        verbs.length > 2 && (
                            <div className="flex flex-wrap gap-1.5">
                                {verbs.map((v) => (
                                    <button
                                        key={v}
                                        type="button"
                                        onClick={() => setVerb(v)}
                                        className={cn(
                                            'cursor-pointer rounded-full border px-3 py-1 text-xs',
                                            verb === v
                                                ? 'border-navy bg-navy text-white dark:text-background'
                                                : 'text-muted-foreground hover:text-foreground',
                                        )}
                                    >
                                        {v === 'all'
                                            ? t.radar.drivers.dimensions.all
                                            : r.recs.verbs[v]}
                                    </button>
                                ))}
                            </div>
                        )
                    }
                />
                {list.length === 0 ? (
                    <p className="rounded-2xl border border-dashed px-6 py-8 text-center text-sm text-muted-foreground">
                        {r.recs.empty}
                    </p>
                ) : (
                    <Stagger className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                        {list.map((rec, i) => (
                            <StaggerItem key={`${rec.kind}-${rec.id}`}>
                                <RecommendCard rec={rec} rank={i + 1} />
                            </StaggerItem>
                        ))}
                    </Stagger>
                )}
            </section>

            <AnalysisSection analysis={radar.analysis} />

            {radar.activity.length > 0 && (
                <section className="space-y-4">
                    <SectionHead
                        title={r.activity.title}
                        hint={r.activity.hint}
                        icon={TrendingDown}
                    />
                    <div className="grid gap-2 md:grid-cols-2">
                        {radar.activity.map((a) => (
                            <div
                                key={a.id}
                                id={`radar-product-${a.id}`}
                                className="flex items-center gap-3 rounded-xl border bg-card p-2.5"
                            >
                                <Visual
                                    src={a.image_url}
                                    className="size-12 shrink-0 rounded-lg"
                                />
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium">
                                        {a.name}
                                    </p>
                                    <p className="text-[11px] text-muted-foreground">
                                        {a.competitor}
                                        {a.drop_pct &&
                                        a.previous_price &&
                                        a.price
                                            ? ` · ${tr(r.activity.cut, { from: a.previous_price, to: a.price })} (${a.drop_pct}%)`
                                            : ''}
                                        {a.signals.includes('new_arrival')
                                            ? ` · ${r.signals.new_arrival}`
                                            : ''}
                                    </p>
                                </div>
                                {a.url && (
                                    <a
                                        href={a.url}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="text-muted-foreground hover:text-foreground"
                                        aria-label={r.openProduct}
                                    >
                                        <ExternalLink className="size-4" />
                                    </a>
                                )}
                            </div>
                        ))}
                    </div>
                </section>
            )}
        </div>
    );
}

function AnalysisSection({ analysis }: { analysis: Analysis }) {
    const { t, tr, number } = useI18n();
    const { hex, label } = useVocabulary();
    const a = t.radar.analysis;
    const rows = analysis.competitors.filter((c) => c.products > 0);

    if (rows.length === 0) {
        return (
            <section className="space-y-4">
                <SectionHead title={a.title} hint={a.hint} icon={BarChart3} />
                <p className="rounded-2xl border border-dashed px-6 py-8 text-center text-sm text-muted-foreground">
                    {a.empty}
                </p>
            </section>
        );
    }

    const priced = rows.filter((c) => c.price);
    const lo = Math.min(
        ...priced.map((c) => c.price!.min),
        ...Object.values(analysis.mine.bands).map((b) => b.min),
    );
    const hi = Math.max(
        ...priced.map((c) => c.price!.max),
        ...Object.values(analysis.mine.bands).map((b) => b.max),
    );
    const at = (v: number) => `${((v - lo) / Math.max(1, hi - lo)) * 100}%`;
    const myBand = Object.values(analysis.mine.bands)[0];
    const garments = Array.from(
        new Set([
            ...Object.keys(analysis.market.mix),
            ...Object.keys(analysis.mine.mix),
        ]),
    );
    const palette = [
        'var(--chart-1)',
        'var(--chart-2)',
        'var(--chart-3)',
        'var(--chart-4)',
        'var(--chart-5)',
        'var(--muted-foreground)',
    ];

    return (
        <section className="space-y-4">
            <SectionHead title={a.title} hint={a.hint} icon={BarChart3} />
            <div className="grid gap-6 xl:grid-cols-2">
                <div className="space-y-4 rounded-2xl border border-border/70 bg-card p-5">
                    <div>
                        <h3 className="text-sm font-semibold">{a.prices}</h3>
                        <p className="text-[11px] text-muted-foreground">
                            {a.pricesHint}
                        </p>
                    </div>
                    {priced.length === 0 ? (
                        <p className="text-xs text-muted-foreground">
                            {a.noPrices}
                        </p>
                    ) : (
                        <div className="relative space-y-3" dir="ltr">
                            {myBand && (
                                <div
                                    className="absolute inset-y-0 rounded-md bg-navy/10"
                                    style={{
                                        left: at(myBand.min),
                                        width: `calc(${at(myBand.max)} - ${at(myBand.min)})`,
                                    }}
                                />
                            )}
                            {priced.map((c) => (
                                <div key={c.id} className="relative space-y-1">
                                    <div
                                        className="flex items-center justify-between text-[11px]"
                                        dir="auto"
                                    >
                                        <Link
                                            href={`/store/competitors?competitor=${c.id}`}
                                            className="font-medium hover:underline"
                                        >
                                            {c.name}
                                        </Link>
                                        <span className="num text-muted-foreground">
                                            {number(c.price!.p25)}–
                                            {number(c.price!.p75)}
                                        </span>
                                    </div>
                                    <div className="relative h-2.5 rounded-full bg-muted">
                                        <span
                                            className="absolute inset-y-0 rounded-full bg-gold/40"
                                            style={{
                                                left: at(c.price!.min),
                                                width: `calc(${at(c.price!.max)} - ${at(c.price!.min)})`,
                                            }}
                                        />
                                        <span
                                            className="absolute inset-y-0 rounded-full bg-gold"
                                            style={{
                                                left: at(c.price!.p25),
                                                width: `calc(${at(c.price!.p75)} - ${at(c.price!.p25)})`,
                                            }}
                                        />
                                        <span
                                            className="absolute top-1/2 size-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-card bg-foreground"
                                            style={{
                                                left: at(c.price!.median),
                                            }}
                                        />
                                    </div>
                                </div>
                            ))}
                            {myBand && (
                                <p
                                    className="num relative text-[10px] text-navy"
                                    dir="auto"
                                >
                                    {a.mine}: {number(myBand.min)}–
                                    {number(myBand.max)}
                                </p>
                            )}
                        </div>
                    )}
                </div>

                <div className="space-y-4 rounded-2xl border border-border/70 bg-card p-5">
                    <div>
                        <h3 className="flex items-center gap-1.5 text-sm font-semibold">
                            <Layers className="size-4 text-muted-foreground" />
                            {a.mix}
                        </h3>
                    </div>
                    {[
                        { name: a.mine, mix: analysis.mine.mix, mine: true },
                        {
                            name: t.radar.tabs.products,
                            mix: analysis.market.mix,
                            mine: false,
                        },
                    ].map((row) => (
                        <div key={row.name} className="space-y-1">
                            <p
                                className={cn(
                                    'text-[11px]',
                                    row.mine
                                        ? 'font-medium text-navy'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {row.name}
                            </p>
                            <div className="flex h-3 overflow-hidden rounded-full bg-muted">
                                {garments.map((g, i) =>
                                    row.mix[g] ? (
                                        <span
                                            key={g}
                                            className="h-full"
                                            style={{
                                                width: `${row.mix[g]}%`,
                                                backgroundColor:
                                                    palette[i % palette.length],
                                            }}
                                            title={`${g} ${row.mix[g]}%`}
                                        />
                                    ) : null,
                                )}
                            </div>
                        </div>
                    ))}
                    <div className="flex flex-wrap gap-x-3 gap-y-1 text-[11px]">
                        {garments.map((g, i) => (
                            <span
                                key={g}
                                className="inline-flex items-center gap-1"
                            >
                                <span
                                    className="size-2 rounded-full"
                                    style={{
                                        backgroundColor:
                                            palette[i % palette.length],
                                    }}
                                />
                                <GarmentName garment={g as GarmentKey} />
                                <span className="num text-muted-foreground">
                                    {analysis.market.mix[g] ?? 0}% /{' '}
                                    {analysis.mine.mix[g] ?? 0}%
                                </span>
                            </span>
                        ))}
                    </div>
                    {(analysis.market.missing.length > 0 ||
                        analysis.market.colors_missing.length > 0) && (
                        <div className="rounded-xl bg-gold-soft/50 px-3 py-2 text-xs">
                            <span className="font-medium">{a.missing}: </span>
                            {analysis.market.missing.map((g) => (
                                <span key={g} className="me-2">
                                    <GarmentName garment={g as GarmentKey} />
                                </span>
                            ))}
                            {analysis.market.colors_missing.map((c) => (
                                <span
                                    key={c}
                                    className="me-2 inline-flex items-center gap-1"
                                >
                                    <span
                                        className="size-2.5 rounded-full ring-1 ring-black/10"
                                        style={{ backgroundColor: hex(c) }}
                                    />
                                    {label('colors', c)}
                                </span>
                            ))}
                        </div>
                    )}
                </div>
            </div>

            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                {rows.map((c) => (
                    <div
                        key={c.id}
                        className="space-y-3 rounded-2xl border border-border/70 bg-card p-4"
                    >
                        <div className="flex items-start justify-between gap-2">
                            <div className="min-w-0">
                                <Link
                                    href={`/store/competitors?competitor=${c.id}`}
                                    className="block truncate font-medium hover:underline"
                                >
                                    {c.name}
                                </Link>
                                <p className="text-[11px] text-muted-foreground">
                                    {tr(a.products, { n: c.products })}
                                    {c.classified_pct !== null &&
                                        ` · ${tr(a.classified, { p: c.classified_pct })}`}
                                </p>
                            </div>
                            <PlatformBadge
                                platform={c.platform}
                                withLabel={false}
                            />
                        </div>
                        <div className="flex flex-wrap items-center gap-1.5">
                            {c.colors.map((col) => (
                                <span
                                    key={col}
                                    className="inline-flex items-center gap-1 rounded-full bg-muted/70 px-2 py-0.5 text-[11px]"
                                >
                                    <span
                                        className="size-2.5 rounded-full ring-1 ring-black/10"
                                        style={{ backgroundColor: hex(col) }}
                                    />
                                    {label('colors', col)}
                                </span>
                            ))}
                        </div>
                        <div className="grid grid-cols-2 gap-2 text-[11px]">
                            <div className="rounded-lg bg-muted/50 px-2.5 py-2">
                                <p className="text-muted-foreground">
                                    {a.pace}
                                </p>
                                {c.new_weeks ? (
                                    <div className="flex items-end justify-between gap-1">
                                        <span className="num text-sm font-semibold">
                                            {c.new_30d}
                                        </span>
                                        <Sparkline
                                            data={c.new_weeks}
                                            width={60}
                                            height={20}
                                            tone="gold"
                                        />
                                    </div>
                                ) : (
                                    <p className="text-[10px] text-muted-foreground">
                                        {a.paceUnknown}
                                    </p>
                                )}
                            </div>
                            <div className="rounded-lg bg-muted/50 px-2.5 py-2">
                                <p className="flex items-center gap-1 text-muted-foreground">
                                    <Tag className="size-3" />
                                    {a.cuts}
                                </p>
                                <p className="num text-sm font-semibold">
                                    {c.cuts > 0
                                        ? tr(a.cutsValue, {
                                              n: c.cuts,
                                              p: c.cut_median ?? 0,
                                          })
                                        : '0'}
                                </p>
                            </div>
                        </div>
                        {c.signal_share !== null && (
                            <p className="text-[11px] text-muted-foreground">
                                {tr(a.signalShare, { p: c.signal_share })}
                            </p>
                        )}
                        {c.missing.length > 0 && (
                            <p className="text-[11px]">
                                <span className="text-muted-foreground">
                                    {a.missing}:{' '}
                                </span>
                                {c.missing.map((g) => (
                                    <span
                                        key={g}
                                        className="me-1.5 font-medium"
                                    >
                                        <GarmentName
                                            garment={g as GarmentKey}
                                        />
                                    </span>
                                ))}
                            </p>
                        )}
                    </div>
                ))}
            </div>
        </section>
    );
}

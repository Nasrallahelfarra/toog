import {
    ArrowUpRight,
    CheckCircle2,
    ChevronRight,
    Info,
    Layers,
    Package,
    Sparkles,
    Tag,
    Trophy,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { ProductImage, ProductPrice } from './competitor-products';
import type { CompetitorProduct, ProductFilter } from './competitor-types';
import { useI18n } from '@/hooks/use-i18n';

type Source = { url: string; label?: string; observed_at?: string };

export type ProductEvidence = {
    observed_at: string;
    source_url: string;
    currency: string | null;
    description?: string;
    best_seller: Source | null;
    promotion: Source | null;
    published_sales: { text: string; url: string } | null;
};

export type CompetitorAnalysisData = {
    crawl: {
        observed_at: string;
        home_url: string;
        catalog_url: string;
        limited: boolean;
        sources: { url: string; http_status: number }[];
        warnings: { url: string; message: string }[];
    };
    facts: {
        products: number;
        best_sellers: number;
        featured: number;
        published_sales: number;
        currency: string | null;
        price_min: number | null;
        price_max: number | null;
        categories: Record<string, number>;
    };
    ai: {
        status: string;
        summary: string | null;
        insights: {
            title: string;
            description: string;
            product_ids: number[];
        }[];
    };
};

export function CompetitorAnalysis({
    analysis,
    products,
    onBrowse,
    onSources,
}: {
    analysis: CompetitorAnalysisData;
    products: CompetitorProduct[];
    onBrowse: (filter: ProductFilter) => void;
    onSources: () => void;
}) {
    const { t, number } = useI18n();
    const c = t.market.competitors;
    const w = c.workspace;
    const { facts, ai } = analysis;
    const categories = Object.entries(facts.categories)
        .sort((a, b) => b[1] - a[1])
        .slice(0, 5);
    const metrics = [
        {
            label: w.sampleProducts,
            value: number(facts.products),
            hint: c.coverage,
            icon: Package,
            filter: 'all' as const,
            tone: 'bg-info-soft/70 text-info',
        },
        {
            label: c.bestSeller,
            value: number(facts.best_sellers),
            hint: w.merchantLabel,
            icon: Trophy,
            filter: 'best' as const,
            tone: 'bg-gold-soft/70 text-gold-foreground dark:text-gold',
        },
        {
            label: w.featured,
            value: number(facts.featured),
            hint: w.storefrontLabel,
            icon: Sparkles,
            filter: 'featured' as const,
            tone: 'bg-success-soft/70 text-success',
        },
    ];

    return (
        <div className="space-y-7">
            <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
                {metrics.map(
                    ({ label, value, hint, icon: Icon, filter, tone }) => (
                        <button
                            key={label}
                            type="button"
                            onClick={() => onBrowse(filter)}
                            className="group rounded-2xl border border-border/70 bg-card p-4 text-start transition-colors hover:border-primary/40 focus-visible:outline-2 focus-visible:outline-ring sm:p-5"
                        >
                            <div className="mb-4 flex items-center justify-between">
                                <span
                                    className={`flex size-9 items-center justify-center rounded-xl ${tone}`}
                                >
                                    <Icon className="size-4" />
                                </span>
                                <ChevronRight className="size-4 text-muted-foreground/50 transition-transform group-hover:translate-x-0.5 rtl:rotate-180" />
                            </div>
                            <div className="num text-3xl font-semibold tracking-tight">
                                {value}
                            </div>
                            <div className="mt-1 text-sm font-medium">
                                {label}
                            </div>
                            <div className="mt-1 text-xs text-muted-foreground">
                                {hint}
                            </div>
                        </button>
                    ),
                )}
                <div className="flex flex-col rounded-2xl border border-border/70 bg-card p-4 sm:p-5">
                    <span className="mb-4 flex size-9 items-center justify-center rounded-xl bg-muted text-muted-foreground">
                        <Tag className="size-4" />
                    </span>
                    <div className="num text-2xl font-semibold tracking-tight">
                        <bdi dir="ltr">
                            {facts.price_min !== null &&
                            facts.price_max !== null
                                ? `${number(facts.price_min, Number.isInteger(facts.price_min) ? 0 : 2)} – ${number(facts.price_max, Number.isInteger(facts.price_max) ? 0 : 2)}`
                                : '—'}
                        </bdi>
                    </div>
                    <div className="mt-1 text-sm font-medium">
                        {c.priceRange}{' '}
                        {facts.currency && (
                            <span className="text-xs text-muted-foreground">
                                ({facts.currency})
                            </span>
                        )}
                    </div>
                    <div className="mt-1 text-xs text-muted-foreground">
                        {w.priceHint}
                    </div>
                </div>
            </div>

            <div className="flex flex-col gap-3 rounded-xl bg-info-soft/35 px-4 py-3 sm:flex-row sm:items-center">
                <Info className="hidden size-4 shrink-0 text-info sm:block" />
                <p className="flex-1 text-xs leading-relaxed text-muted-foreground">
                    {facts.published_sales > 0
                        ? c.salesPublished
                        : c.salesUnavailable}
                </p>
                <button
                    type="button"
                    onClick={onSources}
                    className="shrink-0 text-start text-xs font-medium text-info underline-offset-4 hover:underline"
                >
                    {w.sources}
                </button>
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
                                {w.aiSubtitle}
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
                                {ai.insights.length > 0 && (
                                    <div className="space-y-4">
                                        {ai.insights.map((insight, i) => (
                                            <div
                                                key={i}
                                                className="flex gap-3 border-t border-border/60 pt-4"
                                            >
                                                <span className="num mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">
                                                    {number(i + 1)}
                                                </span>
                                                <div className="min-w-0 space-y-2">
                                                    <h3 className="text-sm font-semibold">
                                                        {insight.title}
                                                    </h3>
                                                    <p className="text-sm leading-7 text-muted-foreground">
                                                        {insight.description}
                                                    </p>
                                                    <div className="flex flex-wrap gap-2">
                                                        {insight.product_ids.map(
                                                            (id) => {
                                                                const product =
                                                                    products.find(
                                                                        (p) =>
                                                                            p.id ===
                                                                            id,
                                                                    );
                                                                return product?.url ? (
                                                                    <a
                                                                        key={id}
                                                                        href={
                                                                            product.url
                                                                        }
                                                                        target="_blank"
                                                                        rel="noreferrer"
                                                                        className="max-w-full truncate rounded-md bg-muted/60 px-2 py-1 text-xs text-primary hover:underline"
                                                                    >
                                                                        {
                                                                            product.name
                                                                        }
                                                                    </a>
                                                                ) : null;
                                                            },
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                                <p className="border-t border-border/60 pt-4 text-xs leading-relaxed text-muted-foreground">
                                    {c.hypotheses}
                                </p>
                            </>
                        ) : (
                            <>
                                <div>
                                    <h3 className="text-sm font-medium">
                                        {ai.status === 'processing'
                                            ? c.scanProgress.aiProcessing
                                            : w.aiWaiting}
                                    </h3>
                                    <p className="mt-2 text-sm leading-7 text-muted-foreground">
                                        {w.aiWaitingHint}
                                    </p>
                                </div>
                                {ai.status !== 'processing' && (
                                    <details className="text-xs leading-relaxed text-muted-foreground">
                                        <summary className="cursor-pointer text-primary">
                                            {t.common.details}
                                        </summary>
                                        <p className="mt-2">
                                            {ai.status === 'not_configured'
                                                ? c.aiNotConfigured
                                                : c.aiUnavailable}
                                        </p>
                                    </details>
                                )}
                                <Button
                                    variant="soft"
                                    onClick={() => onBrowse('all')}
                                >
                                    {w.browse}
                                    <ArrowUpRight className="size-4 rtl:-scale-x-100" />
                                </Button>
                            </>
                        )}
                    </div>
                </section>

                <section className="rounded-2xl border border-border/70 bg-card p-5 sm:p-6">
                    <h2 className="flex items-center gap-2 font-semibold">
                        <Layers className="size-4 text-muted-foreground" />
                        {w.categories}
                    </h2>
                    <p className="mt-1 text-xs text-muted-foreground">
                        {w.categoriesHint}
                    </p>
                    {categories.length ? (
                        <div className="mt-6 space-y-5">
                            {categories.map(([label, count]) => (
                                <div key={label}>
                                    <div className="mb-2 flex items-center justify-between gap-3 text-xs">
                                        <span className="truncate" dir="auto">
                                            {label}
                                        </span>
                                        <span className="num text-muted-foreground">
                                            {number(count)}
                                        </span>
                                    </div>
                                    <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                                        <div
                                            className="h-full rounded-full bg-primary/60"
                                            style={{
                                                width: `${Math.min(100, (count / Math.max(facts.products, 1)) * 100)}%`,
                                            }}
                                        />
                                    </div>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <p className="py-8 text-sm text-muted-foreground">
                            {t.common.noData}
                        </p>
                    )}
                </section>
            </div>

            <section className="space-y-4">
                <div>
                    <h2 className="text-lg font-semibold">{w.signals}</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {w.signalsHint}
                    </p>
                </div>
                <div className="grid gap-5 lg:grid-cols-2">
                    {(
                        [
                            {
                                title: w.best,
                                filter: 'best',
                                icon: Trophy,
                                entries: products.filter(
                                    (p) => p.evidence?.best_seller,
                                ),
                                color: 'text-gold',
                            },
                            {
                                title: w.promoted,
                                filter: 'featured',
                                icon: Sparkles,
                                entries: products.filter(
                                    (p) => p.evidence?.promotion,
                                ),
                                color: 'text-info',
                            },
                        ] as const
                    ).map(({ title, filter, icon: Icon, entries, color }) => (
                        <div
                            key={filter}
                            className="flex flex-col rounded-2xl border border-border/70 bg-card p-5"
                        >
                            <h3 className="mb-4 flex items-center gap-2 text-sm font-semibold">
                                <Icon className={`size-4 ${color}`} />
                                {title}
                            </h3>
                            {entries.length ? (
                                <div className="flex-1 divide-y divide-border/60">
                                    {entries.slice(0, 3).map((p) => (
                                        <div
                                            key={p.id}
                                            className="flex items-center gap-3 py-3 first:pt-0"
                                        >
                                            <ProductImage
                                                key={p.image_url}
                                                product={p}
                                                className="size-16 shrink-0 rounded-xl"
                                            />
                                            <div className="min-w-0 flex-1 space-y-1">
                                                <h4 className="text-sm font-medium">
                                                    {p.url ? (
                                                        <a
                                                            href={p.url}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="line-clamp-2 hover:text-primary hover:underline"
                                                            dir="auto"
                                                        >
                                                            {p.name}
                                                        </a>
                                                    ) : (
                                                        p.name
                                                    )}
                                                </h4>
                                                <div className="text-sm">
                                                    <ProductPrice product={p} />
                                                </div>
                                            </div>
                                            {p.url && (
                                                <a
                                                    href={p.url}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="flex size-9 shrink-0 items-center justify-center rounded-full hover:bg-muted"
                                                    aria-label={`${w.openProduct}: ${p.name}`}
                                                >
                                                    <ArrowUpRight className="size-4 text-muted-foreground rtl:-scale-x-100" />
                                                </a>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className="flex-1 py-7 text-sm leading-7 text-muted-foreground">
                                    {c.noEvidence}
                                </p>
                            )}
                            {entries.length > 0 && (
                                <Button
                                    variant="ghost"
                                    className="mt-3 w-full border-t pt-3 text-primary"
                                    onClick={() => onBrowse(filter)}
                                >
                                    {w.viewAll}
                                    <ChevronRight className="size-4 rtl:rotate-180" />
                                </Button>
                            )}
                        </div>
                    ))}
                </div>
            </section>
        </div>
    );
}

export function CompetitorSources({
    analysis,
}: {
    analysis: CompetitorAnalysisData;
}) {
    const { t, dateTime, number } = useI18n();
    const c = t.market.competitors;
    const w = c.workspace;
    return (
        <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)]">
            <section className="min-w-0 overflow-hidden rounded-2xl border bg-card">
                <div className="border-b p-5">
                    <h2 className="font-semibold">{w.coverageTitle}</h2>
                    <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                        {w.coverageDescription}
                    </p>
                </div>
                <ul className="divide-y divide-border/60">
                    {analysis.crawl.sources.map((source, i) => {
                        const read =
                            source.http_status >= 200 &&
                            source.http_status < 300;
                        return (
                            <li
                                key={`${source.url}-${i}`}
                                className="flex items-start gap-3 p-4 sm:p-5"
                            >
                                <span className="num mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-muted text-xs text-muted-foreground">
                                    {number(i + 1)}
                                </span>
                                <div className="min-w-0 flex-1">
                                    <a
                                        href={source.url}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="block text-start text-sm break-all text-primary underline-offset-4 hover:underline"
                                        dir="ltr"
                                    >
                                        {source.url}
                                    </a>
                                    <span
                                        className={`mt-2 inline-block text-xs ${read ? 'text-success' : 'text-warning'}`}
                                    >
                                        {read ? w.read : w.notRead}
                                    </span>
                                </div>
                                <ArrowUpRight className="mt-1 size-4 shrink-0 text-muted-foreground rtl:-scale-x-100" />
                            </li>
                        );
                    })}
                </ul>
            </section>
            <aside className="space-y-4 rounded-2xl border bg-card p-5 text-sm leading-7">
                <h2 className="flex items-center gap-2 font-semibold">
                    <Info className="size-4 text-info" />
                    {w.scopeTitle}
                </h2>
                <p className="text-muted-foreground">{c.evidenceNote}</p>
                <p className="text-muted-foreground">
                    {analysis.facts.published_sales > 0
                        ? c.salesPublished
                        : c.salesUnavailable}
                </p>
                {analysis.crawl.limited && (
                    <p className="text-muted-foreground">{c.sampleNote}</p>
                )}
                {analysis.crawl.warnings.length > 0 && (
                    <p className="rounded-xl bg-warning-soft/50 p-3 text-warning">
                        {c.partialCrawl}
                    </p>
                )}
                <div className="border-t pt-3 text-xs text-muted-foreground">
                    {w.recordedAt}
                    <div className="mt-1 font-medium text-foreground">
                        {dateTime(analysis.crawl.observed_at)}
                    </div>
                </div>
            </aside>
        </div>
    );
}

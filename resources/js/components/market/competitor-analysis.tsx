import { ArrowUpRight, CheckCircle2, Info } from 'lucide-react';
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

type Product = {
    id: number;
    name: string;
    url: string | null;
    price: number | null;
    evidence: ProductEvidence | null;
};

export function EvidenceLinks({
    evidence,
}: {
    evidence: ProductEvidence | null;
}) {
    const { t } = useI18n();
    const c = t.market.competitors;
    if (!evidence) return null;

    return (
        <div className="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs">
            {evidence.best_seller && (
                <a
                    href={evidence.best_seller.url}
                    target="_blank"
                    rel="noreferrer"
                    className="text-primary underline"
                >
                    {c.sellerClaim}
                </a>
            )}
            {evidence.promotion && (
                <a
                    href={evidence.promotion.url}
                    target="_blank"
                    rel="noreferrer"
                    className="text-primary underline"
                >
                    {c.featured}
                </a>
            )}
            {evidence.published_sales && (
                <a
                    href={evidence.published_sales.url}
                    target="_blank"
                    rel="noreferrer"
                    className="text-primary underline"
                >
                    {evidence.published_sales.text}
                </a>
            )}
        </div>
    );
}

export function CompetitorAnalysis({
    analysis,
    products,
}: {
    analysis: CompetitorAnalysisData | null;
    products: Product[];
}) {
    const { t, number, relative } = useI18n();
    const c = t.market.competitors;
    if (!analysis)
        return (
            <p className="rounded-xl border p-4 text-sm text-muted-foreground">
                {c.analysisEmpty}
            </p>
        );
    const { facts, crawl, ai } = analysis;
    const featured = products.filter((p) => p.evidence?.promotion).slice(0, 6);
    const best = products.filter((p) => p.evidence?.best_seller).slice(0, 6);

    return (
        <div className="space-y-4">
            <div className="text-xs text-muted-foreground">
                {c.lastChecked}: {relative(crawl.observed_at)}
            </div>
            <div className="grid grid-cols-3 gap-2">
                {[
                    [c.products, facts.products],
                    [c.bestSeller, facts.best_sellers],
                    [c.featured, facts.featured],
                ].map(([label, count]) => (
                    <div
                        key={label}
                        className="rounded-xl border bg-muted/50 p-3"
                    >
                        <div className="text-xl font-semibold">
                            {number(Number(count))}
                        </div>
                        <div className="mt-1 text-xs text-muted-foreground">
                            {label}
                        </div>
                    </div>
                ))}
            </div>
            {facts.price_min !== null && facts.price_max !== null && (
                <div className="rounded-xl border p-3 text-sm">
                    {c.priceRange}:{' '}
                    <span className="font-semibold" dir="ltr">
                        {number(facts.price_min)} – {number(facts.price_max)}{' '}
                        {facts.currency}
                    </span>
                </div>
            )}
            <div className="space-y-2 rounded-xl bg-muted/40 p-3 text-xs leading-relaxed">
                <p className="flex gap-2">
                    <Info className="mt-0.5 size-4 shrink-0" />
                    {c.evidenceNote}
                </p>
                <p>
                    {facts.published_sales > 0
                        ? c.salesPublished
                        : c.salesUnavailable}
                </p>
                {crawl.limited && <p>{c.sampleNote}</p>}
                {crawl.warnings.length > 0 && <p>{c.partialCrawl}</p>}
            </div>
            {[
                [c.sellerClaim, best],
                [c.featured, featured],
            ].map(([label, entries]) => (
                <section key={String(label)} className="space-y-2">
                    <h3 className="text-sm font-semibold">{String(label)}</h3>
                    {(entries as Product[]).length === 0 ? (
                        <p className="text-xs text-muted-foreground">
                            {c.noEvidence}
                        </p>
                    ) : (
                        (entries as Product[]).map((p) => (
                            <div key={p.id} className="rounded-xl border p-3">
                                <a
                                    href={p.url ?? undefined}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="flex items-start justify-between gap-2 text-sm"
                                >
                                    <span>{p.name}</span>
                                    <ArrowUpRight className="size-4 shrink-0" />
                                </a>
                                <div className="mt-1 text-xs text-muted-foreground">
                                    {p.price === null
                                        ? '—'
                                        : `${number(p.price)} ${p.evidence?.currency ?? ''}`}
                                </div>
                                <EvidenceLinks evidence={p.evidence} />
                            </div>
                        ))
                    )}
                </section>
            ))}
            <section className="space-y-3 rounded-xl border p-4">
                <h3 className="flex items-center gap-2 font-semibold">
                    {c.geminiAnalysis}
                    {ai.status === 'completed' && (
                        <CheckCircle2 className="size-4 text-success" />
                    )}
                </h3>
                {ai.status === 'completed' ? (
                    <>
                        <p className="text-sm leading-relaxed">{ai.summary}</p>
                        <p className="text-xs text-muted-foreground">
                            {c.hypotheses}
                        </p>
                        {ai.insights.map((insight, i) => (
                            <div
                                key={i}
                                className="space-y-1 border-t pt-3 text-sm"
                            >
                                <h4 className="font-medium">{insight.title}</h4>
                                <p>{insight.description}</p>
                                <div className="flex flex-wrap gap-2 text-xs">
                                    {insight.product_ids.map((id) => {
                                        const product = products.find(
                                            (p) => p.id === id,
                                        );
                                        return product?.url ? (
                                            <a
                                                key={id}
                                                href={product.url}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="text-primary underline"
                                            >
                                                {product.name}
                                            </a>
                                        ) : null;
                                    })}
                                </div>
                            </div>
                        ))}
                    </>
                ) : (
                    <p className="text-sm leading-relaxed text-muted-foreground">
                        {ai.status === 'not_configured'
                            ? c.aiNotConfigured
                            : c.aiUnavailable}
                    </p>
                )}
            </section>
            <details className="rounded-xl border p-3 text-xs">
                <summary className="cursor-pointer font-medium">
                    {c.sources} ({crawl.sources.length})
                </summary>
                <ul className="mt-3 space-y-2" dir="ltr">
                    {crawl.sources.map((source, i) => (
                        <li key={i} className="break-all">
                            <a
                                href={source.url}
                                target="_blank"
                                rel="noreferrer"
                                className="text-primary underline"
                            >
                                {source.url}
                            </a>{' '}
                            · {source.http_status}
                        </li>
                    ))}
                </ul>
            </details>
        </div>
    );
}

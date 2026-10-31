import {
    ArrowUpRight,
    ChevronLeft,
    ChevronRight,
    ImageIcon,
    Search,
    Sparkles,
    Trophy,
} from 'lucide-react';
import { useRef, useState } from 'react';
import { EmptyState } from '@/components/mojo/empty-state';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';
import type { ProductEvidence } from './competitor-analysis';
import type { CompetitorProduct, ProductFilter } from './competitor-types';

export function EvidenceLinks({
    evidence,
}: {
    evidence: ProductEvidence | null;
}) {
    const { t } = useI18n();
    const c = t.market.competitors;
    if (!evidence) return null;

    return (
        <div className="flex flex-wrap gap-2 text-[11px] leading-relaxed">
            {evidence.best_seller && (
                <a
                    href={evidence.best_seller.url}
                    target="_blank"
                    rel="noreferrer"
                    className="rounded-md bg-primary/8 px-2 py-1 text-primary underline-offset-4 hover:underline"
                >
                    {c.sellerClaim}
                </a>
            )}
            {evidence.promotion && (
                <a
                    href={evidence.promotion.url}
                    target="_blank"
                    rel="noreferrer"
                    className="rounded-md bg-primary/8 px-2 py-1 text-primary underline-offset-4 hover:underline"
                >
                    {c.featured}
                </a>
            )}
            {evidence.published_sales && (
                <a
                    href={evidence.published_sales.url}
                    target="_blank"
                    rel="noreferrer"
                    className="rounded-md bg-primary/8 px-2 py-1 text-primary underline-offset-4 hover:underline"
                >
                    {evidence.published_sales.text}
                </a>
            )}
        </div>
    );
}

export function ProductImage({
    product,
    className,
}: {
    product: CompetitorProduct;
    className?: string;
}) {
    const [failed, setFailed] = useState(false);
    const [loaded, setLoaded] = useState(false);
    return (
        <div
            className={cn(
                'relative flex items-center justify-center overflow-hidden bg-muted/50',
                className,
            )}
        >
            {(!product.image_url || failed || !loaded) && (
                <ImageIcon
                    className="absolute size-8 text-muted-foreground/40"
                    aria-hidden="true"
                />
            )}
            {product.image_url && !failed && (
                <img
                    src={product.image_url}
                    alt=""
                    loading="lazy"
                    onLoad={() => setLoaded(true)}
                    onError={() => setFailed(true)}
                    className={cn(
                        'relative size-full object-contain transition-opacity',
                        loaded ? 'opacity-100' : 'opacity-0',
                    )}
                />
            )}
        </div>
    );
}

export function ProductPrice({ product }: { product: CompetitorProduct }) {
    const { t, number } = useI18n();
    const currency = product.evidence?.currency;
    const unit = currency === 'SAR' ? t.common.sar : currency;
    return (
        <span className="num inline-flex flex-wrap items-baseline gap-2">
            <span className="font-semibold">
                {product.price === null ? (
                    <span className="text-xs font-normal text-muted-foreground">
                        {t.market.competitors.workspace.unknownPrice}
                    </span>
                ) : (
                    <>
                        {number(
                            product.price,
                            Number.isInteger(product.price) ? 0 : 2,
                        )}{' '}
                        <span className="text-xs font-normal">{unit}</span>
                    </>
                )}
            </span>
            {product.price !== null &&
                product.previous_price !== null &&
                product.previous_price > product.price && (
                    <span className="text-xs text-muted-foreground line-through">
                        {number(
                            product.previous_price,
                            Number.isInteger(product.previous_price) ? 0 : 2,
                        )}{' '}
                        {unit}
                    </span>
                )}
        </span>
    );
}

export function ProductCard({ product }: { product: CompetitorProduct }) {
    const { t } = useI18n();
    const w = t.market.competitors.workspace;
    return (
        <article className="group flex min-w-0 flex-col overflow-hidden rounded-2xl border border-border/70 bg-card transition-shadow hover:shadow-md">
            <div className="relative p-2 pb-0">
                {product.url ? (
                    <a
                        href={product.url}
                        target="_blank"
                        rel="noreferrer"
                        aria-label={`${w.openProduct}: ${product.name}`}
                        className="block rounded-xl focus-visible:outline-2 focus-visible:outline-ring"
                    >
                        <ProductImage
                            key={product.image_url}
                            product={product}
                            className="aspect-[4/3] rounded-xl"
                        />
                    </a>
                ) : (
                    <ProductImage
                        key={product.image_url}
                        product={product}
                        className="aspect-[4/3] rounded-xl"
                    />
                )}
                {(product.evidence?.best_seller ||
                    product.evidence?.promotion) && (
                    <span className="pointer-events-none absolute start-4 top-4 flex size-8 items-center justify-center rounded-full bg-card/95 shadow-sm">
                        {product.evidence.best_seller ? (
                            <Trophy className="size-4 text-gold" />
                        ) : (
                            <Sparkles className="size-4 text-info" />
                        )}
                    </span>
                )}
            </div>
            <div className="flex flex-1 flex-col gap-3 p-4">
                {product.category && (
                    <p className="truncate text-xs text-muted-foreground">
                        {product.category}
                    </p>
                )}
                <h3 className="text-sm leading-relaxed font-medium">
                    {product.url ? (
                        <a
                            className="line-clamp-2 hover:text-primary hover:underline"
                            href={product.url}
                            target="_blank"
                            rel="noreferrer"
                            dir="auto"
                        >
                            {product.name}
                        </a>
                    ) : (
                        <span className="line-clamp-2" dir="auto">
                            {product.name}
                        </span>
                    )}
                </h3>
                <div className="mt-auto flex items-center justify-between gap-2">
                    <ProductPrice product={product} />
                    {product.url && (
                        <a
                            href={product.url}
                            target="_blank"
                            rel="noreferrer"
                            className="flex size-8 shrink-0 items-center justify-center rounded-full bg-muted/60 text-muted-foreground hover:bg-primary/10 hover:text-primary"
                            aria-label={`${w.openProduct}: ${product.name}`}
                        >
                            <ArrowUpRight className="size-4 rtl:-scale-x-100" />
                        </a>
                    )}
                </div>
                <EvidenceLinks evidence={product.evidence} />
            </div>
        </article>
    );
}

const PAGE_SIZE = 20;
type Sort = 'recommended' | 'priceLow' | 'priceHigh' | 'newest';

export function CompetitorProducts({
    products,
    filter,
    onFilterChange,
    total,
}: {
    products: CompetitorProduct[];
    filter: ProductFilter;
    onFilterChange: (filter: ProductFilter) => void;
    total?: number;
}) {
    const { t, tr, number, dir } = useI18n();
    const w = t.market.competitors.workspace;
    const [search, setSearch] = useState('');
    const [sort, setSort] = useState<Sort>('recommended');
    const [page, setPage] = useState(1);
    const resultsRef = useRef<HTMLDivElement>(null);
    const changePage = (value: number) => {
        setPage(value);
        resultsRef.current?.scrollIntoView({ block: 'start' });
        resultsRef.current?.focus({ preventScroll: true });
    };
    const filtered = products
        .filter((p) => {
            const matches = `${p.name} ${p.category ?? ''}`
                .toLocaleLowerCase()
                .includes(search.trim().toLocaleLowerCase());
            return (
                matches &&
                (filter === 'all' ||
                    (filter === 'best'
                        ? !!p.evidence?.best_seller
                        : !!p.evidence?.promotion))
            );
        })
        .sort((a, b) => {
            if (sort === 'priceLow' || sort === 'priceHigh') {
                if (a.price === null) return b.price === null ? 0 : 1;
                if (b.price === null) return -1;
                return sort === 'priceLow'
                    ? a.price - b.price
                    : b.price - a.price;
            }
            if (sort === 'newest')
                return (
                    Date.parse(b.first_seen_at ?? '1970-01-01') -
                    Date.parse(a.first_seen_at ?? '1970-01-01')
                );
            return (
                Number(!!b.evidence?.best_seller) -
                Number(!!a.evidence?.best_seller)
            );
        });
    const pages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
    const currentPage = Math.min(page, pages);
    const start = (currentPage - 1) * PAGE_SIZE;
    const filters = [
        { key: 'all' as const, label: w.all, count: products.length },
        {
            key: 'best' as const,
            label: w.best,
            count: products.filter((p) => p.evidence?.best_seller).length,
        },
        {
            key: 'featured' as const,
            label: w.promoted,
            count: products.filter((p) => p.evidence?.promotion).length,
        },
    ];
    const reset = () => {
        setSearch('');
        onFilterChange('all');
        setPage(1);
    };

    if (!products.length)
        return <EmptyState icon={ImageIcon} title={w.noProducts} />;

    return (
        <div className="space-y-5">
            <div className="space-y-4 rounded-2xl border bg-card p-4 sm:p-5">
                <div className="flex flex-col gap-3 sm:flex-row">
                    <div className="relative flex-1">
                        <Search className="pointer-events-none absolute start-3 top-3 size-4 text-muted-foreground" />
                        <Input
                            aria-label={w.search}
                            placeholder={w.search}
                            value={search}
                            onChange={(e) => {
                                setSearch(e.target.value);
                                setPage(1);
                            }}
                            className="h-10 bg-background ps-10"
                        />
                    </div>
                    <Select
                        dir={dir}
                        value={sort}
                        onValueChange={(v) => {
                            setSort(v as Sort);
                            setPage(1);
                        }}
                    >
                        <SelectTrigger
                            className="h-10 w-full bg-background sm:w-52"
                            aria-label={w.sort}
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {(
                                [
                                    'recommended',
                                    'priceLow',
                                    'priceHigh',
                                    'newest',
                                ] as const
                            ).map((s) => (
                                <SelectItem key={s} value={s}>
                                    {w[s]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <div
                    className="flex flex-wrap gap-2"
                    role="group"
                    aria-label={w.products}
                >
                    {filters.map((f) => (
                        <button
                            key={f.key}
                            type="button"
                            aria-pressed={filter === f.key}
                            onClick={() => {
                                onFilterChange(f.key);
                                setPage(1);
                            }}
                            className={cn(
                                'inline-flex min-h-9 items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-medium transition-colors focus-visible:outline-2 focus-visible:outline-ring',
                                filter === f.key
                                    ? 'border-primary/30 bg-primary/10 text-primary'
                                    : 'border-transparent bg-muted/60 text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {f.label}
                            <span className="num opacity-75">
                                {number(f.count)}
                            </span>
                        </button>
                    ))}
                </div>
            </div>
            <div
                ref={resultsRef}
                tabIndex={-1}
                className="flex scroll-mt-20 flex-wrap justify-between gap-2 text-xs text-muted-foreground outline-none"
                aria-live="polite"
            >
                <span>{tr(w.results, { count: number(filtered.length) })}</span>
                {total !== undefined && total > products.length && (
                    <span>
                        {tr(w.allLoaded, {
                            shown: number(products.length),
                            total: number(total),
                        })}
                    </span>
                )}
            </div>
            {filtered.length ? (
                <div className="grid grid-cols-1 gap-4 min-[400px]:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                    {filtered.slice(start, start + PAGE_SIZE).map((p) => (
                        <ProductCard key={p.id} product={p} />
                    ))}
                </div>
            ) : (
                <EmptyState
                    icon={Search}
                    title={w.noMatches}
                    description={w.noMatchesHint}
                    action={
                        <Button variant="outline" onClick={reset}>
                            {w.reset}
                        </Button>
                    }
                />
            )}
            {filtered.length > PAGE_SIZE && (
                <nav
                    aria-label={w.products}
                    className="flex flex-wrap items-center justify-between gap-3 border-t pt-4"
                >
                    <span className="text-xs text-muted-foreground">
                        {tr(w.showing, {
                            from: number(start + 1),
                            to: number(
                                Math.min(start + PAGE_SIZE, filtered.length),
                            ),
                            total: number(filtered.length),
                        })}
                    </span>
                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={currentPage === 1}
                            onClick={() => changePage(currentPage - 1)}
                        >
                            <ChevronLeft className="size-4 rtl:rotate-180" />
                            {w.previous}
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={currentPage === pages}
                            onClick={() => changePage(currentPage + 1)}
                        >
                            {w.next}
                            <ChevronRight className="size-4 rtl:rotate-180" />
                        </Button>
                    </div>
                </nav>
            )}
        </div>
    );
}

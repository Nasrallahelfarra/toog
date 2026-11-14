import { Link } from '@inertiajs/react';
import {
    ArrowDownRight,
    ArrowRight,
    ArrowUpRight,
    Check,
    ClipboardCheck,
    Database,
    Gem,
    Info,
    Layers,
    Radar,
    Shapes,
    Sparkles,
    Store as StoreIcon,
    TriangleAlert,
    WandSparkles,
} from 'lucide-react';
import { motion } from 'motion/react';
import type { ReactNode } from 'react';
import { Visual } from '@/components/market/radar/parts';
import { GarmentIcon } from '@/components/studio/designer/garment-icons';
import type { GarmentKey } from '@/components/studio/designer/types';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useCount } from '@/hooks/use-count';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';
import type {
    Board,
    MarketExample,
    MarketSide,
    MineExample,
    MineSide,
    TrendItem,
} from './types';

/** In the catalog, rare (under 5%) or absent: the same rule as the server's gap. */
function presence(mine: MineSide | null): 'has' | 'rare' | 'none' {
    if (!mine || mine.products === 0) return 'none';

    return mine.catalog_pct >= 5 || mine.unit_pct >= 5 ? 'has' : 'rare';
}

/** A colour swatch, the garment drawn, or an icon for the attribute type. */
export function AttrMark({
    item,
    className,
}: {
    item: Pick<TrendItem, 'type' | 'value' | 'hex'>;
    className?: string;
}) {
    if (item.type === 'color') {
        return (
            <span
                className={cn(
                    'size-10 shrink-0 rounded-full shadow-inner ring-1 ring-black/10 dark:ring-white/15',
                    className,
                )}
                style={{ backgroundColor: item.hex ?? 'var(--muted)' }}
            />
        );
    }

    const Icon =
        { pattern: Shapes, material: Layers, style: Gem }[
            item.type as 'pattern' | 'material' | 'style'
        ] ?? Sparkles;

    return (
        <span
            className={cn(
                'flex size-10 shrink-0 items-center justify-center rounded-xl bg-gold-soft text-gold-foreground dark:text-gold',
                className,
            )}
        >
            {item.type === 'garment' ? (
                <GarmentIcon
                    garment={item.value as GarmentKey}
                    className="size-[60%]"
                />
            ) : (
                <Icon className="size-[50%]" />
            )}
        </span>
    );
}

function TypeChip({ type }: { type: TrendItem['type'] }) {
    const { t } = useI18n();

    return (
        <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground">
            {t.market.board.types[type]}
        </span>
    );
}

function Chip({
    children,
    tone = 'muted',
}: {
    children: ReactNode;
    tone?: 'muted' | 'gold' | 'warning' | 'success';
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px]',
                {
                    muted: 'bg-muted text-muted-foreground',
                    gold: 'bg-gold-soft font-medium text-gold-foreground dark:text-gold',
                    warning: 'bg-warning-soft text-warning',
                    success: 'bg-success-soft text-success',
                }[tone],
            )}
        >
            {children}
        </span>
    );
}

/** +35% / −28%: the change in the value's share of the store's units. */
export function ChangePill({
    change,
    className,
}: {
    change: number | null;
    className?: string;
}) {
    const { number } = useI18n();
    if (change === null) return null;
    const up = change >= 0;
    const Arrow = up ? ArrowUpRight : ArrowDownRight;

    return (
        <span
            className={cn(
                'inline-flex items-center gap-0.5 rounded-md px-1.5 py-0.5 text-xs font-semibold',
                up
                    ? 'bg-success-soft text-success'
                    : 'bg-warning-soft text-warning',
                className,
            )}
        >
            <Arrow className="size-3.5" />
            <bdi dir="ltr" className="num">
                {up ? '+' : '−'}
                {number(Math.abs(change))}%
            </bdi>
        </span>
    );
}

function ItemHead({ item, aside }: { item: TrendItem; aside?: ReactNode }) {
    return (
        <div className="flex items-start gap-3">
            <AttrMark item={item} />
            <div className="min-w-0 flex-1">
                <h3 className="truncate text-[15px] leading-tight font-semibold">
                    {item.label}
                </h3>
                <div className="mt-1">
                    <TypeChip type={item.type} />
                </div>
            </div>
            {aside}
        </div>
    );
}

/** The value's share at competitors against its share of your catalog. */
function ShareBars({
    market,
    mine,
}: {
    market: MarketSide | null;
    mine: MineSide | null;
}) {
    const { t, percent } = useI18n();
    const b = t.market.board;
    const rows = [
        { label: b.inMarket, pct: market?.pct ?? null, bar: 'bg-gold' },
        { label: b.inCatalog, pct: mine?.catalog_pct ?? 0, bar: 'bg-primary' },
    ];

    return (
        <div className="space-y-2.5">
            {rows.map((row, i) => (
                <div key={row.label} className="space-y-1">
                    <div className="flex items-baseline justify-between gap-2 text-xs">
                        <span className="text-muted-foreground">
                            {row.label}
                        </span>
                        <span className="num font-semibold">
                            {row.pct === null ? '—' : percent(row.pct)}
                        </span>
                    </div>
                    <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                        <motion.div
                            className={cn('h-full rounded-full', row.bar)}
                            initial={{ width: 0 }}
                            animate={{
                                width: `${Math.min(100, Math.max(row.pct ?? 0, row.pct ? 2 : 0))}%`,
                            }}
                            transition={{ duration: 0.8, delay: i * 0.12 }}
                        />
                    </div>
                </div>
            ))}
        </div>
    );
}

function MarketChips({
    market,
    withBrands = true,
}: {
    market: MarketSide;
    withBrands?: boolean;
}) {
    const { t, tr, number } = useI18n();
    const count = useCount();
    const b = t.market.board;

    return (
        <div className="flex flex-wrap gap-1.5">
            {withBrands && market.brands === 1 && (
                <Tooltip>
                    <TooltipTrigger asChild>
                        <span>
                            <Chip tone="warning">
                                <Info className="size-3" />
                                {b.oneBrand}
                            </Chip>
                        </span>
                    </TooltipTrigger>
                    <TooltipContent>{b.oneBrandHint}</TooltipContent>
                </Tooltip>
            )}
            {withBrands && market.brands > 1 && (
                <Chip>
                    {tr(b.atBrands, {
                        brands: count(market.brands, b.count.competitors),
                    })}
                </Chip>
            )}
            {market.demand > 0 && (
                <Chip tone="gold">
                    {tr(b.demand, { n: number(market.demand) })}
                </Chip>
            )}
            {market.post_lift !== null && market.post_lift > 1 && (
                <Chip tone="gold">
                    {tr(b.postLift, { lift: market.post_lift })}
                </Chip>
            )}
            {market.new > 0 && (
                <Chip tone="success">
                    {tr(b.newArrivals, { n: number(market.new) })}
                </Chip>
            )}
            {market.crowded && <Chip tone="warning">{b.crowded}</Chip>}
        </div>
    );
}

function MarketExamples({ examples }: { examples: MarketExample[] }) {
    const { t, tr, number } = useI18n();
    const b = t.market.board;

    return (
        <div className="space-y-1.5">
            <p className="text-[11px] font-medium text-muted-foreground">
                {b.competitorExamples}
            </p>
            <div className="grid grid-cols-3 gap-2">
                {examples.map((e) => {
                    const body = (
                        <>
                            <Visual
                                src={e.image_url}
                                garment={e.garment}
                                alt={e.name}
                                className="aspect-[3/4] rounded-lg"
                            />
                            <div className="mt-1 truncate text-[11px] text-muted-foreground">
                                {e.competitor}
                            </div>
                            {(e.published_sales || e.best_seller) && (
                                <div className="truncate text-[11px] font-medium text-gold-foreground dark:text-gold">
                                    {e.published_sales
                                        ? tr(b.purchases, {
                                              n: number(e.published_sales),
                                          })
                                        : b.bestSeller}
                                </div>
                            )}
                        </>
                    );

                    return e.url ? (
                        <a
                            key={e.id}
                            href={e.url}
                            target="_blank"
                            rel="noreferrer noopener"
                            title={e.name}
                            className="group block min-w-0"
                        >
                            {body}
                        </a>
                    ) : (
                        <div
                            key={e.id}
                            title={e.name}
                            className="group min-w-0"
                        >
                            {body}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

function MineExamples({ examples }: { examples: MineExample[] }) {
    const { t } = useI18n();
    const count = useCount();
    const b = t.market.board;

    return (
        <div className="space-y-1.5">
            <p className="text-[11px] font-medium text-muted-foreground">
                {b.topSellers}
            </p>
            <ul className="space-y-1.5">
                {examples.map((e) => (
                    <li key={e.id} className="flex items-center gap-2.5">
                        <Visual
                            src={e.image_url}
                            garment={e.garment}
                            alt={e.name}
                            className="size-9 shrink-0 rounded-md"
                        />
                        <span className="min-w-0 flex-1 truncate text-xs">
                            {e.name}
                        </span>
                        <span className="num shrink-0 text-xs text-muted-foreground">
                            {count(e.units, b.count.units)}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function ItemActions({ item, primary }: { item: TrendItem; primary: string }) {
    const { t } = useI18n();

    return (
        <div className="mt-auto flex flex-wrap gap-2">
            <Button size="sm" variant="gold" asChild>
                <Link href={item.design_href}>
                    <WandSparkles className="size-4" />
                    {primary}
                </Link>
            </Button>
            {item.evaluate_href && (
                <Button size="sm" variant="outline" asChild>
                    <Link href={item.evaluate_href}>
                        <ClipboardCheck className="size-4" />
                        {t.market.board.evaluate}
                    </Link>
                </Button>
            )}
        </div>
    );
}

const cardClass =
    'h-full rounded-2xl border border-border/70 bg-card p-5 shadow-[0_1px_2px_rgba(16,24,40,0.04)] transition-shadow hover:shadow-md';

/**
 * A card's facts and its pictures: stacked in a grid cell, side by side
 * when the card spans the whole row.
 */
function CardLayout({
    wide,
    facts,
    media,
}: {
    wide: boolean;
    facts: ReactNode;
    media: ReactNode;
}) {
    return (
        <article
            className={cn(
                cardClass,
                wide
                    ? 'grid gap-5 md:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)] md:gap-8'
                    : 'flex flex-col gap-4',
            )}
        >
            <div className="flex min-w-0 flex-col gap-4">{facts}</div>
            <div className={cn('min-w-0', !wide && 'flex flex-1 flex-col')}>
                {media}
            </div>
        </article>
    );
}

/** Strong at competitors, rare or absent in the store's catalog. */
export function OpportunityCard({
    item,
    wide = false,
}: {
    item: TrendItem;
    wide?: boolean;
}) {
    const { t } = useI18n();
    const b = t.market.board;
    const actions = <ItemActions item={item} primary={b.design} />;

    return (
        <CardLayout
            wide={wide}
            facts={
                <>
                    <ItemHead item={item} />
                    <ShareBars market={item.market} mine={item.mine} />
                    {item.market && <MarketChips market={item.market} />}
                    {wide && actions}
                </>
            }
            media={
                <>
                    {!!item.market?.examples.length && (
                        <MarketExamples examples={item.market.examples} />
                    )}
                    {!wide && <div className="mt-auto pt-4">{actions}</div>}
                </>
            }
        />
    );
}

/** Strong at competitors and rising in the store's own sales. */
export function FocusCard({
    item,
    wide = false,
}: {
    item: TrendItem;
    wide?: boolean;
}) {
    const { t, tr, percent } = useI18n();
    const count = useCount();
    const b = t.market.board;
    const market = item.market;
    const mine = item.mine;

    const actions = <ItemActions item={item} primary={b.designMore} />;

    return (
        <CardLayout
            wide={wide}
            facts={
                <>
                    <ItemHead item={item} />
                    <div className="grid grid-cols-2 gap-2">
                        <div className="rounded-xl bg-gold-soft/60 p-3">
                            <div className="text-[11px] text-muted-foreground">
                                {b.inMarket}
                            </div>
                            <div className="num mt-1 text-xl font-semibold">
                                {market?.pct !== null &&
                                market?.pct !== undefined
                                    ? percent(market.pct)
                                    : market?.post_lift
                                      ? `${market.post_lift}×`
                                      : '—'}
                            </div>
                            {market && market.brands > 0 && (
                                <div className="mt-0.5 truncate text-[11px] text-muted-foreground">
                                    {tr(b.atBrands, {
                                        brands: count(
                                            market.brands,
                                            b.count.competitors,
                                        ),
                                    })}
                                </div>
                            )}
                        </div>
                        <div className="rounded-xl bg-primary/6 p-3 dark:bg-primary/15">
                            <div className="text-[11px] text-muted-foreground">
                                {b.lastMonths}
                            </div>
                            <div className="num mt-1 text-xl font-semibold">
                                {count(mine?.units ?? 0, b.count.units)}
                            </div>
                            <ChangePill
                                change={mine?.change_pct ?? null}
                                className="mt-1"
                            />
                        </div>
                    </div>
                    {market && (
                        <MarketChips market={market} withBrands={false} />
                    )}
                    {wide && actions}
                </>
            }
            media={
                <>
                    {!!mine?.examples.length && (
                        <MineExamples examples={mine.examples} />
                    )}
                    {wide && !!market?.examples.length && (
                        <div className="mt-4">
                            <MarketExamples examples={market.examples} />
                        </div>
                    )}
                    {!wide && <div className="mt-auto pt-4">{actions}</div>}
                </>
            }
        />
    );
}

/** A value rising or cooling in the store's own sales. */
export function MoverRow({
    item,
    kind,
    hasMarket,
}: {
    item: TrendItem;
    kind: 'rising' | 'cooling';
    hasMarket: boolean;
}) {
    const { t, tr, number } = useI18n();
    const count = useCount();
    const b = t.market.board;
    const mine = item.mine!;
    const strong = item.strong;

    return (
        <li className="flex flex-col gap-3 rounded-xl border border-border/60 p-3 sm:flex-row sm:items-center">
            <div className="flex min-w-0 flex-1 items-start gap-3">
                <AttrMark item={item} className="size-9" />
                <div className="min-w-0 flex-1 space-y-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="font-medium">{item.label}</span>
                        <TypeChip type={item.type} />
                        <ChangePill change={mine.change_pct} />
                    </div>
                    <div className="flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-muted-foreground">
                        <span>
                            {b.lastMonths}:{' '}
                            <span className="num font-medium text-foreground">
                                {number(mine.units)}
                            </span>
                        </span>
                        <span>
                            {b.monthsBefore}:{' '}
                            <span className="num">
                                {number(mine.units_before)}
                            </span>
                        </span>
                        <span>
                            {tr(b.catalogLine, {
                                products: count(
                                    mine.products,
                                    b.count.products,
                                ),
                                inStock: number(mine.in_stock),
                            })}
                        </span>
                    </div>
                    {kind === 'cooling' ? (
                        <p className="text-[11px] text-muted-foreground">
                            {tr(b.inStock, {
                                units: count(mine.stock, b.count.units),
                            })}
                            {' · '}
                            {strong ? b.coolingMarket : b.coolingAlone}
                        </p>
                    ) : (
                        hasMarket && (
                            <p
                                className={cn(
                                    'text-[11px]',
                                    strong
                                        ? 'font-medium text-gold-foreground dark:text-gold'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {strong ? b.alsoMarket : b.notMarket}
                            </p>
                        )
                    )}
                </div>
            </div>
            <div className="flex shrink-0 items-center gap-2 ps-12 sm:ps-0">
                <div className="flex -space-x-2 rtl:space-x-reverse">
                    {mine.examples.slice(0, 3).map((e) => (
                        <Visual
                            key={e.id}
                            src={e.image_url}
                            garment={e.garment}
                            alt={e.name}
                            className="size-8 rounded-full ring-2 ring-card"
                        />
                    ))}
                </div>
                {kind === 'rising' && (
                    <Button size="sm" variant="ghost" asChild>
                        <Link href={item.design_href}>
                            <WandSparkles className="size-4" />
                            {b.designMore}
                        </Link>
                    </Button>
                )}
            </div>
        </li>
    );
}

/** What competitors carry most, and whether the store carries it. */
export function PulseList({ items }: { items: TrendItem[] }) {
    const { t, tr, percent } = useI18n();
    const count = useCount();
    const b = t.market.board;

    return (
        <ol className="space-y-3.5">
            {items.map((item, i) => {
                const state = presence(item.mine);
                const pct = item.market?.pct ?? null;

                return (
                    <li key={item.key} className="flex items-center gap-3">
                        <span className="num w-4 shrink-0 text-center text-xs text-muted-foreground">
                            {i + 1}
                        </span>
                        <AttrMark item={item} className="size-8" />
                        <div className="min-w-0 flex-1">
                            <div className="flex items-baseline justify-between gap-2 text-sm">
                                <span className="truncate font-medium">
                                    {item.label}
                                </span>
                                <span className="num shrink-0 text-xs font-semibold">
                                    {pct !== null
                                        ? percent(pct)
                                        : item.market?.post_lift
                                          ? `${item.market.post_lift}×`
                                          : '—'}
                                </span>
                            </div>
                            <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-muted">
                                <motion.div
                                    className="h-full rounded-full bg-gold"
                                    initial={{ width: 0 }}
                                    animate={{
                                        width: `${Math.min(100, pct ?? 0)}%`,
                                    }}
                                    transition={{
                                        duration: 0.8,
                                        delay: i * 0.05,
                                    }}
                                />
                            </div>
                            <div className="mt-1 flex items-center justify-between gap-2 text-[11px] text-muted-foreground">
                                <span className="truncate">
                                    {b.types[item.type]}
                                    {item.market && item.market.brands > 0
                                        ? ` · ${tr(b.atBrands, { brands: count(item.market.brands, b.count.competitors) })}`
                                        : ''}
                                </span>
                                <span
                                    className={cn(
                                        'inline-flex shrink-0 items-center gap-1',
                                        state === 'has' && 'text-success',
                                        state === 'none' &&
                                            'font-medium text-gold-foreground dark:text-gold',
                                    )}
                                >
                                    {state === 'has' && (
                                        <Check className="size-3" />
                                    )}
                                    {state === 'has'
                                        ? b.pulse.youHave
                                        : state === 'rare'
                                          ? b.pulse.youRare
                                          : b.pulse.youDont}
                                </span>
                            </div>
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}

/** Where the reading comes from, so every number can be traced. */
export function SourcesBar({ board }: { board: Board }) {
    const { t, tr, number, date, relative } = useI18n();
    const count = useCount();
    const b = t.market.board.sources;
    const cb = t.market.board.count;
    const c = board.coverage;

    return (
        <div className="space-y-3">
            {board.basis !== 'live' && (
                <div className="flex flex-wrap items-center gap-3 rounded-xl border border-warning/30 bg-warning-soft px-4 py-3 text-sm text-warning">
                    <TriangleAlert className="size-4 shrink-0" />
                    <span className="min-w-0 flex-1">
                        {board.basis === 'sample' ? b.sample : b.none}
                    </span>
                    <Button size="sm" variant="outline" asChild>
                        <Link href="/store/competitors">{b.manage}</Link>
                    </Button>
                </div>
            )}
            <div className="grid gap-3 rounded-2xl border border-border/70 bg-card p-4 text-xs md:grid-cols-[1fr_1fr_auto] md:items-start">
                <div className="flex gap-2.5">
                    <Radar className="mt-0.5 size-4 shrink-0 text-gold-foreground dark:text-gold" />
                    <div className="space-y-0.5">
                        <div className="font-medium text-foreground">
                            {b.market}
                        </div>
                        <p className="text-muted-foreground">
                            {tr(b.marketLine, {
                                products: count(c.products, cb.products),
                                brands: count(c.brands, cb.competitors),
                                posts: count(c.posts, cb.posts),
                            })}
                        </p>
                        {c.demand > 0 && (
                            <p className="text-muted-foreground">
                                {tr(b.demand, { n: number(c.demand) })}
                            </p>
                        )}
                        {c.excluded > 0 && (
                            <p className="text-muted-foreground">
                                {tr(b.excluded, {
                                    n: count(c.excluded, cb.products),
                                })}
                            </p>
                        )}
                        {c.scanned_at && (
                            <p className="text-muted-foreground/80">
                                {tr(b.scanned, {
                                    time: relative(c.scanned_at),
                                })}
                            </p>
                        )}
                    </div>
                </div>
                <div className="flex gap-2.5">
                    <StoreIcon className="mt-0.5 size-4 shrink-0 text-primary" />
                    <div className="space-y-0.5">
                        <div className="font-medium text-foreground">
                            {b.mine}
                        </div>
                        <p className="text-muted-foreground">
                            {c.units > 0
                                ? tr(b.mineLine, {
                                      units: count(c.units, cb.units),
                                      from: date(board.window.from, {
                                          month: 'short',
                                      }),
                                      to: date(board.window.to, {
                                          month: 'short',
                                          year: 'numeric',
                                      }),
                                      beforeFrom: date(
                                          board.window.before_from,
                                          { month: 'short' },
                                      ),
                                      beforeTo: date(board.window.before_to, {
                                          month: 'short',
                                          year: 'numeric',
                                      }),
                                  })
                                : b.noSales}
                        </p>
                    </div>
                </div>
                <Popover>
                    <PopoverTrigger className="inline-flex cursor-pointer items-center gap-1 self-start text-muted-foreground hover:text-foreground">
                        <Database className="size-3.5" />
                        {b.method}
                    </PopoverTrigger>
                    <PopoverContent className="w-80 text-xs leading-relaxed">
                        {b.methodBody}
                    </PopoverContent>
                </Popover>
            </div>
        </div>
    );
}

/** Engagement lives on the competitor radar; this card points there. */
export function RadarLink({ board }: { board: Board }) {
    const { t, tr } = useI18n();
    const count = useCount();
    const b = t.market.board.radar;
    const top = board.top_post;

    return (
        <Link
            href="/store/competitors"
            className="group flex flex-col gap-4 rounded-2xl border border-border/70 bg-card p-4 shadow-[0_1px_2px_rgba(16,24,40,0.04)] transition-shadow hover:shadow-md sm:flex-row sm:items-center xl:flex-col xl:items-stretch"
        >
            {top && (
                <Visual
                    src={top.image_url}
                    garment={top.garment}
                    alt={top.competitor}
                    className="aspect-[4/3] w-full shrink-0 rounded-xl sm:aspect-square sm:size-24 xl:aspect-[16/10] xl:size-auto xl:w-full"
                />
            )}
            <div className="min-w-0 flex-1 space-y-1.5">
                <h3 className="flex items-center gap-2 text-[15px] font-semibold">
                    <Radar className="size-4 text-gold-foreground dark:text-gold" />
                    {b.title}
                </h3>
                <p className="text-xs leading-relaxed text-muted-foreground">
                    {b.body}
                </p>
                <div className="flex flex-wrap gap-1.5">
                    {board.coverage.hot_posts > 0 && (
                        <Chip>
                            {tr(b.hot, {
                                n: count(
                                    board.coverage.hot_posts,
                                    t.market.board.count.posts,
                                ),
                            })}
                        </Chip>
                    )}
                    {top && top.lift > 1 && (
                        <Chip tone="gold">
                            {tr(b.top, {
                                lift: top.lift,
                                competitor: top.competitor,
                            })}
                        </Chip>
                    )}
                </div>
            </div>
            <span className="inline-flex shrink-0 items-center gap-1.5 text-sm font-medium text-primary">
                {b.open}
                <ArrowRight className="size-4 transition-transform group-hover:translate-x-0.5 rtl:rotate-180 rtl:group-hover:-translate-x-0.5" />
            </span>
        </Link>
    );
}

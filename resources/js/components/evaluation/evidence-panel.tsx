import { Database, TrendingDown, TrendingUp } from 'lucide-react';
import { motion } from 'motion/react';
import { ColorChips } from '@/components/mojo/color-chips';
import { SectionCard } from '@/components/mojo/section-card';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';
import type { Evidence } from './types';

/**
 * The data behind the score: which best sellers and slow movers it
 * resembles, matched trends, price band, market and self saturation.
 */
export function EvidencePanel({ evidence, price }: { evidence: Evidence; price: number | null }) {
    const { t, number, money, locale } = useI18n();
    const s = t.evaluation.show;

    return (
        <SectionCard title={s.evidence} icon={Database}>
            <div className="grid gap-6 md:grid-cols-2">
                <SimilarList title={s.similarWinners} items={evidence.similar_winners ?? []} tone="success" icon={TrendingUp} />
                <SimilarList title={s.similarStagnant} items={evidence.similar_stagnant ?? []} tone="danger" icon={TrendingDown} />

                <div className="space-y-2.5">
                    <h4 className="text-muted-foreground text-xs font-medium">{s.matchedTrends}</h4>
                    {evidence.matched_trends?.length ? (
                        <div className="flex flex-wrap gap-1.5">
                            {evidence.matched_trends.map((tr_, i) => (
                                <span
                                    key={i}
                                    className={cn(
                                        'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs',
                                        tr_.status === 'rising' ? 'bg-success-soft text-success' : tr_.status === 'declining' ? 'bg-danger-soft text-danger' : 'bg-muted',
                                    )}
                                >
                                    {locale === 'ar' ? tr_.name_ar : tr_.name_en}
                                    <span className="num opacity-70">{tr_.momentum}</span>
                                </span>
                            ))}
                        </div>
                    ) : (
                        <p className="text-muted-foreground text-sm">{s.noTrends}</p>
                    )}
                    {evidence.saudi_palette_matches?.length ? (
                        <div className="space-y-1.5 pt-2">
                            <h4 className="text-muted-foreground text-xs font-medium">{s.palette}</h4>
                            <ColorChips colors={evidence.saudi_palette_matches} />
                        </div>
                    ) : null}
                </div>

                <div className="space-y-4">
                    {evidence.price_band && (
                        <div className="space-y-2">
                            <h4 className="text-muted-foreground text-xs font-medium">{s.priceBand}</h4>
                            <PriceBand band={evidence.price_band} price={price} />
                            <p className="text-xs">
                                {money(evidence.price_band.low)} – {money(evidence.price_band.high)}
                            </p>
                        </div>
                    )}
                    <div className="grid grid-cols-2 gap-3">
                        {evidence.competitor_overlap && (
                            <Stat label={s.competitorOverlap} value={`${number(evidence.competitor_overlap.similar)} / ${number(evidence.competitor_overlap.total)}`} />
                        )}
                        {evidence.self_repetition !== undefined && <Stat label={s.selfRepetition} value={number(evidence.self_repetition)} />}
                    </div>
                </div>
            </div>
        </SectionCard>
    );
}

function SimilarList({ title, items, tone, icon: Icon }: { title: string; items: { name: string; similarity: number }[]; tone: 'success' | 'danger'; icon: typeof TrendingUp }) {
    return (
        <div className="space-y-2.5">
            <h4 className="text-muted-foreground flex items-center gap-1.5 text-xs font-medium">
                <Icon className={cn('size-3.5', tone === 'success' ? 'text-success' : 'text-danger')} />
                {title}
            </h4>
            {items.length === 0 ? (
                <p className="text-muted-foreground text-sm">—</p>
            ) : (
                <ul className="space-y-2">
                    {items.map((item, i) => (
                        <li key={i} className="space-y-1">
                            <div className="flex items-baseline justify-between gap-3 text-sm">
                                <span className="truncate">{item.name}</span>
                                <span className="num text-muted-foreground shrink-0 text-xs">{item.similarity}%</span>
                            </div>
                            <div className="bg-muted h-1 overflow-hidden rounded-full">
                                <motion.div
                                    className={cn('h-full rounded-full', tone === 'success' ? 'bg-success' : 'bg-danger')}
                                    initial={{ width: 0 }}
                                    animate={{ width: `${item.similarity}%` }}
                                    transition={{ duration: 0.8, delay: 0.2 + i * 0.08 }}
                                />
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function PriceBand({ band, price }: { band: { low: number; median: number; high: number }; price: number | null }) {
    const min = Math.min(band.low, price ?? band.low) * 0.75;
    const max = Math.max(band.high, price ?? band.high) * 1.2;
    const pos = (v: number) => ((v - min) / (max - min)) * 100;
    const inside = price !== null && price >= band.low && price <= band.high;

    return (
        <div className="relative h-8">
            <div className="bg-muted absolute inset-x-0 top-3 h-2 rounded-full" />
            <motion.div
                className="bg-success/40 absolute top-3 h-2 rounded-full"
                style={{ insetInlineStart: `${pos(band.low)}%` }}
                initial={{ width: 0 }}
                animate={{ width: `${pos(band.high) - pos(band.low)}%` }}
                transition={{ duration: 0.8 }}
            />
            <div className="bg-success absolute top-2.5 h-3 w-0.5 rounded" style={{ insetInlineStart: `${pos(band.median)}%` }} />
            {price !== null && (
                <motion.div
                    className="absolute top-0 -translate-x-1/2 rtl:translate-x-1/2"
                    initial={{ opacity: 0, y: -4 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ delay: 0.6 }}
                    style={{ insetInlineStart: `${pos(price)}%` }}
                >
                    <div className={cn('size-4 rounded-full border-2 border-white shadow ring-2', inside ? 'bg-success ring-success/30' : 'bg-warning ring-warning/30')} />
                </motion.div>
            )}
        </div>
    );
}

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <div className="bg-muted/50 rounded-xl p-3">
            <div className="num text-lg font-semibold">{value}</div>
            <div className="text-muted-foreground text-[11px] leading-snug">{label}</div>
        </div>
    );
}

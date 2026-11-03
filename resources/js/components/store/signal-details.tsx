import { Loader2, ThumbsDown, ThumbsUp } from 'lucide-react';
import { Meter } from '@/components/mojo/meter';
import { toneClasses } from '@/components/mojo/tone';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

export type SignalKey =
    | 'velocity'
    | 'revenue'
    | 'add_to_cart'
    | 'returns'
    | 'turnover';

export type SignalStats = {
    weight: number;
    lower_is_better: boolean;
    products: number;
    with_sales: number;
    measured: number;
    median: number | null;
    best: { name: string; value: number }[];
    weakest: { name: string; value: number }[];
};

/** What one classification signal measures, and how the store's products spread on it. */
export function SignalDetails({
    signal,
    weight,
    stats,
    onClose,
}: {
    signal: SignalKey | null;
    weight: number;
    stats: SignalStats | undefined;
    onClose: () => void;
}) {
    const { t, tr, number, money, percent, isRtl } = useI18n();
    const p = t.performance;
    // Every signal has the same four texts; returns and turnover add a rule, add-to-cart a no-data note.
    const d: {
        what: string;
        how: string;
        why: string;
        source: string;
        rule?: string;
        noData?: string;
    } | null = signal ? p.signals[signal] : null;

    const format = (value: number | null) => {
        if (value === null) return '—';
        if (signal === 'velocity')
            return tr(p.signalPerMonth, { n: number(value, 1) });
        if (signal === 'revenue') return money(value);
        if (signal === 'turnover') return tr(p.days, { n: number(value) });
        return percent(value, 1);
    };

    return (
        <Sheet
            open={signal !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <SheetContent
                side={isRtl ? 'left' : 'right'}
                className="w-full overflow-y-auto sm:max-w-md"
            >
                {signal && d && (
                    <>
                        <SheetHeader className="space-y-2 border-b pb-4">
                            <span
                                className={cn(
                                    'w-fit rounded-full px-2.5 py-0.5 text-[11px] font-medium',
                                    toneClasses.navy.soft,
                                    toneClasses.navy.text,
                                )}
                            >
                                {tr(p.signalWeight, {
                                    weight: percent(weight),
                                })}
                            </span>
                            <SheetTitle className="text-start text-lg leading-snug">
                                {p.criteria[signal]}
                            </SheetTitle>
                            <SheetDescription className="text-start leading-relaxed">
                                {d.what}
                            </SheetDescription>
                        </SheetHeader>

                        <div className="space-y-6 px-4 pb-6">
                            <section className="space-y-2">
                                <h3 className="text-sm font-semibold">
                                    {p.signalSections.how}
                                </h3>
                                <p className="rounded-xl bg-muted/50 p-3 text-sm leading-relaxed">
                                    {d.how}
                                </p>
                            </section>

                            <section className="space-y-2">
                                <h3 className="text-sm font-semibold">
                                    {p.signalSections.weight}
                                </h3>
                                <Meter
                                    value={weight}
                                    valueLabel={tr(p.signalPoints, {
                                        n: weight,
                                    })}
                                    tone="navy"
                                    height={6}
                                />
                                <p className="text-sm leading-relaxed text-muted-foreground">
                                    {tr(
                                        signal === 'returns' ||
                                            signal === 'turnover'
                                            ? p.signalWeightLower
                                            : p.signalWeightHigher,
                                        { weight },
                                    )}
                                </p>
                            </section>

                            {d.rule && (
                                <p className="rounded-xl bg-warning-soft p-3 text-sm leading-relaxed text-warning">
                                    {d.rule}
                                </p>
                            )}

                            <section className="space-y-2">
                                <h3 className="text-sm font-semibold">
                                    {p.signalSections.why}
                                </h3>
                                <p className="text-sm leading-relaxed text-muted-foreground">
                                    {d.why}
                                </p>
                            </section>

                            <section className="space-y-2">
                                <h3 className="text-sm font-semibold">
                                    {p.signalSections.source}
                                </h3>
                                <p className="text-sm leading-relaxed text-muted-foreground">
                                    {d.source}
                                </p>
                            </section>

                            <section className="space-y-3">
                                <h3 className="text-sm font-semibold">
                                    {p.signalSections.store}
                                </h3>
                                {!stats ? (
                                    <p
                                        role="status"
                                        className="flex items-center gap-2 text-sm text-muted-foreground"
                                    >
                                        <Loader2 className="size-4 animate-spin motion-reduce:animate-none" />
                                        {p.signalLoading}
                                    </p>
                                ) : stats.measured === 0 ? (
                                    <p className="rounded-xl border border-dashed p-3 text-sm leading-relaxed text-muted-foreground">
                                        {d.noData ?? p.signalNoSales}
                                    </p>
                                ) : (
                                    <>
                                        <dl className="grid grid-cols-2 gap-2">
                                            <div className="rounded-xl border border-border/60 p-3">
                                                <dt className="text-[11px] text-muted-foreground">
                                                    {p.signalMedian}
                                                </dt>
                                                <dd className="num mt-0.5 text-sm font-semibold">
                                                    {format(stats.median)}
                                                </dd>
                                            </div>
                                            <div className="rounded-xl border border-border/60 p-3">
                                                <dt className="text-[11px] text-muted-foreground">
                                                    {p.signalCoverage}
                                                </dt>
                                                <dd className="num mt-0.5 text-sm font-semibold">
                                                    {tr(p.signalCoverageValue, {
                                                        n: number(
                                                            stats.measured,
                                                        ),
                                                        total: number(
                                                            stats.products,
                                                        ),
                                                    })}
                                                </dd>
                                            </div>
                                        </dl>
                                        {[
                                            {
                                                title: p.signalBest,
                                                rows: stats.best,
                                                icon: ThumbsUp,
                                                tone: 'text-success',
                                            },
                                            {
                                                title: p.signalWeakest,
                                                rows: stats.weakest,
                                                icon: ThumbsDown,
                                                tone: 'text-danger',
                                            },
                                        ].map((list) => (
                                            <div
                                                key={list.title}
                                                className="space-y-1.5"
                                            >
                                                <h4 className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                                                    <list.icon
                                                        className={cn(
                                                            'size-3.5',
                                                            list.tone,
                                                        )}
                                                    />
                                                    {list.title}
                                                </h4>
                                                <ol className="divide-y rounded-xl border border-border/60">
                                                    {list.rows.map((row, i) => (
                                                        <li
                                                            key={`${row.name}-${i}`}
                                                            className="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                                                        >
                                                            <span className="truncate">
                                                                {row.name}
                                                            </span>
                                                            <span
                                                                className={cn(
                                                                    'num shrink-0 font-semibold',
                                                                    list.tone,
                                                                )}
                                                            >
                                                                {format(
                                                                    row.value,
                                                                )}
                                                            </span>
                                                        </li>
                                                    ))}
                                                </ol>
                                            </div>
                                        ))}
                                    </>
                                )}
                            </section>
                        </div>
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}

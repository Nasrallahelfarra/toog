import { ChevronDown, Gauge } from 'lucide-react';
import { motion } from 'motion/react';
import { useState } from 'react';
import { Meter } from '@/components/mojo/meter';
import { ease } from '@/components/mojo/motion';
import type { Tone } from '@/components/mojo/tone';
import type { MemberRating, RatingGrade, RatingMeta, RatingPartKey, StatusMix, TeamMember } from '@/components/store/team/shared';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useI18n } from '@/hooks/use-i18n';
import { useIsMobile } from '@/hooks/use-mobile';
import { cn } from '@/lib/utils';

const SEGMENTS: { key: keyof StatusMix; fill: string; dot: string }[] = [
    { key: 'moving', fill: 'bg-success', dot: 'bg-success' },
    { key: 'slowing', fill: 'bg-warning', dot: 'bg-warning' },
    { key: 'stagnant', fill: 'bg-danger', dot: 'bg-danger' },
    { key: 'unrated', fill: 'bg-muted-foreground/35', dot: 'bg-muted-foreground/50' },
];

export const PART_ORDER: RatingPartKey[] = ['sales', 'success', 'uploads', 'status'];

const GRADE_CLASSES: Record<RatingGrade | 'pending', string> = {
    excellent: 'bg-success-soft text-success ring-success/25',
    very_good: 'bg-primary/10 text-primary ring-primary/20 dark:bg-primary/20',
    average: 'bg-muted text-foreground/80 ring-border',
    watch: 'bg-warning-soft text-warning ring-warning/30',
    pending: 'bg-muted/50 text-muted-foreground ring-transparent border border-dashed border-muted-foreground/40',
};

/** The tone a 0-100 score reads in, on the same steps as the grades. */
export function scoreTone(score: number): Tone {
    return score >= 80 ? 'success' : score >= 60 ? 'navy' : score >= 40 ? 'neutral' : 'warning';
}

export function mixTotal(mix: StatusMix) {
    return mix.moving + mix.slowing + mix.stagnant + mix.unrated;
}

/** Sort helper: best score first, those still being rated last. */
export function byRating(a: { rating: MemberRating }, b: { rating: MemberRating }) {
    return (b.rating.score ?? -1) - (a.rating.score ?? -1);
}

/**
 * How the products a member holds stand today, as one stacked bar:
 * moving, slowing, stagnant, not classified yet. The counts sit beside it
 * in words, so colour is never the only signal.
 */
export function StatusBar({ mix, size = 'md', className }: { mix: StatusMix; size?: 'sm' | 'md'; className?: string }) {
    const { t, tr, number } = useI18n();
    const h = t.team.health;
    const total = mixTotal(mix);
    const aria = tr(h.aria, {
        moving: number(mix.moving),
        slowing: number(mix.slowing),
        stagnant: number(mix.stagnant),
        unrated: number(mix.unrated),
        total: number(total),
    });

    return (
        <div className={cn(size === 'sm' ? 'min-w-28 space-y-1' : 'space-y-1.5', className)}>
            <div role="img" aria-label={aria} className={cn('bg-muted flex w-full gap-[2px] overflow-hidden rounded-full', size === 'sm' ? 'h-1.5' : 'h-2.5')}>
                {total > 0 &&
                    SEGMENTS.filter((segment) => mix[segment.key] > 0).map((segment, index) => (
                        <Tooltip key={segment.key}>
                            <TooltipTrigger asChild>
                                <motion.span
                                    className={cn('block h-full min-w-[3px]', segment.fill)}
                                    style={{ flexBasis: 0 }}
                                    initial={{ flexGrow: 0 }}
                                    animate={{ flexGrow: mix[segment.key] }}
                                    transition={{ duration: 0.6, delay: index * 0.05, ease }}
                                />
                            </TooltipTrigger>
                            <TooltipContent>{tr(h.segment, { label: h[segment.key], count: number(mix[segment.key]) })}</TooltipContent>
                        </Tooltip>
                    ))}
            </div>
            {total === 0 ? (
                <p className="text-muted-foreground text-xs">{h.none}</p>
            ) : size === 'sm' ? (
                <p className="num text-muted-foreground text-xs whitespace-nowrap">{tr(h.short, { moving: number(mix.moving), total: number(total) })}</p>
            ) : (
                <p className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                    <span className="text-foreground font-medium">{tr(h.summary, { moving: number(mix.moving), total: number(total) })}</span>
                    {SEGMENTS.filter((segment) => segment.key !== 'moving' && mix[segment.key] > 0).map((segment) => (
                        <span key={segment.key} className="text-muted-foreground inline-flex items-center gap-1">
                            <span aria-hidden className={cn('inline-block size-2 rounded-full', segment.dot)} />
                            <span className="num">{number(mix[segment.key])}</span> {h[segment.key]}
                        </span>
                    ))}
                </p>
            )}
        </div>
    );
}

/**
 * The rating as a pill: "72 / 100" and the grade, coloured by the grade.
 * Someone without a score yet reads "being rated", with why in a tooltip
 * (and in words beside it on the large one, for touch screens).
 */
export function RatingBadge({
    rating,
    size = 'sm',
    products,
    plain = false,
    className,
}: {
    rating: MemberRating;
    size?: 'sm' | 'lg';
    /** Products held, for the "needs 5 products" reason. */
    products?: number;
    /** Inside another control (a button row): no tooltip of its own. */
    plain?: boolean;
    className?: string;
}) {
    const { t, tr, number } = useI18n();
    const r = t.team.rating;
    const pill = cn(
        'inline-flex w-fit shrink-0 items-center gap-1.5 rounded-full font-medium whitespace-nowrap ring-1 ring-inset',
        size === 'lg' ? 'px-3 py-1 text-sm' : 'px-2 py-0.5 text-xs',
    );

    if (rating.score === null || rating.grade === null) {
        const reason = rating.reason ? tr(r.reasons[rating.reason], { count: number(products ?? 0) }) : null;
        const badge = (
            <span
                role="img"
                className={cn(pill, GRADE_CLASSES.pending, className)}
                tabIndex={reason && !plain ? 0 : undefined}
                aria-label={reason ? `${r.pending}: ${reason}` : r.pending}
            >
                <span aria-hidden className="bg-muted-foreground/50 inline-block size-1.5 rounded-full" />
                {r.pending}
            </span>
        );

        return (
            <span className={cn('inline-flex flex-wrap items-center gap-x-2 gap-y-1', size === 'sm' && 'contents')}>
                {reason && !plain ? (
                    <Tooltip>
                        <TooltipTrigger asChild>{badge}</TooltipTrigger>
                        <TooltipContent>{reason}</TooltipContent>
                    </Tooltip>
                ) : (
                    badge
                )}
                {size === 'lg' && reason && <span className="text-muted-foreground text-xs font-normal">{reason}</span>}
            </span>
        );
    }

    const grade = r.grades[rating.grade];

    return (
        <span role="img" className={cn(pill, GRADE_CLASSES[rating.grade], className)} aria-label={tr(r.aria, { score: number(rating.score), grade })}>
            <span aria-hidden className="num" dir="ltr">
                <span className="font-semibold">{number(rating.score)}</span>
                <span className="opacity-60"> / 100</span>
            </span>
            <span aria-hidden className="opacity-40">
                ·
            </span>
            <span aria-hidden>{grade}</span>
        </span>
    );
}

/**
 * The one-line formula, and which months it covered when that is not the
 * period on screen (a single month is rated on the three up to it).
 */
export function RatingNote({ meta, length, className }: { meta: RatingMeta; length: number; className?: string }) {
    const { t, tr, percent, number, date } = useI18n();
    const r = t.team.rating;

    return (
        <p className={cn('text-muted-foreground text-xs leading-relaxed', className)}>
            {tr(r.formula, {
                sales: percent(meta.weights.sales),
                success: percent(meta.weights.success),
                uploads: percent(meta.weights.uploads),
                status: percent(meta.weights.status),
            })}
            {meta.months !== length && <> {tr(r.window, { count: number(meta.months), month: date(meta.until, { month: 'long', year: 'numeric' }) })}</>}
        </p>
    );
}

/**
 * The four parts behind a member's rating: each one's weight, its score
 * against the typical member (the dashed mark at 50) and the figures it
 * came from. On a phone it folds away behind "how is it worked out?".
 */
export function RatingBreakdown({ member, meta, length }: { member: TeamMember; meta: RatingMeta; length: number }) {
    const { t, tr, number, percent } = useI18n();
    const r = t.team.rating;
    const mobile = useIsMobile();
    const [open, setOpen] = useState(false);
    const rating = member.rating;
    const format = (key: RatingPartKey, value: number) => (key === 'sales' || key === 'uploads' ? number(value, 1) : percent(value * 100));

    const body =
        rating.score === null ? (
            <p className="bg-muted/60 text-muted-foreground rounded-xl px-4 py-3 text-sm">
                {r.pending}
                {rating.reason && <> · {tr(r.reasons[rating.reason], { count: number(member.products) })}</>}
            </p>
        ) : (
            <ul className="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                {PART_ORDER.map((key) => {
                    const part = rating.parts.find((item) => item.key === key);

                    return (
                        <li key={key} className="min-w-0 space-y-1.5">
                            <div className="flex items-baseline justify-between gap-3">
                                <span className="min-w-0">
                                    <span className="block truncate text-sm font-medium">{r.parts[key]}</span>
                                    <span className="text-muted-foreground block truncate text-[11px]">{r.captions[key]}</span>
                                </span>
                                <span className="text-muted-foreground shrink-0 text-[11px]">{tr(r.weight, { weight: percent(meta.weights[key]) })}</span>
                            </div>
                            {part ? (
                                <>
                                    <div className="flex items-center gap-2.5">
                                        <div className="relative flex-1">
                                            <Meter value={part.score} tone={scoreTone(part.score)} height={6} />
                                            <span aria-hidden className="border-foreground/45 absolute -inset-y-1 start-1/2 border-s border-dashed" />
                                        </div>
                                        <span className="num w-8 shrink-0 text-end text-sm font-semibold">{number(part.score)}</span>
                                    </div>
                                    <p className="num text-muted-foreground text-xs">{tr(r.vsTeam, { value: format(key, part.value), team: format(key, part.team) })}</p>
                                </>
                            ) : (
                                <p className="text-muted-foreground text-xs">{r.noData}</p>
                            )}
                        </li>
                    );
                })}
            </ul>
        );

    return (
        <Collapsible open={!mobile || open} onOpenChange={setOpen} className="space-y-3 border-t pt-4">
            {mobile ? (
                <CollapsibleTrigger className="focus-visible:ring-ring flex min-h-11 w-full cursor-pointer items-center justify-between gap-2 rounded-lg text-sm font-semibold focus-visible:ring-2 focus-visible:outline-none">
                    <span className="inline-flex items-center gap-2">
                        <Gauge className="text-muted-foreground size-4" />
                        {r.how}
                    </span>
                    <ChevronDown className={cn('text-muted-foreground size-4 transition-transform', open && 'rotate-180')} />
                </CollapsibleTrigger>
            ) : (
                <h3 className="flex items-center gap-2 text-sm font-semibold">
                    <Gauge className="text-muted-foreground size-4" />
                    {r.how}
                </h3>
            )}
            <CollapsibleContent className="space-y-3">
                {body}
                <RatingNote meta={meta} length={length} />
            </CollapsibleContent>
        </Collapsible>
    );
}

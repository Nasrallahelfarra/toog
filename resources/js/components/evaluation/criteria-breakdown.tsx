import { Info } from 'lucide-react';
import { Meter } from '@/components/mojo/meter';
import type { Tone } from '@/components/mojo/tone';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useI18n } from '@/hooks/use-i18n';
import { CRITERIA } from './types';
import type { Breakdown, CriterionKey, Factor } from './types';

export function criterionTone(score: number): Tone {
    return score >= 70 ? 'success' : score >= 45 ? 'warning' : 'danger';
}

/**
 * The six criteria (FR-3.3) as animated bars with weight and a one-line
 * reason drawn from the evaluation's factors.
 */
export function CriteriaBreakdown({ breakdown, factors }: { breakdown: Breakdown; factors: Factor[] | null }) {
    const { t, tr, field } = useI18n();
    const detail = (key: CriterionKey) => {
        const factor = factors?.find((f) => f.criterion === key);

        return factor ? field(factor, 'detail') : null;
    };

    return (
        <div className="space-y-4">
            {CRITERIA.map((key, i) => {
                const c = breakdown.criteria[key];

                if (!c) {
                    return null;
                }

                return (
                    <Meter
                        key={key}
                        value={c.score}
                        tone={criterionTone(c.score)}
                        delay={0.15 + i * 0.07}
                        label={
                            <span className="flex items-center gap-1.5">
                                <span className="font-medium">{t.evaluation.criteria[key].label}</span>
                                <span className="text-muted-foreground bg-muted rounded-md px-1.5 text-[11px]">{tr(t.evaluation.show.weight, { w: Math.round(c.weight) })}</span>
                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <Info className="text-muted-foreground size-3.5 cursor-help" />
                                    </TooltipTrigger>
                                    <TooltipContent className="max-w-60">{t.evaluation.criteria[key].hint}</TooltipContent>
                                </Tooltip>
                            </span>
                        }
                        hint={detail(key)}
                    />
                );
            })}
        </div>
    );
}

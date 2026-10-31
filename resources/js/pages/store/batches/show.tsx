import { Link, usePoll } from '@inertiajs/react';
import { ArrowRight, Crown, Layers, Loader2 } from 'lucide-react';
import { motion } from 'motion/react';
import type { Factor, Recommendation, Thresholds } from '@/components/evaluation/types';
import { ColorChips } from '@/components/mojo/color-chips';
import { DecisionBadge } from '@/components/mojo/decision-badge';
import { PageBody, PageHeader } from '@/components/mojo/page-header';
import { ScoreRing } from '@/components/mojo/score-ring';
import { SectionCard } from '@/components/mojo/section-card';
import { toneClasses } from '@/components/mojo/tone';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

type Row = {
    id: number;
    name: string;
    category: string | null;
    price: string | null;
    colors: string[];
    image_url: string | null;
    status: 'pending' | 'processing' | 'completed' | 'failed';
    score: number | null;
    recommendation: Recommendation | null;
    expected_score: number | null;
    final_decision: string | null;
    top_factor: Factor | null;
};

type Props = {
    batch: { id: number; name: string; created_at: string };
    evaluations: Row[];
    thresholds: Thresholds;
};

/**
 * FR-3.8: a collection ranked by score, updating live while it runs.
 */
export default function BatchShow({ batch, evaluations, thresholds }: Props) {
    const { t, tr, money, date, field } = useI18n();
    const b = t.evaluation.batch;
    const done = evaluations.filter((e) => e.status === 'completed' || e.status === 'failed').length;
    const running = done < evaluations.length;
    const counts = (['accept', 'modify', 'reject'] as const).map((k) => ({ key: k, n: evaluations.filter((e) => e.recommendation === k).length }));
    let rank = 0;

    return (
        <PageBody className="max-w-5xl">
            {running && <Poll />}
            <PageHeader
                title={batch.name}
                description={`${b.showTitle} · ${date(batch.created_at)}`}
                icon={Layers}
                actions={
                    <Button variant="ghost" asChild>
                        <Link href="/store/evaluations">
                            <ArrowRight className="size-4 ltr:rotate-180" />
                            {t.common.back}
                        </Link>
                    </Button>
                }
            />

            <div className="grid gap-4 md:grid-cols-[2fr_3fr]">
                <SectionCard>
                    <div className="space-y-3">
                        <div className="flex items-center justify-between text-sm">
                            <span className="font-medium">{tr(b.progress, { done, total: evaluations.length })}</span>
                            {running && (
                                <span className="text-muted-foreground flex items-center gap-1.5 text-xs">
                                    <Loader2 className="size-3.5 animate-spin" />
                                    {b.live}
                                </span>
                            )}
                        </div>
                        <Progress value={(done / Math.max(1, evaluations.length)) * 100} indicatorClassName="bg-gold" />
                    </div>
                </SectionCard>
                <SectionCard>
                    <div className="grid grid-cols-3 gap-3 text-center">
                        {counts.map((c) => {
                            const tone = toneClasses[c.key === 'accept' ? 'success' : c.key === 'modify' ? 'warning' : 'danger'];

                            return (
                                <div key={c.key} className={cn('rounded-xl py-3', tone.soft)}>
                                    <div className={cn('num text-2xl font-semibold', tone.text)}>{c.n}</div>
                                    <div className="text-muted-foreground text-xs">{t.common.decision[c.key]}</div>
                                </div>
                            );
                        })}
                    </div>
                </SectionCard>
            </div>

            <SectionCard title={b.ranking} flush>
                <ul className="divide-y divide-border/60">
                    {evaluations.map((e, i) => {
                        const completed = e.status === 'completed';
                        const position = completed ? ++rank : null;

                        return (
                            <motion.li key={e.id} layout initial={{ opacity: 0 }} animate={{ opacity: 1 }} transition={{ delay: i * 0.04 }}>
                                <Link href={`/store/evaluations/${e.id}`} className="hover:bg-muted/40 flex items-center gap-4 px-5 py-3.5 transition-colors">
                                    <span
                                        className={cn(
                                            'num flex size-8 shrink-0 items-center justify-center rounded-full text-sm font-semibold',
                                            position === 1 ? 'bg-gold text-gold-foreground' : 'bg-muted text-muted-foreground',
                                        )}
                                    >
                                        {position === 1 ? <Crown className="size-4" /> : (position ?? '–')}
                                    </span>
                                    <div className="bg-muted size-12 shrink-0 overflow-hidden rounded-lg">
                                        {e.image_url ? (
                                            <img src={e.image_url} alt="" className="size-full object-cover" />
                                        ) : (
                                            <div className="flex size-full items-center justify-center">
                                                <ColorChips colors={e.colors.slice(0, 2)} showLabels={false} />
                                            </div>
                                        )}
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2">
                                            <span className="truncate font-medium">{e.name}</span>
                                            {position === 1 && <span className="text-gold-foreground dark:text-gold text-[11px] font-medium">{b.bestPick}</span>}
                                        </div>
                                        <p className="text-muted-foreground truncate text-xs">
                                            {completed && e.top_factor ? field(e.top_factor, 'detail') : [e.category, e.price ? money(e.price) : null].filter(Boolean).join(' · ')}
                                        </p>
                                    </div>
                                    <div className="flex shrink-0 items-center gap-3">
                                        {completed ? (
                                            <>
                                                <DecisionBadge value={e.recommendation} className="hidden sm:inline-flex" />
                                                <ScoreRing score={e.score} size={48} stroke={5} accept={thresholds.accept} reject={thresholds.reject} />
                                            </>
                                        ) : (
                                            <DecisionBadge value={e.status} />
                                        )}
                                    </div>
                                </Link>
                            </motion.li>
                        );
                    })}
                </ul>
            </SectionCard>
        </PageBody>
    );
}

function Poll() {
    usePoll(2500, { only: ['evaluations'] });

    return null;
}

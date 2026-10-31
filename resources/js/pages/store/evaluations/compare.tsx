import { Link } from '@inertiajs/react';
import { ArrowDownRight, ArrowRight, ArrowUpRight, GitCompareArrows, RefreshCw } from 'lucide-react';
import { motion } from 'motion/react';
import type { ReactNode } from 'react';
import { CRITERIA } from '@/components/evaluation/types';
import type { EvaluationDetail, Thresholds } from '@/components/evaluation/types';
import { ColorChips } from '@/components/mojo/color-chips';
import { DecisionBadge } from '@/components/mojo/decision-badge';
import { EmptyState } from '@/components/mojo/empty-state';
import { PageBody, PageHeader } from '@/components/mojo/page-header';
import { ScoreRing } from '@/components/mojo/score-ring';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/hooks/use-i18n';
import { useVocabulary } from '@/hooks/use-vocabulary';
import { cn } from '@/lib/utils';

type Props = {
    current: number;
    versions: EvaluationDetail[];
    thresholds: Thresholds;
};

/**
 * FR-3.7: every version of a candidate side by side, with score deltas and
 * the attributes that changed between versions highlighted.
 */
export default function EvaluationCompare({ current, versions, thresholds }: Props) {
    const { t, tr, money, date } = useI18n();
    const { label } = useVocabulary();
    const c = t.evaluation.compare;
    const name = versions[0]?.name ?? '';

    const back = (
        <Button variant="ghost" asChild>
            <Link href={`/store/evaluations/${current}`}>
                <ArrowRight className="size-4 ltr:rotate-180" />
                {c.back}
            </Link>
        </Button>
    );

    if (versions.length < 2) {
        return (
            <PageBody className="max-w-4xl">
                <PageHeader title={c.title} icon={GitCompareArrows} actions={back} />
                <div className="bg-card rounded-2xl border">
                    <EmptyState
                        icon={GitCompareArrows}
                        title={c.single}
                        action={
                            <Button asChild>
                                <Link href={`/store/evaluations/create?parent=${current}`}>
                                    <RefreshCw className="size-4" />
                                    {t.evaluation.show.newVersion}
                                </Link>
                            </Button>
                        }
                    />
                </div>
            </PageBody>
        );
    }

    const attributes: { key: string; label: string; render: (v: EvaluationDetail) => ReactNode; value: (v: EvaluationDetail) => string }[] = [
        { key: 'price', label: t.evaluation.form.price, render: (v) => (v.price ? money(v.price) : '—'), value: (v) => String(v.price ?? '') },
        { key: 'colors', label: t.evaluation.form.colors, render: (v) => <ColorChips colors={v.colors} />, value: (v) => [...(v.colors ?? [])].sort().join(',') },
        { key: 'material', label: t.evaluation.form.material, render: (v) => label('materials', v.material), value: (v) => v.material ?? '' },
        { key: 'pattern', label: t.evaluation.form.pattern, render: (v) => label('patterns', v.pattern), value: (v) => v.pattern ?? '' },
        { key: 'style', label: t.evaluation.form.style, render: (v) => label('styles', v.style), value: (v) => v.style ?? '' },
        { key: 'season', label: t.evaluation.form.season, render: (v) => label('seasons', v.season), value: (v) => v.season ?? '' },
    ];

    const first = versions[0].score ?? 0;
    const last = versions[versions.length - 1].score ?? 0;
    const cols = `minmax(150px,1fr) repeat(${versions.length}, minmax(190px,1fr))`;

    return (
        <PageBody>
            <PageHeader title={c.title} description={tr(c.subtitle, { name })} icon={GitCompareArrows} actions={back} />

            <div className="bg-card flex flex-wrap items-center gap-4 rounded-2xl border border-border/70 p-5">
                <span className="text-muted-foreground text-sm">{c.scoreChange}</span>
                <span className="num text-2xl font-semibold">{first}</span>
                <ArrowRight className="text-muted-foreground size-5 rtl:rotate-180" />
                <span className="num text-2xl font-semibold">{last}</span>
                <Delta value={last - first} large />
            </div>

            <div className="bg-card overflow-x-auto rounded-2xl border border-border/70">
                <div className="min-w-fit">
                    {/* Version headers */}
                    <div className="grid border-b" style={{ gridTemplateColumns: cols }}>
                        <div />
                        {versions.map((v, i) => (
                            <motion.div
                                key={v.id}
                                initial={{ opacity: 0, y: 10 }}
                                animate={{ opacity: 1, y: 0 }}
                                transition={{ delay: i * 0.08 }}
                                className={cn('flex flex-col items-center gap-2 p-5 text-center', v.id === current && 'bg-gold-soft/50')}
                            >
                                <Link href={`/store/evaluations/${v.id}`} className="text-sm font-semibold hover:underline">
                                    {tr(c.version, { n: v.version })}
                                </Link>
                                <span className="text-muted-foreground text-[11px]">{date(v.created_at)}</span>
                                <ScoreRing score={v.score} size={84} stroke={7} accept={thresholds.accept} reject={thresholds.reject} />
                                <DecisionBadge value={v.recommendation ?? v.status} />
                                {i > 0 && <Delta value={(v.score ?? 0) - (versions[i - 1].score ?? 0)} />}
                            </motion.div>
                        ))}
                    </div>

                    <Section title={c.attributes} />
                    {attributes.map((a) => (
                        <div key={a.key} className="grid border-b border-border/50 last:border-0" style={{ gridTemplateColumns: cols }}>
                            <div className="text-muted-foreground p-3 text-sm">{a.label}</div>
                            {versions.map((v, i) => {
                                const changed = i > 0 && a.value(v) !== a.value(versions[i - 1]);

                                return (
                                    <div key={v.id} className={cn('flex items-center justify-center p-3 text-center text-sm', changed && 'bg-info-soft/60')}>
                                        <span className="flex flex-col items-center gap-1">
                                            {a.render(v)}
                                            {changed && <span className="text-info text-[10px] font-medium">{c.changed}</span>}
                                        </span>
                                    </div>
                                );
                            })}
                        </div>
                    ))}

                    <Section title={c.criteria} />
                    {CRITERIA.map((key) => (
                        <div key={key} className="grid border-b border-border/50 last:border-0" style={{ gridTemplateColumns: cols }}>
                            <div className="text-muted-foreground p-3 text-sm">{t.evaluation.criteria[key].label}</div>
                            {versions.map((v, i) => {
                                const score = v.breakdown?.criteria[key]?.score ?? null;
                                const prev = i > 0 ? (versions[i - 1].breakdown?.criteria[key]?.score ?? null) : null;

                                return (
                                    <div key={v.id} className="flex items-center justify-center gap-2 p-3">
                                        <span className="num text-sm font-semibold">{score ?? '—'}</span>
                                        {score !== null && prev !== null && <Delta value={score - prev} />}
                                    </div>
                                );
                            })}
                        </div>
                    ))}
                </div>
            </div>
        </PageBody>
    );
}

function Section({ title }: { title: string }) {
    return <div className="bg-muted/50 text-muted-foreground border-b px-3 py-2 text-xs font-semibold">{title}</div>;
}

function Delta({ value, large = false }: { value: number; large?: boolean }) {
    if (value === 0) {
        return <span className={cn('text-muted-foreground num', large ? 'text-sm' : 'text-[11px]')}>±0</span>;
    }

    const up = value > 0;

    return (
        <span
            className={cn(
                'num inline-flex items-center gap-0.5 rounded-md font-semibold',
                up ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger',
                large ? 'px-2 py-1 text-sm' : 'px-1.5 py-0.5 text-[11px]',
            )}
        >
            {up ? <ArrowUpRight className="size-3" /> : <ArrowDownRight className="size-3" />}
            <bdi dir="ltr">
                {up ? '+' : ''}
                {value}
            </bdi>
        </span>
    );
}

import { useForm } from '@inertiajs/react';
import { CheckCircle2, Gavel, PenLine, XCircle } from 'lucide-react';
import { AnimatePresence, motion } from 'motion/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { DecisionBadge } from '@/components/mojo/decision-badge';
import { SectionCard } from '@/components/mojo/section-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';
import type { EvaluationDetail, FinalDecision } from './types';

const expected: Record<string, FinalDecision> = { accept: 'approved', reject: 'rejected', modify: 'needs_changes' };

/**
 * FR-3.9: the platform recommends; an authorised user takes the final
 * decision, and must give a reason when overriding the recommendation.
 */
export function DecisionPanel({ evaluation }: { evaluation: EvaluationDetail }) {
    const { t, tr, dateTime } = useI18n();
    const s = t.evaluation.show;
    const [editing, setEditing] = useState(!evaluation.final_decision);

    const form = useForm<{ decision: FinalDecision | ''; reason: string }>({
        decision: evaluation.final_decision ?? (evaluation.recommendation ? expected[evaluation.recommendation] : ''),
        reason: evaluation.override_reason ?? '',
    });

    const override = !!form.data.decision && !!evaluation.recommendation && expected[evaluation.recommendation] !== form.data.decision;

    const options: { value: FinalDecision; label: string; icon: typeof CheckCircle2; tone: string }[] = [
        { value: 'approved', label: s.approve, icon: CheckCircle2, tone: 'data-[on=true]:border-success data-[on=true]:bg-success-soft data-[on=true]:text-success' },
        { value: 'needs_changes', label: s.needsChanges, icon: PenLine, tone: 'data-[on=true]:border-warning data-[on=true]:bg-warning-soft data-[on=true]:text-warning' },
        { value: 'rejected', label: s.rejectAction, icon: XCircle, tone: 'data-[on=true]:border-danger data-[on=true]:bg-danger-soft data-[on=true]:text-danger' },
    ];

    const save = () =>
        form.post(`/store/evaluations/${evaluation.id}/decide`, {
            preserveScroll: true,
            onSuccess: () => setEditing(false),
        });

    return (
        <SectionCard title={s.finalDecision} description={s.finalHint} icon={Gavel}>
            <AnimatePresence mode="wait" initial={false}>
                {!editing && evaluation.final_decision ? (
                    <motion.div key="view" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="space-y-3">
                        <div className="flex flex-wrap items-center gap-2">
                            <DecisionBadge value={evaluation.final_decision} size="lg" />
                            {evaluation.override_reason && <Badge variant="warning">{s.overrideBadge}</Badge>}
                        </div>
                        {evaluation.override_reason && <p className="bg-muted/60 rounded-xl px-3 py-2 text-sm leading-relaxed">{evaluation.override_reason}</p>}
                        <p className="text-muted-foreground text-xs">{tr(s.decidedBy, { name: evaluation.decider ?? '—', date: dateTime(evaluation.decided_at) })}</p>
                        <Button variant="outline" size="sm" onClick={() => setEditing(true)}>
                            {s.changeDecision}
                        </Button>
                    </motion.div>
                ) : (
                    <motion.div key="edit" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="space-y-3">
                        <div className="grid grid-cols-3 gap-2">
                            {options.map((o) => (
                                <button
                                    key={o.value}
                                    type="button"
                                    data-on={form.data.decision === o.value}
                                    onClick={() => form.setData('decision', o.value)}
                                    className={cn(
                                        'text-muted-foreground hover:border-primary/30 flex cursor-pointer flex-col items-center gap-1.5 rounded-xl border px-2 py-3 text-xs font-medium transition-all',
                                        o.tone,
                                    )}
                                >
                                    <o.icon className="size-5" />
                                    {o.label}
                                </button>
                            ))}
                        </div>

                        <AnimatePresence>
                            {override && (
                                <motion.div initial={{ opacity: 0, height: 0 }} animate={{ opacity: 1, height: 'auto' }} exit={{ opacity: 0, height: 0 }} className="space-y-2 overflow-hidden">
                                    <p className="bg-warning-soft text-warning rounded-lg px-3 py-2 text-xs">{s.overrideHint}</p>
                                    <Textarea rows={3} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} placeholder={s.reasonPlaceholder} />
                                </motion.div>
                            )}
                        </AnimatePresence>
                        <InputError message={form.errors.reason ?? form.errors.decision} />

                        <div className="flex gap-2">
                            <Button className="flex-1" onClick={save} disabled={!form.data.decision || form.processing}>
                                {s.saveDecision}
                            </Button>
                            {evaluation.final_decision && (
                                <Button variant="ghost" onClick={() => setEditing(false)}>
                                    {t.common.cancel}
                                </Button>
                            )}
                        </div>
                    </motion.div>
                )}
            </AnimatePresence>
        </SectionCard>
    );
}

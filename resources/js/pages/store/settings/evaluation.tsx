import { useForm } from '@inertiajs/react';
import { Gauge, RotateCcw, Scale, SlidersHorizontal } from 'lucide-react';
import { motion } from 'motion/react';
import InputError from '@/components/input-error';
import { FadeIn } from '@/components/mojo/motion';
import { SectionCard } from '@/components/mojo/section-card';
import { SettingsShell } from '@/components/store/settings-shell';
import { Button } from '@/components/ui/button';
import { Slider } from '@/components/ui/slider';
import { Spinner } from '@/components/ui/spinner';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

type Criterion = 'success_similarity' | 'trend_fit' | 'saudi_fit' | 'failure_risk' | 'saturation' | 'price_fit';

type Values = {
    accept_threshold: number;
    reject_threshold: number;
    proven_ratio: number;
    weights: Record<Criterion, number>;
};

const CRITERIA: Criterion[] = ['success_similarity', 'trend_fit', 'saudi_fit', 'failure_risk', 'saturation', 'price_fit'];

export default function EvaluationSettings({ values, defaults }: { values: Values; defaults: Values }) {
    const { t, number } = useI18n();
    const s = t.settings.evaluation;
    const form = useForm<Values>(values);
    const totalWeight = CRITERIA.reduce((sum, c) => sum + (form.data.weights[c] ?? 0), 0) || 1;

    const submit = () => form.put('/store/settings/evaluation', { preserveScroll: true });

    return (
        <SettingsShell
            actions={
                <Button variant="ghost" onClick={() => form.setData(defaults)}>
                    <RotateCcw className="size-4" />
                    {s.resetDefaults}
                </Button>
            }
        >
            <FadeIn>
                <SectionCard title={s.thresholdsTitle} description={s.thresholdsHint} icon={Gauge}>
                    <ThresholdBand accept={form.data.accept_threshold} reject={form.data.reject_threshold} />

                    <div className="mt-6 grid gap-6 md:grid-cols-2">
                        <SliderField
                            label={s.rejectThreshold}
                            value={form.data.reject_threshold}
                            min={10}
                            max={85}
                            tone="danger"
                            onChange={(v) => form.setData('reject_threshold', v)}
                            error={form.errors.reject_threshold}
                        />
                        <SliderField
                            label={s.acceptThreshold}
                            value={form.data.accept_threshold}
                            min={40}
                            max={95}
                            tone="success"
                            onChange={(v) => form.setData('accept_threshold', v)}
                            error={form.errors.accept_threshold}
                        />
                    </div>
                </SectionCard>
            </FadeIn>

            <FadeIn delay={0.06}>
                <SectionCard title={s.weightsTitle} description={s.weightsHint} icon={SlidersHorizontal}>
                    <div className="grid gap-x-10 gap-y-6 md:grid-cols-2">
                        {CRITERIA.map((criterion) => {
                            const share = ((form.data.weights[criterion] ?? 0) / totalWeight) * 100;

                            return (
                                <div key={criterion} className="space-y-2.5">
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <div className="text-sm font-medium">{s.criteria[criterion].label}</div>
                                            <div className="text-muted-foreground text-xs">{s.criteria[criterion].hint}</div>
                                        </div>
                                        <motion.span
                                            key={Math.round(share)}
                                            initial={{ scale: 0.85, opacity: 0.4 }}
                                            animate={{ scale: 1, opacity: 1 }}
                                            className="bg-primary/8 text-primary dark:bg-primary/15 num shrink-0 rounded-lg px-2 py-0.5 text-sm font-semibold"
                                        >
                                            {number(share)}%
                                        </motion.span>
                                    </div>
                                    <Slider
                                        value={[form.data.weights[criterion] ?? 0]}
                                        min={0}
                                        max={50}
                                        step={1}
                                        onValueChange={([v]) => form.setData('weights', { ...form.data.weights, [criterion]: v })}
                                    />
                                </div>
                            );
                        })}
                    </div>
                    <InputError message={form.errors.weights} className="mt-4" />

                    <div className="mt-6 flex h-3 overflow-hidden rounded-full">
                        {CRITERIA.map((criterion, i) => (
                            <motion.div
                                key={criterion}
                                className="h-full"
                                style={{ backgroundColor: `var(--chart-${(i % 5) + 1})`, opacity: i >= 5 ? 0.6 : 1 }}
                                animate={{ width: `${((form.data.weights[criterion] ?? 0) / totalWeight) * 100}%` }}
                                transition={{ duration: 0.4 }}
                                title={s.criteria[criterion].label}
                            />
                        ))}
                    </div>
                </SectionCard>
            </FadeIn>

            <FadeIn delay={0.1}>
                <SectionCard title={s.balanceTitle} description={s.balanceHint} icon={Scale}>
                    <div className="space-y-5">
                        <div className="flex h-14 overflow-hidden rounded-2xl text-sm font-semibold">
                            <motion.div
                                className="bg-primary text-primary-foreground flex items-center justify-center gap-2"
                                animate={{ width: `${form.data.proven_ratio}%` }}
                                transition={{ type: 'spring', stiffness: 200, damping: 26 }}
                            >
                                {s.proven} <span className="num">{form.data.proven_ratio}%</span>
                            </motion.div>
                            <motion.div
                                className="bg-gold text-gold-foreground flex items-center justify-center gap-2"
                                animate={{ width: `${100 - form.data.proven_ratio}%` }}
                                transition={{ type: 'spring', stiffness: 200, damping: 26 }}
                            >
                                {s.novel} <span className="num">{100 - form.data.proven_ratio}%</span>
                            </motion.div>
                        </div>
                        <Slider
                            value={[form.data.proven_ratio]}
                            min={40}
                            max={95}
                            step={5}
                            onValueChange={([v]) => form.setData('proven_ratio', v)}
                        />
                        <InputError message={form.errors.proven_ratio} />
                    </div>
                </SectionCard>
            </FadeIn>

            <div className="flex justify-end">
                <Button size="lg" onClick={submit} disabled={form.processing || !form.isDirty}>
                    {form.processing && <Spinner />}
                    {t.common.saveChanges}
                </Button>
            </div>
        </SettingsShell>
    );
}

function ThresholdBand({ accept, reject }: { accept: number; reject: number }) {
    const { t } = useI18n();
    const z = t.settings.evaluation.zones;
    const zones = [
        { label: z.reject, from: 0, to: reject, className: 'bg-danger-soft text-danger' },
        { label: z.modify, from: reject, to: accept, className: 'bg-warning-soft text-warning' },
        { label: z.accept, from: accept, to: 100, className: 'bg-success-soft text-success' },
    ];

    return (
        <div className="space-y-2">
            <div className="flex h-12 overflow-hidden rounded-xl text-xs font-semibold" dir="ltr">
                {zones.map((zone) => (
                    <motion.div
                        key={zone.label}
                        className={cn('flex items-center justify-center overflow-hidden whitespace-nowrap', zone.className)}
                        animate={{ width: `${Math.max(0, zone.to - zone.from)}%` }}
                        transition={{ type: 'spring', stiffness: 220, damping: 28 }}
                    >
                        {zone.label}
                    </motion.div>
                ))}
            </div>
            <div className="text-muted-foreground num relative h-4 text-[11px]" dir="ltr">
                <span className="absolute start-0">0</span>
                <motion.span className="absolute -translate-x-1/2" animate={{ left: `${reject}%` }}>
                    {reject}
                </motion.span>
                <motion.span className="absolute -translate-x-1/2" animate={{ left: `${accept}%` }}>
                    {accept}
                </motion.span>
                <span className="absolute end-0">100</span>
            </div>
        </div>
    );
}

function SliderField({
    label,
    value,
    min,
    max,
    tone,
    onChange,
    error,
}: {
    label: string;
    value: number;
    min: number;
    max: number;
    tone: 'success' | 'danger';
    onChange: (v: number) => void;
    error?: string;
}) {
    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between">
                <span className="text-sm font-medium">{label}</span>
                <span className={cn('num rounded-lg px-2.5 py-0.5 text-lg font-semibold', tone === 'success' ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger')}>
                    {value}
                </span>
            </div>
            <Slider value={[value]} min={min} max={max} step={1} onValueChange={([v]) => onChange(v)} />
            <InputError message={error} />
        </div>
    );
}

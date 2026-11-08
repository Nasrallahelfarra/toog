import { useForm, usePage } from '@inertiajs/react';
import { Check, Eraser, Gem, LayoutTemplate, Ruler, ScanLine, Shapes, Shirt, Sofa, Sun, WandSparkles } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { AnimatePresence, motion, useReducedMotion } from 'motion/react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import type { DesignItem } from '@/components/evaluation/types';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/hooks/use-i18n';
import { useVocabulary } from '@/hooks/use-vocabulary';
import { cn } from '@/lib/utils';
import { aspectClass } from './design-card';

const PRESETS: { key: Preset; icon: LucideIcon }[] = [
    { key: 'clean_background', icon: Eraser },
    { key: 'studio_light', icon: Sun },
    { key: 'sharpen_details', icon: ScanLine },
    { key: 'simplify_pattern', icon: Shapes },
    { key: 'luxury_finish', icon: Gem },
    { key: 'model_shot', icon: Shirt },
    { key: 'lifestyle', icon: Sofa },
    { key: 'ad_space', icon: LayoutTemplate },
];

type Preset = 'clean_background' | 'studio_light' | 'sharpen_details' | 'simplify_pattern' | 'luxury_finish' | 'model_shot' | 'lifestyle' | 'ad_space';

const MAX_PRESETS = 4;
const RATIOS = ['1:1', '4:5', '3:4', '9:16', '16:9'];

/**
 * Edit an existing design: quick professional retouches, a recolour and the
 * merchant's own words, applied to the same design as a new version.
 */
export function DesignEditor({ design, open, onOpenChange }: { design: DesignItem; open: boolean; onOpenChange: (open: boolean) => void }) {
    const { t, tr } = useI18n();
    const e = t.studio.editor;
    const { options } = useVocabulary();
    const reduced = useReducedMotion();
    // Keep the studio's clean editing workspace, when one is open, after this edit.
    const since = usePage<{ since?: number | null }>().props.since ?? null;
    const form = useForm<{ presets: Preset[]; color: string; instruction: string; aspect_ratio: string; variants_count: number; since: number | null }>({
        presets: [],
        color: '',
        instruction: '',
        aspect_ratio: design.aspect_ratio,
        variants_count: 1,
        since,
    });
    const colors = options('colors');
    const chosenColor = colors.find((c) => c.value === form.data.color);
    const ready = form.data.presets.length > 0 || form.data.color !== '' || form.data.instruction.trim() !== '';

    const toggle = (key: Preset) =>
        form.setData('presets', form.data.presets.includes(key) ? form.data.presets.filter((p) => p !== key) : [...form.data.presets, key].slice(0, MAX_PRESETS));

    const close = (value: boolean) => {
        if (form.processing) return;
        if (!value) {
            form.reset();
            form.clearErrors();
        }
        onOpenChange(value);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!ready) return;
        form.transform((data) => ({ ...data, color: data.color || null }));
        form.post(`/store/designs/${design.id}/refine`, { preserveScroll: true, onSuccess: () => close(false) });
    };

    const summary = [
        ...form.data.presets.map((key) => e.presets[key].label),
        ...(chosenColor ? [`${e.recolor}: ${chosenColor.label}`] : []),
        ...(form.data.instruction.trim() ? [e.instruction] : []),
    ];

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent className="max-h-[92dvh] overflow-y-auto sm:max-w-5xl">
                <DialogHeader className="text-start sm:text-start">
                    <DialogTitle className="flex items-center gap-2">
                        <WandSparkles className="text-primary size-5" />
                        {e.title}
                    </DialogTitle>
                    <DialogDescription className="leading-6">{e.subtitle}</DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="grid gap-6 md:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
                    <div className="space-y-3 md:sticky md:top-0 md:self-start">
                        <div className={cn('bg-muted relative mx-auto max-h-[52dvh] overflow-hidden rounded-2xl border', aspectClass[design.aspect_ratio] ?? 'aspect-square')}>
                            <img src={design.image_url} alt={design.title} className="size-full object-contain" />
                            <span className="absolute start-3 top-3 rounded-full bg-black/55 px-2.5 py-1 text-[11px] font-medium text-white backdrop-blur">{e.original}</span>
                            {chosenColor && (
                                <motion.span
                                    initial={reduced ? false : { scale: 0.6, opacity: 0 }}
                                    animate={{ scale: 1, opacity: 1 }}
                                    className="absolute end-3 bottom-3 size-9 rounded-full border-2 border-white shadow-lg"
                                    style={{ backgroundColor: chosenColor.hex }}
                                    aria-hidden
                                />
                            )}
                        </div>
                        <div className="rounded-xl border p-3" aria-live="polite">
                            <p className="text-muted-foreground mb-2 text-xs font-medium">{summary.length ? e.summary : e.nothing}</p>
                            <div className="flex flex-wrap gap-1.5">
                                <AnimatePresence initial={false}>
                                    {summary.map((label) => (
                                        <motion.span
                                            key={label}
                                            layout
                                            initial={reduced ? false : { opacity: 0, scale: 0.85 }}
                                            animate={{ opacity: 1, scale: 1 }}
                                            exit={{ opacity: 0, scale: 0.85 }}
                                            className="bg-primary/10 text-primary rounded-full px-2.5 py-1 text-xs font-medium"
                                        >
                                            {label}
                                        </motion.span>
                                    ))}
                                </AnimatePresence>
                            </div>
                        </div>
                    </div>

                    <div className="space-y-6">
                        <section className="space-y-2.5">
                            <div className="flex items-baseline justify-between gap-2">
                                <h3 className="text-sm font-semibold">{e.quick}</h3>
                                <span className="text-muted-foreground text-xs">{tr(e.quickHint, { n: MAX_PRESETS })}</span>
                            </div>
                            <div className="grid gap-2 sm:grid-cols-2">
                                {PRESETS.map(({ key, icon: Icon }) => {
                                    const on = form.data.presets.includes(key);
                                    const full = !on && form.data.presets.length >= MAX_PRESETS;

                                    return (
                                        <button
                                            key={key}
                                            type="button"
                                            aria-pressed={on}
                                            disabled={full}
                                            onClick={() => toggle(key)}
                                            className={cn(
                                                'focus-visible:ring-ring flex items-start gap-3 rounded-xl border p-3 text-start transition-all focus-visible:ring-2 focus-visible:outline-none disabled:opacity-45',
                                                on ? 'border-primary bg-primary/5 ring-primary/30 ring-1' : 'hover:border-primary/40 hover:bg-muted/50',
                                            )}
                                        >
                                            <span className={cn('flex size-9 shrink-0 items-center justify-center rounded-lg transition-colors', on ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground')}>
                                                {on ? <Check className="size-4" /> : <Icon className="size-4" />}
                                            </span>
                                            <span className="min-w-0">
                                                <span className="block text-sm font-medium">{e.presets[key].label}</span>
                                                <span className="text-muted-foreground block text-[11px] leading-5">{e.presets[key].hint}</span>
                                            </span>
                                        </button>
                                    );
                                })}
                            </div>
                        </section>

                        <section className="space-y-2.5">
                            <h3 className="text-sm font-semibold">{e.recolor}</h3>
                            <div className="flex flex-wrap gap-2" role="radiogroup" aria-label={e.recolor}>
                                <button
                                    type="button"
                                    role="radio"
                                    aria-checked={form.data.color === ''}
                                    onClick={() => form.setData('color', '')}
                                    className={cn('h-9 rounded-full border px-3 text-xs transition-colors', form.data.color === '' ? 'border-primary bg-primary/5 text-primary' : 'hover:bg-muted')}
                                >
                                    {e.noColor}
                                </button>
                                {colors.map((color) => (
                                    <button
                                        key={color.value}
                                        type="button"
                                        role="radio"
                                        aria-checked={form.data.color === color.value}
                                        aria-label={color.label}
                                        title={color.label}
                                        onClick={() => form.setData('color', color.value)}
                                        className={cn(
                                            'focus-visible:ring-ring size-9 rounded-full border-2 shadow-sm transition-transform hover:scale-110 focus-visible:ring-2 focus-visible:outline-none',
                                            form.data.color === color.value ? 'border-primary ring-primary/30 scale-110 ring-2' : 'border-white/70 dark:border-white/20',
                                        )}
                                        style={{ backgroundColor: color.hex }}
                                    />
                                ))}
                            </div>
                        </section>

                        <section className="space-y-2">
                            <label htmlFor="refine-instruction" className="text-sm font-semibold">
                                {e.instruction}
                            </label>
                            <Textarea
                                id="refine-instruction"
                                value={form.data.instruction}
                                onChange={(event) => form.setData('instruction', event.target.value)}
                                placeholder={e.instructionPlaceholder}
                                maxLength={1000}
                                rows={3}
                            />
                            <div className="flex justify-between gap-2">
                                <InputError message={form.errors.instruction} />
                                <span className="text-muted-foreground ms-auto text-[11px]">{form.data.instruction.length}/1000</span>
                            </div>
                        </section>

                        <section className="grid gap-4 sm:grid-cols-[1fr_auto]">
                            <div className="space-y-2">
                                <h3 className="flex items-center gap-1.5 text-sm font-semibold">
                                    <Ruler className="size-3.5" />
                                    {e.ratio}
                                </h3>
                                <div className="flex flex-wrap gap-1.5">
                                    {RATIOS.map((ratio) => (
                                        <button
                                            key={ratio}
                                            type="button"
                                            dir="ltr"
                                            aria-pressed={form.data.aspect_ratio === ratio}
                                            onClick={() => form.setData('aspect_ratio', ratio)}
                                            className={cn('h-8 rounded-lg border px-2.5 text-xs transition-colors', form.data.aspect_ratio === ratio ? 'border-primary bg-primary/5 text-primary' : 'hover:bg-muted')}
                                        >
                                            {ratio}
                                        </button>
                                    ))}
                                </div>
                            </div>
                            <div className="space-y-2">
                                <h3 className="text-sm font-semibold">{e.variants}</h3>
                                <div className="flex gap-1.5">
                                    {[1, 2].map((n) => (
                                        <button
                                            key={n}
                                            type="button"
                                            aria-pressed={form.data.variants_count === n}
                                            onClick={() => form.setData('variants_count', n)}
                                            className={cn('num h-8 w-10 rounded-lg border text-xs transition-colors', form.data.variants_count === n ? 'border-primary bg-primary/5 text-primary' : 'hover:bg-muted')}
                                        >
                                            {n}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        </section>

                        <div className="bg-background/95 sticky bottom-0 flex flex-wrap justify-end gap-2 border-t pt-4 backdrop-blur">
                            <Button type="button" variant="outline" disabled={form.processing} onClick={() => close(false)}>
                                {t.common.cancel}
                            </Button>
                            <Button type="submit" disabled={!ready || form.processing}>
                                {form.processing ? <Spinner /> : <WandSparkles className="size-4" />}
                                {e.apply}
                            </Button>
                        </div>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

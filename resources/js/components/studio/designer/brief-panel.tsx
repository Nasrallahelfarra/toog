import type { InertiaFormProps } from '@inertiajs/react';
import {
    ChevronDown,
    ImagePlus,
    Plus,
    Sparkles,
    Trash2,
    WandSparkles,
    X,
} from 'lucide-react';
import { AnimatePresence, motion } from 'motion/react';
import { useEffect, useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Label } from '@/components/ui/label';
import { Slider } from '@/components/ui/slider';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { useCount } from '@/hooks/use-count';
import { useI18n } from '@/hooks/use-i18n';
import { useVocabulary } from '@/hooks/use-vocabulary';
import { cn } from '@/lib/utils';
import {
    FabricChips,
    OPTION_LIMITS,
    RemoveButton,
    SectionComposer,
    removeSection,
} from './brief-options';
import { GarmentIcon } from './garment-icons';
import type {
    CustomFabric,
    FashionVocabulary,
    GarmentKey,
    Inspiration,
    RenderKey,
    Modesty,
    StoreCategory,
} from './types';

export type BriefForm = {
    garment: GarmentKey;
    fabric: string | null;
    fabric_image: File | null;
    /** Reuse the fabric photo of an earlier collection. */
    fabric_from: number | null;
    colors: string[];
    occasion: string;
    age: 'young' | 'adult' | 'mature';
    modesty: Modesty | null;
    /** One of the store's own sections, when the garment comes from it. */
    category: string | null;
    show_face: boolean;
    tier: 'economy' | 'mid' | 'premium' | 'luxury' | null;
    creativity: number;
    count: number;
    render: RenderKey;
    notes: string;
    inspiration: Inspiration | null;
};

const MAX_COLORS = 4;
const FABRIC_INPUT_ID = 'designer-fabric-input';

/**
 * The AI designer's brief: fabric (photo and/or type), garment, occasion,
 * colours and how bold, with the rest folded away. Colours are read from
 * the fabric photo in the browser, mapped to the platform palette.
 */
export function BriefPanel({
    form,
    vocabulary,
    aiLive,
    pulse,
    onSubmit,
    reusedFabricUrl,
    onClearReused,
    categories = [],
    customFabrics = [],
}: {
    form: InertiaFormProps<BriefForm>;
    vocabulary: FashionVocabulary;
    categories?: StoreCategory[];
    customFabrics?: CustomFabric[];
    aiLive: boolean;
    pulse: number;
    onSubmit: () => void;
    reusedFabricUrl?: string | null;
    onClearReused?: () => void;
}) {
    const { t, tr, locale } = useI18n();
    const count = useCount();
    const d = t.designer.brief;
    const name = (entry: { ar: string; en: string }) => entry[locale];
    const ready = Boolean(
        form.data.fabric || form.data.fabric_image || form.data.fabric_from,
    );
    // A competitor piece is copied unless the merchant asks for inspiration only.
    const copyable =
        form.data.inspiration?.kind === 'competitor' ||
        form.data.inspiration?.kind === 'social';
    const copying =
        copyable && (form.data.inspiration?.match ?? 'close') === 'close';
    const builtIn = form.data.fabric
        ? vocabulary.fabrics[form.data.fabric]
        : null;
    const custom = customFabrics.find((c) => c.key === form.data.fabric);
    const fabricHint = builtIn
        ? tr(d.selectedFabric, {
              name: name(builtIn),
              drape: locale === 'ar' ? builtIn.drape_ar : builtIn.drape_en,
          })
        : custom?.base && vocabulary.fabrics[custom.base]
          ? tr(d.customFabric.baseHint, {
                base: name(vocabulary.fabrics[custom.base]),
            })
          : d.fabricTypeHint;
    const minutes = Math.max(1, Math.round(form.data.count * 0.6 + 0.5));
    const modesty: Modesty =
        form.data.modesty ??
        (['abaya', 'prayer_set', 'jalabiya'].includes(form.data.garment)
            ? 'strict'
            : 'standard');

    return (
        <form
            id="designer-brief"
            onSubmit={(e) => {
                e.preventDefault();
                onSubmit();
            }}
            className="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-[0_1px_2px_rgba(16,24,40,0.04)]"
        >
            <header className="border-b px-5 pt-5 pb-4">
                <div className="flex items-center gap-2">
                    <span className="h-3.5 w-0.5 rounded-full bg-gold" />
                    <h2 className="text-[15px] font-semibold">{d.title}</h2>
                </div>
                <p className="mt-1 text-xs text-muted-foreground">{d.hint}</p>
            </header>

            <div className="space-y-6 p-5">
                <AnimatePresence>
                    {form.data.inspiration && (
                        <motion.div
                            initial={{ opacity: 0, height: 0 }}
                            animate={{ opacity: 1, height: 'auto' }}
                            exit={{ opacity: 0, height: 0 }}
                            className="space-y-2.5 overflow-hidden rounded-xl bg-gold-soft p-2.5"
                        >
                            <div className="flex items-center gap-3">
                                {form.data.inspiration.image_url ? (
                                    <img
                                        src={form.data.inspiration.image_url}
                                        alt=""
                                        className="size-10 shrink-0 rounded-lg object-cover"
                                    />
                                ) : (
                                    <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-gold/20 text-gold-foreground dark:text-gold">
                                        <Sparkles className="size-4" />
                                    </span>
                                )}
                                <div className="min-w-0 flex-1 text-xs leading-relaxed">
                                    <span className="block text-[11px] text-muted-foreground">
                                        {d.inspiredBy}
                                    </span>
                                    <span className="line-clamp-1 font-medium">
                                        {form.data.inspiration.label}
                                    </span>
                                </div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="size-8"
                                    onClick={() =>
                                        form.setData('inspiration', null)
                                    }
                                    aria-label={d.clearInspiration}
                                >
                                    <X className="size-4" />
                                </Button>
                            </div>
                            {copyable && (
                                <div className="space-y-1.5">
                                    <p className="text-[11px] font-medium text-muted-foreground">
                                        {d.match.label}
                                    </p>
                                    <Segmented
                                        options={['close', 'inspired']}
                                        labels={{
                                            close: d.match.close,
                                            inspired: d.match.inspired,
                                        }}
                                        value={copying ? 'close' : 'inspired'}
                                        onChange={(v) =>
                                            form.setData((data) => ({
                                                ...data,
                                                inspiration: data.inspiration
                                                    ? {
                                                          ...data.inspiration,
                                                          match: v as
                                                              | 'close'
                                                              | 'inspired',
                                                      }
                                                    : null,
                                                // A copy comes in at most three versions.
                                                count:
                                                    v === 'close'
                                                        ? Math.min(
                                                              data.count,
                                                              3,
                                                          )
                                                        : data.count,
                                            }))
                                        }
                                    />
                                    <p className="text-[11px] leading-relaxed text-muted-foreground">
                                        {copying
                                            ? d.match.closeHint
                                            : d.match.inspiredHint}
                                    </p>
                                </div>
                            )}
                        </motion.div>
                    )}
                </AnimatePresence>

                <Field label={d.fabricPhoto} pulse={pulse}>
                    <FabricDrop
                        file={form.data.fabric_image}
                        reusedUrl={reusedFabricUrl ?? null}
                        onClearReused={onClearReused}
                        onChange={(file) =>
                            form.setData((data) => ({
                                ...data,
                                fabric_image: file,
                                fabric_from: file ? null : data.fabric_from,
                            }))
                        }
                        onColors={(colors) =>
                            colors.length &&
                            form.setData('colors', colors.slice(0, MAX_COLORS))
                        }
                    />
                    <InputError message={form.errors.fabric_image} />
                </Field>

                <Field label={d.fabricType} hint={fabricHint} pulse={pulse}>
                    <FabricChips
                        form={form}
                        vocabulary={vocabulary}
                        customFabrics={customFabrics}
                    />
                    <InputError message={form.errors.fabric} />
                </Field>

                <Field label={d.garment} pulse={pulse}>
                    <GarmentPicker
                        form={form}
                        vocabulary={vocabulary}
                        categories={categories}
                    />
                </Field>

                <Field
                    label={d.modesty}
                    hint={d.modestyHints[modesty]}
                    pulse={pulse}
                >
                    <Segmented
                        options={['strict', 'standard', 'open']}
                        labels={d.modestyLevels}
                        value={modesty}
                        onChange={(v) =>
                            form.setData('modesty', v as BriefForm['modesty'])
                        }
                    />
                </Field>

                <Field label={d.occasion} pulse={pulse}>
                    <div className="flex flex-wrap gap-1.5">
                        {Object.entries(vocabulary.occasions).map(
                            ([key, occasion]) => (
                                <Chip
                                    key={key}
                                    active={form.data.occasion === key}
                                    onClick={() =>
                                        form.setData('occasion', key)
                                    }
                                >
                                    {name(occasion)}
                                </Chip>
                            ),
                        )}
                    </div>
                </Field>

                <Field label={d.colors} hint={d.colorsHint} pulse={pulse}>
                    <ColorPicker
                        value={form.data.colors}
                        onChange={(colors) => form.setData('colors', colors)}
                    />
                </Field>

                {!copying && (
                    <Field label={d.creativity}>
                        <div className="space-y-3 pt-1">
                            <Slider
                                min={1}
                                max={5}
                                step={1}
                                value={[form.data.creativity]}
                                onValueChange={([v]) =>
                                    form.setData('creativity', v)
                                }
                                dir={locale === 'ar' ? 'rtl' : 'ltr'}
                                aria-label={d.creativity}
                            />
                            <div className="flex justify-between text-[10px] text-muted-foreground">
                                {d.creativityStops.map((stop, i) => (
                                    <span
                                        key={stop}
                                        className={cn(
                                            'transition-colors',
                                            form.data.creativity === i + 1 &&
                                                'font-semibold text-foreground',
                                        )}
                                    >
                                        {stop}
                                    </span>
                                ))}
                            </div>
                            <p className="rounded-lg bg-muted/60 px-3 py-2 text-xs leading-relaxed">
                                {d.creativityHints[form.data.creativity - 1]}
                            </p>
                        </div>
                    </Field>
                )}

                <div className="grid gap-5 sm:grid-cols-[auto_1fr]">
                    <Field label={d.count}>
                        <Segmented
                            options={
                                copying ? ['1', '2', '3'] : ['1', '2', '3', '4']
                            }
                            value={String(form.data.count)}
                            onChange={(v) => form.setData('count', Number(v))}
                            ltr
                        />
                    </Field>
                    <Field label={d.render}>
                        <div className="grid grid-cols-2 gap-1.5">
                            {(
                                Object.keys(vocabulary.renders) as RenderKey[]
                            ).map((key) => (
                                <Chip
                                    key={key}
                                    active={form.data.render === key}
                                    onClick={() => form.setData('render', key)}
                                    className="justify-center rounded-lg"
                                >
                                    {name(vocabulary.renders[key])}
                                </Chip>
                            ))}
                        </div>
                    </Field>
                </div>

                {(form.data.render === 'on_model' ||
                    form.data.render === 'editorial') && (
                    <label className="flex cursor-pointer items-center justify-between gap-3 rounded-xl border px-3.5 py-2.5">
                        <span className="min-w-0">
                            <span className="block text-xs font-semibold">
                                {d.showFace}
                            </span>
                            <span className="block text-[11px] leading-relaxed text-muted-foreground">
                                {d.showFaceHint}
                            </span>
                        </span>
                        <Switch
                            checked={form.data.show_face}
                            onCheckedChange={(v) =>
                                form.setData('show_face', v)
                            }
                            aria-label={d.showFace}
                        />
                    </label>
                )}

                <MoreOptions form={form} />

                {!aiLive && (
                    <p className="rounded-xl bg-info-soft px-3 py-2.5 text-xs leading-relaxed text-info">
                        {d.demo}
                    </p>
                )}

                <div className="space-y-2">
                    <Button
                        type="submit"
                        size="lg"
                        variant="gold"
                        className="h-12 w-full text-[15px]"
                        disabled={form.processing || !ready}
                    >
                        {form.processing ? (
                            <Spinner />
                        ) : (
                            <WandSparkles className="size-4" />
                        )}
                        {form.processing ? d.submitting : d.submit}
                    </Button>
                    <p className="text-center text-[11px] text-muted-foreground">
                        {ready
                            ? tr(d.estimateLine, {
                                  designs: count(
                                      form.data.count,
                                      d.counts.designs,
                                  ),
                                  minutes: count(minutes, d.counts.minutes),
                              })
                            : d.needFabric}
                    </p>
                </div>
            </div>
        </form>
    );
}

function MoreOptions({ form }: { form: InertiaFormProps<BriefForm> }) {
    const { t } = useI18n();
    const d = t.designer.brief;
    const [open, setOpen] = useState(false);

    return (
        <Collapsible
            open={open}
            onOpenChange={setOpen}
            className="rounded-xl border border-dashed"
        >
            <CollapsibleTrigger className="flex w-full cursor-pointer items-center justify-between px-3.5 py-2.5 text-xs font-medium text-muted-foreground hover:text-foreground">
                {d.more}
                <ChevronDown
                    className={cn(
                        'size-4 transition-transform',
                        open && 'rotate-180',
                    )}
                />
            </CollapsibleTrigger>
            <CollapsibleContent className="space-y-4 px-3.5 pb-4">
                <Field label={d.age}>
                    <Segmented
                        options={['young', 'adult', 'mature']}
                        labels={d.ages}
                        value={form.data.age}
                        onChange={(v) =>
                            form.setData('age', v as BriefForm['age'])
                        }
                    />
                </Field>
                <Field label={d.tier}>
                    <Segmented
                        options={['economy', 'mid', 'premium', 'luxury']}
                        labels={d.tiers}
                        value={form.data.tier ?? 'mid'}
                        onChange={(v) =>
                            form.setData('tier', v as BriefForm['tier'])
                        }
                    />
                </Field>
                <Field label={d.notes}>
                    <Textarea
                        rows={3}
                        value={form.data.notes}
                        maxLength={600}
                        onChange={(e) => form.setData('notes', e.target.value)}
                        placeholder={d.notesPlaceholder}
                        className="text-sm"
                    />
                    <InputError message={form.errors.notes} />
                </Field>
            </CollapsibleContent>
        </Collapsible>
    );
}

/**
 * Fabric photo with a round swatch preview. The dominant colours are read
 * on a small canvas and mapped to the nearest platform colours.
 */
function FabricDrop({
    file,
    reusedUrl,
    onClearReused,
    onChange,
    onColors,
}: {
    file: File | null;
    reusedUrl: string | null;
    onClearReused?: () => void;
    onChange: (file: File | null) => void;
    onColors: (colors: string[]) => void;
}) {
    const { t } = useI18n();
    const d = t.designer.brief;
    const { vocabulary } = useVocabulary();
    const input = useRef<HTMLInputElement>(null);
    const [preview, setPreview] = useState<string | null>(null);
    const [dragging, setDragging] = useState(false);
    const [sampled, setSampled] = useState<string[]>([]);

    useEffect(() => {
        if (!file) {
            setPreview(null);
            setSampled([]);
            return;
        }
        const url = URL.createObjectURL(file);
        setPreview(url);
        const img = new Image();
        img.onload = () => {
            const colors = dominantColors(img, vocabulary?.colors ?? {});
            setSampled(colors);
            onColors(colors);
        };
        img.src = url;

        return () => URL.revokeObjectURL(url);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [file]);

    const pick = (files: FileList | null) => {
        const f = files?.[0];
        if (f && f.type.startsWith('image/')) onChange(f);
    };

    // Always mounted, so "fabric photo" buttons elsewhere can open the picker.
    const picker = (
        <input
            ref={input}
            id={FABRIC_INPUT_ID}
            type="file"
            accept="image/*"
            className="hidden"
            onChange={(e) => {
                pick(e.target.files);
                e.target.value = '';
            }}
        />
    );

    if (!preview && reusedUrl) {
        return (
            <div className="flex items-center gap-4 rounded-xl bg-muted/40 p-3">
                {picker}
                <img
                    src={reusedUrl}
                    alt=""
                    className="size-20 shrink-0 rounded-full object-cover shadow-sm ring-4 ring-white/70 dark:ring-white/10"
                />
                <p className="flex-1 text-xs leading-relaxed text-muted-foreground">
                    {d.reusedFabric}
                </p>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-8 self-start text-muted-foreground"
                    onClick={onClearReused}
                    aria-label={t.designer.brief.removeFabricPhoto}
                >
                    <Trash2 className="size-4" />
                </Button>
            </div>
        );
    }

    if (preview) {
        return (
            <div className="flex items-center gap-4 rounded-xl bg-muted/40 p-3">
                {picker}
                <button
                    type="button"
                    onClick={() => input.current?.click()}
                    className="relative shrink-0 cursor-pointer"
                    aria-label={d.fabricDrop}
                >
                    <img
                        src={preview}
                        alt=""
                        className="size-20 rounded-full object-cover shadow-sm ring-4 ring-white/70 transition-opacity hover:opacity-85 dark:ring-white/10"
                    />
                </button>
                <div className="min-w-0 flex-1 space-y-2">
                    <p className="text-[11px] text-muted-foreground">
                        {d.sampled}
                    </p>
                    <div className="flex flex-wrap gap-1.5">
                        {sampled.map((c) => (
                            <span
                                key={c}
                                className="size-6 rounded-full shadow-sm ring-1 ring-black/10"
                                style={{
                                    backgroundColor:
                                        vocabulary?.colors?.[c]?.hex,
                                }}
                                title={c}
                            />
                        ))}
                    </div>
                    <p className="text-[10px] text-muted-foreground">
                        {d.visualEstimate}
                    </p>
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-8 self-start text-muted-foreground"
                    onClick={() => onChange(null)}
                    aria-label={t.designer.brief.removeFabricPhoto}
                >
                    <Trash2 className="size-4" />
                </Button>
            </div>
        );
    }

    return (
        <div>
            <button
                type="button"
                onClick={() => input.current?.click()}
                onDragOver={(e) => {
                    e.preventDefault();
                    setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={(e) => {
                    e.preventDefault();
                    setDragging(false);
                    pick(e.dataTransfer.files);
                }}
                className={cn(
                    'group flex w-full cursor-pointer items-center gap-3 rounded-xl border-2 border-dashed px-4 py-4 text-start transition-colors',
                    dragging
                        ? 'border-gold bg-gold-soft/50'
                        : 'hover:border-foreground/25 hover:bg-muted/40',
                )}
            >
                <span className="flex size-12 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground transition-colors group-hover:text-foreground">
                    <ImagePlus className="size-5" />
                </span>
                <span className="space-y-0.5">
                    <span className="block text-sm font-medium">
                        {d.fabricDrop}
                    </span>
                    <span className="block text-[11px] leading-relaxed text-muted-foreground">
                        {d.fabricTip}
                    </span>
                </span>
            </button>
            {picker}
        </div>
    );
}

/** Opens the brief's fabric photo picker from anywhere on the page. */
export function openFabricPicker() {
    const field = document.getElementById(FABRIC_INPUT_ID);
    field
        ?.closest('form')
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    field?.click();
}

/** Top colours of an image, as platform colour keys covering at least 8% each. */
function dominantColors(
    img: HTMLImageElement,
    palette: Record<string, { hex?: string }>,
): string[] {
    const size = 48;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext('2d');
    if (!ctx) return [];
    ctx.drawImage(img, 0, 0, size, size);
    const data = ctx.getImageData(0, 0, size, size).data;
    const entries = Object.entries(palette)
        .filter(([, v]) => v.hex)
        .map(
            ([key, v]) =>
                [
                    key,
                    parseInt(v.hex!.slice(1, 3), 16),
                    parseInt(v.hex!.slice(3, 5), 16),
                    parseInt(v.hex!.slice(5, 7), 16),
                ] as const,
        );
    const counts: Record<string, number> = {};

    for (let i = 0; i < data.length; i += 4) {
        const [r, g, b] = [data[i], data[i + 1], data[i + 2]];
        let best = entries[0]?.[0];
        let distance = Infinity;
        for (const [key, pr, pg, pb] of entries) {
            const dist = (r - pr) ** 2 + (g - pg) ** 2 + (b - pb) ** 2;
            if (dist < distance) {
                distance = dist;
                best = key;
            }
        }
        if (best) counts[best] = (counts[best] ?? 0) + 1;
    }

    const total = size * size;

    return Object.entries(counts)
        .filter(([, n]) => n / total >= 0.08)
        .sort((a, b) => b[1] - a[1])
        .slice(0, 3)
        .map(([key]) => key);
}

function ColorPicker({
    value,
    onChange,
}: {
    value: string[];
    onChange: (colors: string[]) => void;
}) {
    const { t } = useI18n();
    const { options } = useVocabulary();
    const [open, setOpen] = useState(false);
    const toggle = (key: string) =>
        onChange(
            value.includes(key)
                ? value.filter((c) => c !== key)
                : [...value, key].slice(0, MAX_COLORS),
        );

    return (
        <div className="space-y-2">
            <div className="flex flex-wrap items-center gap-1.5">
                {value.map((key) => {
                    const option = options('colors').find(
                        (o) => o.value === key,
                    );

                    return (
                        <button
                            key={key}
                            type="button"
                            onClick={() => toggle(key)}
                            className="group flex cursor-pointer items-center gap-1.5 rounded-full border py-0.5 ps-0.5 pe-2 text-[11px] hover:bg-muted"
                        >
                            <span
                                className="size-5 rounded-full ring-1 ring-black/10"
                                style={{ backgroundColor: option?.hex }}
                            />
                            {option?.label ?? key}
                            <X className="size-3 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100 pointer-coarse:opacity-100" />
                        </button>
                    );
                })}
                {value.length < MAX_COLORS && (
                    <button
                        type="button"
                        onClick={() => setOpen((v) => !v)}
                        aria-expanded={open}
                        aria-label={t.designer.brief.addColor}
                        title={t.designer.brief.addColor}
                        className="flex size-7 cursor-pointer items-center justify-center rounded-full border border-dashed text-muted-foreground hover:border-foreground/30 hover:text-foreground"
                    >
                        <Plus className="size-3.5" />
                    </button>
                )}
            </div>
            <AnimatePresence>
                {open && (
                    <motion.div
                        initial={{ opacity: 0, y: -4 }}
                        animate={{ opacity: 1, y: 0 }}
                        exit={{ opacity: 0, y: -4 }}
                        className="flex flex-wrap gap-1.5 rounded-xl bg-muted/40 p-2.5"
                    >
                        {options('colors').map((o) => (
                            <button
                                key={o.value}
                                type="button"
                                title={o.label}
                                aria-pressed={value.includes(o.value)}
                                onClick={() => toggle(o.value)}
                                className={cn(
                                    'size-7 cursor-pointer rounded-full ring-1 ring-black/10 transition-transform hover:scale-110',
                                    value.includes(o.value) &&
                                        'ring-2 ring-gold ring-offset-2 ring-offset-background',
                                )}
                                style={{ backgroundColor: o.hex }}
                            />
                        ))}
                    </motion.div>
                )}
            </AnimatePresence>
        </div>
    );
}

/** A labelled brief field; it glows briefly when an insight fills the brief. */
function Field({
    label,
    hint,
    pulse,
    children,
}: {
    label: string;
    hint?: string;
    pulse?: number;
    children: React.ReactNode;
}) {
    const [flash, setFlash] = useState(false);

    useEffect(() => {
        if (!pulse) return;
        setFlash(true);
        const timer = window.setTimeout(() => setFlash(false), 1200);
        return () => window.clearTimeout(timer);
    }, [pulse]);

    return (
        <div
            className={cn(
                '-m-1.5 space-y-2 rounded-xl p-1.5 transition-shadow duration-700',
                flash && 'ring-2 ring-gold/50',
            )}
        >
            <Label className="text-xs font-semibold">{label}</Label>
            {children}
            {hint && (
                <p className="text-[11px] leading-relaxed text-muted-foreground">
                    {hint}
                </p>
            )}
        </div>
    );
}

function Chip({
    active,
    onClick,
    children,
    className,
}: {
    active: boolean;
    onClick: () => void;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <button
            type="button"
            aria-pressed={active}
            onClick={onClick}
            className={cn(
                'inline-flex cursor-pointer items-center rounded-full border px-3 py-1.5 text-xs transition-all',
                active
                    ? 'border-navy bg-navy text-white dark:text-background'
                    : 'text-foreground/80 hover:border-foreground/30',
                className,
            )}
        >
            {children}
        </button>
    );
}

function Segmented({
    options,
    value,
    onChange,
    labels,
    ltr,
}: {
    options: string[];
    value: string;
    onChange: (v: string) => void;
    labels?: Record<string, string>;
    ltr?: boolean;
}) {
    return (
        <div
            role="radiogroup"
            className="flex rounded-xl bg-muted p-1"
            dir={ltr ? 'ltr' : undefined}
        >
            {options.map((o) => (
                <button
                    key={o}
                    type="button"
                    role="radio"
                    aria-checked={value === o}
                    onClick={() => onChange(o)}
                    className={cn(
                        'relative min-w-9 flex-1 cursor-pointer rounded-lg px-2 py-1.5 text-xs font-medium whitespace-nowrap transition-colors',
                        value === o
                            ? 'bg-card text-foreground shadow-sm'
                            : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    {labels?.[o] ?? o}
                </button>
            ))}
        </div>
    );
}

/**
 * What to design: the store's own sections first (synced from Zid), each
 * with the garment it maps to, then every other garment type.
 */
function GarmentPicker({
    form,
    vocabulary,
    categories,
}: {
    form: InertiaFormProps<BriefForm>;
    vocabulary: FashionVocabulary;
    categories: StoreCategory[];
}) {
    const { t, tr, locale } = useI18n();
    const count = useCount();
    const d = t.designer.brief;
    const s = d.customSection;
    // Clothing sections only; the server leaves accessories and the like out.
    const mapped = categories.filter((c) => c.garment);
    const customCount = mapped.filter((c) => c.source === 'custom').length;
    const [others, setOthers] = useState(
        mapped.length === 0 || form.data.category === null,
    );
    const [composing, setComposing] = useState(false);
    const [notice, setNotice] = useState<string | null>(null);
    const pick = (garment: GarmentKey, category: string | null) =>
        form.setData((data) => ({ ...data, garment, category }));
    const otherSelected =
        !others && form.data.category === null
            ? vocabulary.garments[form.data.garment]?.[locale]
            : null;

    return (
        <div className="space-y-3">
            <div className="space-y-1.5">
                <p className="text-[11px] text-muted-foreground">
                    {d.storeSections}
                </p>
                {mapped.length === 0 && !composing && (
                    <p className="text-[11px] leading-relaxed text-muted-foreground">
                        {s.empty}
                    </p>
                )}
                <div className="grid grid-cols-2 gap-2">
                    {mapped.map((c) => {
                        const active = form.data.category === c.name;
                        const card = (
                            <button
                                key={c.name}
                                type="button"
                                aria-pressed={active}
                                onClick={() => pick(c.garment!, c.name)}
                                className={cn(
                                    'flex w-full cursor-pointer items-center gap-2.5 rounded-xl border px-2.5 py-2 text-start transition-all',
                                    active
                                        ? 'border-gold bg-gold-soft/60 ring-2 ring-gold/25'
                                        : 'hover:border-foreground/25',
                                )}
                            >
                                <GarmentIcon
                                    garment={c.garment!}
                                    className={cn(
                                        'h-8 w-7 shrink-0',
                                        active
                                            ? 'text-foreground'
                                            : 'text-muted-foreground',
                                    )}
                                />
                                <span className="min-w-0">
                                    <span className="block truncate text-xs font-medium">
                                        {c.name}
                                    </span>
                                    <span className="block truncate text-[10px] text-muted-foreground">
                                        {c.source === 'custom' ? (
                                            <span className="text-gold-foreground dark:text-gold">
                                                {s.custom}
                                            </span>
                                        ) : (
                                            count(c.products, d.counts.products)
                                        )}{' '}
                                        ·{' '}
                                        {
                                            vocabulary.garments[c.garment!][
                                                locale
                                            ]
                                        }
                                    </span>
                                </span>
                            </button>
                        );

                        return c.source === 'custom' ? (
                            <div key={c.name} className="group relative">
                                {card}
                                <RemoveButton
                                    label={tr(d.remove, { name: c.name })}
                                    onClick={() =>
                                        removeSection(c, {
                                            wasSelected: active,
                                            onRemoved: () => {
                                                if (active) {
                                                    form.setData(
                                                        'category',
                                                        null,
                                                    );
                                                    setOthers(true);
                                                }
                                            },
                                            onRestored: () =>
                                                pick(c.garment!, c.name),
                                            labels: {
                                                removed: tr(d.removed, {
                                                    name: c.name,
                                                }),
                                                undo: d.undo,
                                            },
                                        })
                                    }
                                />
                            </div>
                        ) : (
                            card
                        );
                    })}
                    <AnimatePresence initial={false}>
                        {composing ? (
                            <SectionComposer
                                key="composer"
                                vocabulary={vocabulary}
                                categories={categories}
                                onAdded={(section) => {
                                    pick(section.garment, section.name);
                                    setComposing(false);
                                    setNotice(null);
                                }}
                                onExisting={(section) => {
                                    pick(section.garment!, section.name);
                                    setComposing(false);
                                    setNotice(
                                        tr(s.exists, { name: section.name }),
                                    );
                                }}
                                onCancel={() => setComposing(false)}
                            />
                        ) : (
                            <button
                                key="add"
                                type="button"
                                disabled={customCount >= OPTION_LIMITS.section}
                                title={
                                    customCount >= OPTION_LIMITS.section
                                        ? s.limit
                                        : s.add
                                }
                                onClick={() => {
                                    setComposing(true);
                                    setNotice(null);
                                }}
                                className="flex min-h-[52px] cursor-pointer items-center justify-center gap-1.5 rounded-xl border border-dashed text-[11px] font-medium text-muted-foreground transition-colors hover:border-foreground/30 hover:text-foreground disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <Plus className="size-3.5" />
                                {s.add}
                            </button>
                        )}
                    </AnimatePresence>
                </div>
                {notice && (
                    <p
                        aria-live="polite"
                        className="text-[11px] text-muted-foreground"
                    >
                        {notice}
                    </p>
                )}
                {mapped.length > 0 && (
                    <button
                        type="button"
                        onClick={() => setOthers((v) => !v)}
                        aria-expanded={others}
                        className="flex cursor-pointer items-center gap-1 text-[11px] font-medium text-muted-foreground hover:text-foreground"
                    >
                        {d.otherGarments}
                        {otherSelected && (
                            <span className="font-normal text-foreground/80">
                                · {otherSelected}
                            </span>
                        )}
                        <ChevronDown
                            className={cn(
                                'size-3.5 transition-transform',
                                others && 'rotate-180',
                            )}
                        />
                    </button>
                )}
            </div>
            {others && (
                <div className="grid grid-cols-4 gap-2">
                    {(Object.keys(vocabulary.garments) as GarmentKey[]).map(
                        (key) => {
                            const active =
                                form.data.garment === key &&
                                form.data.category === null;

                            return (
                                <button
                                    key={key}
                                    type="button"
                                    aria-pressed={active}
                                    onClick={() => pick(key, null)}
                                    className={cn(
                                        'flex cursor-pointer flex-col items-center gap-1.5 rounded-xl border px-1 pt-2.5 pb-2 text-[11px] leading-tight transition-all',
                                        active
                                            ? 'border-gold bg-gold-soft/60 text-foreground ring-2 ring-gold/25'
                                            : 'text-muted-foreground hover:border-foreground/25 hover:text-foreground',
                                    )}
                                >
                                    <GarmentIcon
                                        garment={key}
                                        className="h-9 w-8"
                                    />
                                    <span className="text-center">
                                        {vocabulary.garments[key][locale]}
                                    </span>
                                </button>
                            );
                        },
                    )}
                </div>
            )}
        </div>
    );
}

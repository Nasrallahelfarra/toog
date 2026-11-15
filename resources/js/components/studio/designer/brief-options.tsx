import { router } from '@inertiajs/react';
import type { InertiaFormProps } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { AnimatePresence, motion } from 'motion/react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';
import type { BriefForm } from './brief-panel';
import { GarmentIcon } from './garment-icons';
import type {
    CustomFabric,
    DesignerProps,
    FashionVocabulary,
    GarmentKey,
    StoreCategory,
} from './types';

const OPTIONS_URL = '/store/studio/designer/options';

export const OPTION_LIMITS = { fabric: 20, section: 12 } as const;

/** Reload only the designer's data; the brief keeps what was typed. */
const quiet: {
    preserveScroll: boolean;
    preserveState: boolean;
    only: string[];
} = {
    preserveScroll: true,
    preserveState: true,
    only: ['designer'],
};

/**
 * A name in the form used to spot duplicates, with the server's rules:
 * no diacritics or tatweel, one alef, yaa and taa marbuta, lower case.
 */
export function normalizeName(name: string): string {
    return name
        .replace(/[ً-ٰٟـ]/g, '')
        .replace(/[أإآ]/g, 'ا')
        .replace(/ى/g, 'ي')
        .replace(/ة/g, 'ه')
        .toLowerCase()
        .replace(/\s+/g, ' ')
        .trim();
}

const tidy = (name: string) => name.replace(/\s+/g, ' ').trim();

type NameProblem = 'short' | 'long' | 'letters' | null;

/** 2–40 characters with at least two Arabic or Latin letters. */
function nameProblem(name: string): NameProblem {
    const value = tidy(name);
    if (value.length < 2) return 'short';
    if (value.length > 40) return 'long';
    if (!/[\p{Script=Arabic}A-Za-z].*[\p{Script=Arabic}A-Za-z]/u.test(value))
        return 'letters';

    return null;
}

/**
 * The garment a section's name points to, as the server reads store
 * sections; occasion words alone ("خطوبة", "زفاف") suggest an evening dress.
 */
export function guessGarment(name: string): GarmentKey | null {
    const text = name.toLowerCase();
    const words: [GarmentKey, string[]][] = [
        ['dress_evening', ['سهر', 'evening']],
        ['abaya', ['عباي', 'abaya']],
        ['jalabiya', ['جلابي', 'jalabiya', 'jalabia']],
        ['kaftan', ['قفطان', 'قفاطين', 'kaftan', 'caftan']],
        ['kimono', ['كيمون', 'kimono']],
        ['prayer_set', ['جلال', 'صلاة', 'prayer']],
        ['coord_set', ['طقم', 'أطقم', 'اطقم', 'set']],
        ['dress_daily', ['فستان', 'فساتين', 'dress']],
        [
            'dress_evening',
            ['خطوب', 'ملكة', 'ملكه', 'زفاف', 'wedding', 'engagement'],
        ],
    ];

    return (
        words.find(([, list]) => list.some((w) => text.includes(w)))?.[0] ??
        null
    );
}

/** One fabric chip; the merchant's own fabrics carry a small gold dot. */
function FabricChip({
    label,
    active,
    sheer,
    custom,
    title,
    onClick,
}: {
    label: string;
    active: boolean;
    sheer: boolean;
    custom?: boolean;
    title?: string;
    onClick: () => void;
}) {
    const { t } = useI18n();

    return (
        <button
            type="button"
            title={title}
            aria-pressed={active}
            onClick={onClick}
            className={cn(
                'inline-flex cursor-pointer items-center gap-1 rounded-full border px-3 py-1.5 text-xs transition-all',
                active
                    ? 'border-navy bg-navy text-white dark:text-background'
                    : 'text-foreground/80 hover:border-foreground/30',
            )}
        >
            {custom && (
                <span
                    className="size-1.5 shrink-0 rounded-full bg-gold"
                    aria-hidden
                />
            )}
            {label}
            {sheer && (
                <span
                    className={cn(
                        'text-[10px]',
                        active ? 'opacity-70' : 'text-muted-foreground',
                    )}
                >
                    · {t.designer.brief.sheer}
                </span>
            )}
        </button>
    );
}

/** The small × on a custom chip or card: on hover, focus, or always on touch. */
function RemoveButton({
    label,
    onClick,
}: {
    label: string;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            aria-label={label}
            title={label}
            onClick={onClick}
            className="absolute -end-1.5 -top-1.5 z-10 flex size-[18px] cursor-pointer items-center justify-center rounded-full border bg-card text-muted-foreground opacity-0 shadow-sm transition-opacity group-focus-within:opacity-100 group-hover:opacity-100 hover:text-foreground pointer-coarse:opacity-100"
        >
            <X className="size-2.5" />
        </button>
    );
}

/**
 * The fabric type chips: the built-in fabrics, the store's own ones, and
 * "Other fabric", which opens in place for a name that isn't listed.
 */
export function FabricChips({
    form,
    vocabulary,
    customFabrics,
}: {
    form: InertiaFormProps<BriefForm>;
    vocabulary: FashionVocabulary;
    customFabrics: CustomFabric[];
}) {
    const { t, tr, locale } = useI18n();
    const d = t.designer.brief;
    const f = d.customFabric;
    const [open, setOpen] = useState(false);
    const [value, setValue] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const [pending, setPending] = useState<string | null>(null);
    const trigger = useRef<HTMLButtonElement>(null);
    const builtIns = Object.entries(vocabulary.fabrics);
    const atLimit = customFabrics.length >= OPTION_LIMITS.fabric;
    const select = (key: string | null) => form.setData('fabric', key);

    const existing = (name: string): string | null => {
        const n = normalizeName(name);

        return (
            builtIns.find(
                ([, fabric]) =>
                    normalizeName(fabric.ar) === n ||
                    normalizeName(fabric.en) === n,
            )?.[0] ??
            customFabrics.find((c) => normalizeName(c.label) === n)?.key ??
            null
        );
    };

    const close = () => {
        setOpen(false);
        setValue('');
        setError(null);
        window.setTimeout(() => trigger.current?.focus(), 0);
    };

    // Saving finds the new fabric by name in the reloaded list and selects it.
    const save = (label: string, reselect: boolean) =>
        router.post(
            OPTIONS_URL,
            { type: 'fabric', label },
            {
                ...quiet,
                onSuccess: (page) => {
                    const fresh =
                        (page.props as { designer?: DesignerProps }).designer
                            ?.custom_fabrics ?? [];
                    const added = fresh.find(
                        (c) => normalizeName(c.label) === normalizeName(label),
                    );
                    if (added && reselect) {
                        select(added.key);
                        setNotice(tr(f.added, { name: label }));
                    }
                },
                onError: (errors) => {
                    setOpen(true);
                    setValue(label);
                    setError(errors.label ?? d.saveFailed);
                },
                onFinish: () => setPending(null),
            },
        );

    const submit = () => {
        const label = tidy(value);
        const problem = nameProblem(label);
        if (problem) {
            setError(f[problem]);
            return;
        }
        const known = existing(label);
        if (known) {
            select(known);
            setNotice(tr(f.exists, { name: label }));
            setOpen(false);
            setValue('');
            setError(null);
            return;
        }
        setPending(label);
        setOpen(false);
        setValue('');
        setError(null);
        save(label, true);
    };

    const remove = (fabric: CustomFabric) => {
        const wasSelected = form.data.fabric === fabric.key;
        router.delete(`${OPTIONS_URL}/${fabric.id}`, {
            ...quiet,
            onSuccess: () => {
                if (wasSelected) select(null);
                setNotice(null);
                toast(tr(d.removed, { name: fabric.label }), {
                    duration: 6000,
                    action: {
                        label: d.undo,
                        onClick: () => save(fabric.label, wasSelected),
                    },
                });
            },
        });
    };

    return (
        <div className="space-y-2">
            <div className="flex flex-wrap gap-1.5">
                {builtIns.map(([key, fabric]) => {
                    const active = form.data.fabric === key;

                    return (
                        <FabricChip
                            key={key}
                            label={fabric[locale]}
                            active={active}
                            sheer={fabric.sheer}
                            title={
                                locale === 'ar'
                                    ? fabric.drape_ar
                                    : fabric.drape_en
                            }
                            onClick={() => select(active ? null : key)}
                        />
                    );
                })}
                {customFabrics.map((c) => {
                    const active = form.data.fabric === c.key;

                    return (
                        <motion.span
                            key={c.key}
                            layout
                            initial={{ opacity: 0, scale: 0.9 }}
                            animate={{ opacity: 1, scale: 1 }}
                            className="group relative"
                        >
                            <FabricChip
                                label={c.label}
                                active={active}
                                custom
                                sheer={Boolean(
                                    c.base && vocabulary.fabrics[c.base]?.sheer,
                                )}
                                title={f.title}
                                onClick={() => select(active ? null : c.key)}
                            />
                            <RemoveButton
                                label={tr(d.remove, { name: c.label })}
                                onClick={() => remove(c)}
                            />
                        </motion.span>
                    );
                })}
                {pending && (
                    <span className="inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs opacity-60">
                        <Spinner className="size-3" />
                        {pending}
                    </span>
                )}
                <AnimatePresence initial={false} mode="popLayout">
                    {open ? (
                        <motion.div
                            key="composer"
                            layout
                            initial={{ opacity: 0, scale: 0.96 }}
                            animate={{ opacity: 1, scale: 1 }}
                            exit={{ opacity: 0, scale: 0.96 }}
                            onBlur={(e) => {
                                // Clicking away closes an empty composer only.
                                if (
                                    !e.currentTarget.contains(
                                        e.relatedTarget as Node | null,
                                    ) &&
                                    tidy(value) === ''
                                ) {
                                    close();
                                }
                            }}
                            className="flex basis-full items-center gap-1.5 rounded-full border border-gold/60 bg-gold-soft/40 py-1 ps-3 pe-1 ring-2 ring-gold/20"
                        >
                            <input
                                autoFocus
                                dir="auto"
                                maxLength={40}
                                enterKeyHint="done"
                                value={value}
                                onChange={(e) => {
                                    setValue(e.target.value);
                                    setError(null);
                                }}
                                onKeyDown={(e) => {
                                    // Inside the brief form: Enter must not start a design.
                                    if (e.key === 'Enter') {
                                        e.preventDefault();
                                        submit();
                                    } else if (e.key === 'Escape') {
                                        e.preventDefault();
                                        close();
                                    }
                                }}
                                placeholder={f.placeholder}
                                aria-label={f.addAria}
                                aria-invalid={Boolean(error)}
                                className="min-w-0 flex-1 bg-transparent text-base outline-none placeholder:text-muted-foreground sm:text-xs"
                            />
                            {value.length > 30 && (
                                <span className="num shrink-0 text-[10px] text-muted-foreground">
                                    {value.length}/40
                                </span>
                            )}
                            <Button
                                type="button"
                                size="sm"
                                variant="gold"
                                className="h-7 rounded-full px-3 text-xs"
                                onClick={submit}
                            >
                                {f.save}
                            </Button>
                            <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                className="size-7 rounded-full"
                                onClick={close}
                                aria-label={f.cancel}
                            >
                                <X className="size-3.5" />
                            </Button>
                        </motion.div>
                    ) : (
                        <motion.button
                            key="trigger"
                            ref={trigger}
                            layout
                            type="button"
                            disabled={atLimit}
                            title={atLimit ? f.limit : f.addAria}
                            aria-label={f.addAria}
                            onClick={() => {
                                setOpen(true);
                                setNotice(null);
                            }}
                            className="inline-flex cursor-pointer items-center gap-1 rounded-full border border-dashed px-3 py-1.5 text-xs text-muted-foreground transition-colors hover:border-foreground/30 hover:text-foreground disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <Plus className="size-3.5" />
                            {f.add}
                        </motion.button>
                    )}
                </AnimatePresence>
            </div>
            {(open || error || notice) && (
                <p
                    aria-live="polite"
                    className={cn(
                        'text-[11px] leading-relaxed',
                        error ? 'text-danger' : 'text-muted-foreground',
                    )}
                >
                    {error ?? notice ?? f.hint}
                </p>
            )}
        </div>
    );
}

/**
 * A section added by hand: its name and the garment the designer designs
 * it as, with a guess from the name the merchant can change.
 */
export function SectionComposer({
    vocabulary,
    categories,
    onAdded,
    onExisting,
    onCancel,
}: {
    vocabulary: FashionVocabulary;
    categories: StoreCategory[];
    onAdded: (section: { name: string; garment: GarmentKey }) => void;
    onExisting: (section: StoreCategory) => void;
    onCancel: () => void;
}) {
    const { t, locale } = useI18n();
    const d = t.designer.brief;
    const s = d.customSection;
    const [name, setName] = useState('');
    const [garment, setGarment] = useState<GarmentKey | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const firstGarment = useRef<HTMLButtonElement>(null);
    const suggestion = guessGarment(name);
    const chosen = garment ?? suggestion;
    const garments = Object.keys(vocabulary.garments) as GarmentKey[];

    const submit = () => {
        const label = tidy(name);
        const problem = nameProblem(label);
        if (problem) {
            setError(problem === 'short' ? s.short : d.customFabric[problem]);
            return;
        }
        const known = categories.find(
            (c) => c.garment && normalizeName(c.name) === normalizeName(label),
        );
        if (known) {
            onExisting(known);
            return;
        }
        if (!chosen) {
            setError(s.pickGarment);
            firstGarment.current?.focus();
            return;
        }
        setSaving(true);
        router.post(
            OPTIONS_URL,
            { type: 'section', label, garment: chosen },
            {
                ...quiet,
                onSuccess: () => onAdded({ name: label, garment: chosen }),
                onError: (errors) =>
                    setError(errors.label ?? errors.garment ?? d.saveFailed),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <motion.div
            initial={{ opacity: 0, height: 0 }}
            animate={{ opacity: 1, height: 'auto' }}
            exit={{ opacity: 0, height: 0 }}
            className="col-span-2 overflow-hidden"
        >
            <div className="space-y-3 rounded-xl border border-gold/60 bg-gold-soft/30 p-3">
                <p className="text-xs font-semibold">{s.title}</p>
                <div className="space-y-1.5">
                    <Label
                        htmlFor="designer-new-section"
                        className="text-[11px]"
                    >
                        {s.name}
                    </Label>
                    <Input
                        id="designer-new-section"
                        autoFocus
                        dir="auto"
                        maxLength={40}
                        enterKeyHint="done"
                        value={name}
                        onChange={(e) => {
                            setName(e.target.value);
                            setError(null);
                        }}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                if (chosen) submit();
                                else firstGarment.current?.focus();
                            } else if (e.key === 'Escape') {
                                e.preventDefault();
                                onCancel();
                            }
                        }}
                        placeholder={s.placeholder}
                        aria-invalid={Boolean(error)}
                        className="h-9 bg-card text-base sm:text-sm"
                    />
                </div>
                <div className="space-y-1.5">
                    <p className="text-[11px] font-medium">{s.designAs}</p>
                    <div
                        role="radiogroup"
                        aria-label={s.designAs}
                        className="grid grid-cols-4 gap-1.5"
                    >
                        {garments.map((key, i) => {
                            const active = chosen === key;

                            return (
                                <button
                                    key={key}
                                    ref={i === 0 ? firstGarment : undefined}
                                    type="button"
                                    role="radio"
                                    aria-checked={active}
                                    onClick={() => {
                                        setGarment(key);
                                        setError(null);
                                    }}
                                    className={cn(
                                        'relative flex cursor-pointer flex-col items-center gap-1 rounded-lg border bg-card px-1 pt-2 pb-1.5 text-[10px] leading-tight transition-all',
                                        active
                                            ? 'border-gold bg-gold-soft/60 text-foreground ring-2 ring-gold/25'
                                            : 'text-muted-foreground hover:border-foreground/25 hover:text-foreground',
                                    )}
                                >
                                    <GarmentIcon
                                        garment={key}
                                        className="h-7 w-6"
                                    />
                                    <span className="text-center">
                                        {vocabulary.garments[key][locale]}
                                    </span>
                                    {key === suggestion && garment === null && (
                                        <span className="absolute -top-1.5 rounded-full bg-gold px-1.5 text-[9px] font-medium text-gold-foreground">
                                            {s.suggested}
                                        </span>
                                    )}
                                </button>
                            );
                        })}
                    </div>
                    <p className="text-[10px] leading-relaxed text-muted-foreground">
                        {s.designAsHint}
                    </p>
                </div>
                {error && (
                    <p aria-live="polite" className="text-[11px] text-danger">
                        {error}
                    </p>
                )}
                <p className="text-[10px] leading-relaxed text-muted-foreground">
                    {s.studioOnly}
                </p>
                <div className="flex justify-end gap-2">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={onCancel}
                    >
                        {d.customFabric.cancel}
                    </Button>
                    <Button
                        type="button"
                        variant="gold"
                        size="sm"
                        disabled={
                            saving || nameProblem(name) !== null || !chosen
                        }
                        onClick={submit}
                    >
                        {saving && <Spinner className="size-3.5" />}
                        {s.save}
                    </Button>
                </div>
            </div>
        </motion.div>
    );
}

/**
 * Remove a custom section, with a few seconds to undo.
 */
export function removeSection(
    section: StoreCategory,
    {
        wasSelected,
        onRemoved,
        onRestored,
        labels,
    }: {
        wasSelected: boolean;
        onRemoved: () => void;
        onRestored: () => void;
        labels: { removed: string; undo: string };
    },
): void {
    if (section.id === null || !section.garment) return;
    const garment = section.garment;

    router.delete(`${OPTIONS_URL}/${section.id}`, {
        ...quiet,
        onSuccess: () => {
            onRemoved();
            toast(labels.removed, {
                duration: 6000,
                action: {
                    label: labels.undo,
                    onClick: () =>
                        router.post(
                            OPTIONS_URL,
                            { type: 'section', label: section.name, garment },
                            {
                                ...quiet,
                                onSuccess: () => wasSelected && onRestored(),
                            },
                        ),
                },
            });
        },
    });
}

export { RemoveButton };

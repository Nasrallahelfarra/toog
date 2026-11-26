import { router, usePage } from '@inertiajs/react';
import { AlertTriangle, ChevronDown, Lightbulb, WandSparkles } from 'lucide-react';
import { motion } from 'motion/react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { SectionCard } from '@/components/mojo/section-card';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';
import { isImageFile, ReferenceTray, useReferenceTray } from './reference-tray';
import type { Background, FaceMode, LookProps, PresentationMode } from './types';

const MODES: PresentationMode[] = ['model', 'mannequin', 'flat'];
const FACES: FaceMode[] = ['cropped', 'shown', 'hijab'];
const BACKGROUNDS: Background[] = ['studio', 'white', 'majlis', 'outdoor'];
const RATIOS = ['3:4', '4:5', '1:1', '9:16'] as const;
const PROMPT_SHOWN_MAX = 1000;

type Remembered = { mode: PresentationMode; face: FaceMode; background: Background };
const FALLBACK: Remembered = { mode: 'model', face: 'cropped', background: 'studio' };

function storageKey(storeId: number | undefined) {
    return `mojo:look-presentation:${storeId ?? 'none'}`;
}

function readRemembered(storeId: number | undefined): Remembered {
    try {
        const raw = window.localStorage.getItem(storageKey(storeId));
        const value = raw ? (JSON.parse(raw) as Partial<Remembered>) : {};

        return {
            mode: MODES.includes(value.mode as PresentationMode) ? (value.mode as PresentationMode) : FALLBACK.mode,
            face: FACES.includes(value.face as FaceMode) ? (value.face as FaceMode) : FALLBACK.face,
            background: BACKGROUNDS.includes(value.background as Background) ? (value.background as Background) : FALLBACK.background,
        };
    } catch {
        return FALLBACK;
    }
}

/** A row of choices; every option is a 44px target on touch screens. */
function Choice<T extends string>({ options, value, onChange, label, render, layoutId, ltr }: { options: readonly T[]; value: T; onChange: (v: T) => void; label: string; render: (v: T) => React.ReactNode; layoutId: string; ltr?: boolean }) {
    return (
        <div role="radiogroup" aria-label={label} className="bg-muted flex gap-1 rounded-xl p-1" dir={ltr ? 'ltr' : undefined}>
            {options.map((option) => (
                <button
                    key={option}
                    type="button"
                    role="radio"
                    aria-checked={value === option}
                    onClick={() => onChange(option)}
                    className={cn(
                        'relative min-h-9 min-w-0 flex-1 cursor-pointer rounded-lg px-1.5 text-xs font-medium transition-colors pointer-coarse:min-h-11',
                        value === option ? 'text-foreground' : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    {value === option && <motion.span layoutId={layoutId} className="bg-card absolute inset-0 rounded-lg shadow-sm" transition={{ type: 'spring', stiffness: 420, damping: 34 }} />}
                    <span className="relative block truncate">{render(option)}</span>
                </button>
            ))}
        </div>
    );
}

function Chips<T extends string>({ options, value, onChange, label, render }: { options: readonly T[]; value: T; onChange: (v: T) => void; label: string; render: (v: T) => string }) {
    return (
        <div role="radiogroup" aria-label={label} className="flex flex-wrap gap-1.5">
            {options.map((option) => (
                <button
                    key={option}
                    type="button"
                    role="radio"
                    aria-checked={value === option}
                    onClick={() => onChange(option)}
                    className={cn(
                        'min-h-9 cursor-pointer rounded-full border px-3 text-xs transition-colors pointer-coarse:min-h-11',
                        value === option ? 'border-primary bg-primary/5 text-primary font-medium' : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                    )}
                >
                    {render(option)}
                </button>
            ))}
        </div>
    );
}

/**
 * The free idea as a look: the merchant's sketches and fabric photos with
 * roles, one instruction in her words, and how the piece is shown.
 */
export function LookComposer({ look, prefillPrompt, since }: { look: LookProps; prefillPrompt?: string | null; since: number | null }) {
    const { t, tr } = useI18n();
    const s = t.studio.look;
    const c = s.composer;
    const storeId = usePage<{ currentStore?: { id: number } | null }>().props.currentStore?.id;
    const tray = useReferenceTray(look.limits);

    const [prompt, setPrompt] = useState(prefillPrompt ?? '');
    const [remembered, setRemembered] = useState<Remembered>(() => readRemembered(storeId));
    const [backView, setBackView] = useState(false);
    const [aspect, setAspect] = useState<(typeof RATIOS)[number]>('3:4');
    const [variants, setVariants] = useState(2);
    const [title, setTitle] = useState('');
    const [advanced, setAdvanced] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [dragging, setDragging] = useState(false);
    const dragDepth = useRef(0);
    const textareaRef = useRef<HTMLTextAreaElement>(null);

    useEffect(() => {
        try {
            window.localStorage.setItem(storageKey(storeId), JSON.stringify(remembered));
        } catch {
            // Private mode or blocked storage: the choice simply isn't remembered.
        }
    }, [remembered, storeId]);

    // The instruction box grows with its text.
    useLayoutEffect(() => {
        const el = textareaRef.current;
        if (!el) return;
        el.style.height = 'auto';
        el.style.height = `${Math.min(el.scrollHeight + 2, 360)}px`;
    }, [prompt]);

    // Ctrl/⌘+V anywhere on the page pastes images into the tray; plain text pastes as usual.
    const { add } = tray;
    useEffect(() => {
        const onPaste = (event: ClipboardEvent) => {
            const files = Array.from(event.clipboardData?.files ?? []).filter(isImageFile);
            if (files.length === 0) return;
            event.preventDefault();
            add(files);
        };
        window.addEventListener('paste', onPaste);

        return () => window.removeEventListener('paste', onPaste);
    }, [add]);

    const roles = new Set(tray.items.map((item) => item.role));
    const hasSketch = roles.has('sketch_front') || roles.has('sketch_back');
    const hasFabric = roles.has('fabric');
    const hasBack = roles.has('sketch_back');
    const placeholder = hasSketch && hasFabric ? c.placeholders.both : hasFabric ? c.placeholders.fabric : hasSketch ? c.placeholders.sketch : c.placeholders.none;
    const total = variants * (hasBack && backView ? 2 : 1);
    const blocked = look.budgetExceeded;
    const disabled = blocked || processing || tray.busy || prompt.trim() === '';

    const append = (text: string) => setPrompt((value) => (value.trim() ? `${value.trim()}، ${text}` : text));

    const imageErrors = Object.entries(errors)
        .filter(([key]) => key === 'images' || key.startsWith('images.'))
        .map(([, message]) => message);
    const presentationError = Object.entries(errors).find(([key]) => key.startsWith('presentation'))?.[1];
    const otherError = Object.entries(errors).find(([key]) => !['prompt', 'budget'].includes(key) && !key.startsWith('images') && !key.startsWith('presentation'))?.[1];

    const submit = async (event: React.FormEvent) => {
        event.preventDefault();
        if (disabled) return;
        setProcessing(true);
        setErrors({});

        const ready = tray.items.filter((item) => item.status === 'done').length;
        const images = await tray.ensureUploaded();
        // A picture that could not be sent again shows its retry; nothing is sent without it.
        if (images.length < ready) {
            setProcessing(false);

            return;
        }

        router.post(
            '/store/studio',
            {
                type: 'look',
                prompt: prompt.trim(),
                images,
                presentation: { mode: remembered.mode, face: remembered.face, background: remembered.background },
                back_view: hasBack && backView,
                aspect_ratio: aspect,
                variants_count: variants,
                ...(title.trim() ? { title: title.trim() } : {}),
                since,
            },
            {
                preserveScroll: true,
                // The form stays as it is after a submit: tweak a word and try again.
                preserveState: true,
                onSuccess: () => {
                    tray.markSent();
                    window.requestAnimationFrame(() => document.getElementById('look-results')?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
                },
                onError: (errs) => setErrors(errs as Record<string, string>),
                onFinish: () => setProcessing(false),
            },
        );
    };

    const dragProps = {
        onDragEnter: (event: React.DragEvent) => {
            if (!Array.from(event.dataTransfer.types).includes('Files')) return;
            event.preventDefault();
            dragDepth.current++;
            setDragging(true);
        },
        onDragOver: (event: React.DragEvent) => {
            if (Array.from(event.dataTransfer.types).includes('Files')) event.preventDefault();
        },
        onDragLeave: () => {
            dragDepth.current = Math.max(0, dragDepth.current - 1);
            if (dragDepth.current === 0) setDragging(false);
        },
        onDrop: (event: React.DragEvent) => {
            event.preventDefault();
            dragDepth.current = 0;
            setDragging(false);
            tray.add(Array.from(event.dataTransfer.files));
        },
    };

    return (
        <form id="studio-form" onSubmit={submit} {...dragProps} className="relative">
            <SectionCard title={c.heading} description={c.subtitle} icon={Lightbulb} className={cn('transition-shadow', dragging && 'ring-primary/40 ring-2')}>
                <div className="space-y-5">
                    <ReferenceTray tray={tray} dragging={dragging} error={imageErrors[0]} />

                    <div className="grid gap-2">
                        <div className="flex items-baseline justify-between gap-2">
                            <Label htmlFor="look-prompt" className="text-sm font-semibold">
                                {c.instruction}
                            </Label>
                            <span className={cn('num text-[11px]', prompt.length > PROMPT_SHOWN_MAX ? 'text-danger' : 'text-muted-foreground')} dir="ltr">
                                {prompt.length}/{PROMPT_SHOWN_MAX}
                            </span>
                        </div>
                        <textarea
                            ref={textareaRef}
                            id="look-prompt"
                            dir="auto"
                            rows={3}
                            value={prompt}
                            maxLength={PROMPT_SHOWN_MAX}
                            onChange={(e) => setPrompt(e.target.value)}
                            placeholder={placeholder}
                            aria-invalid={errors.prompt ? true : undefined}
                            className="border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:border-destructive min-h-24 w-full resize-none rounded-xl border bg-transparent px-3.5 py-3 text-[15px] leading-7 shadow-xs outline-none transition-[color,box-shadow] focus-visible:ring-[3px]"
                        />
                        {errors.prompt && <p className="text-danger text-xs">{errors.prompt}</p>}
                        <div className="flex flex-wrap items-center gap-1.5">
                            <span className="text-muted-foreground text-[11px]">{c.examplesLabel}</span>
                            {c.examples.map((example) => (
                                <button
                                    key={example}
                                    type="button"
                                    onClick={() => append(example)}
                                    className="hover:bg-muted min-h-8 cursor-pointer rounded-full border px-2.5 text-xs transition-colors pointer-coarse:min-h-11 pointer-coarse:px-3.5"
                                >
                                    {example}
                                </button>
                            ))}
                        </div>
                    </div>

                    <div className="grid gap-2.5">
                        <Label className="text-sm font-semibold">{c.presentation}</Label>
                        <Choice options={MODES} value={remembered.mode} onChange={(mode) => setRemembered((r) => ({ ...r, mode }))} label={c.presentation} render={(m) => c.modes[m]} layoutId="look-mode" />
                        {remembered.mode === 'model' && (
                            <Chips options={FACES} value={remembered.face} onChange={(face) => setRemembered((r) => ({ ...r, face }))} label={c.face} render={(f) => c.faces[f]} />
                        )}
                        {presentationError && <p className="text-danger text-xs">{presentationError}</p>}
                    </div>

                    {hasBack && (
                        <label className="hover:bg-muted/40 flex min-h-11 cursor-pointer items-center justify-between gap-3 rounded-xl border px-3.5 py-2.5">
                            <span className="min-w-0">
                                <span className="block text-sm font-medium">{c.backView}</span>
                                <span className="text-muted-foreground block text-[11px] leading-5">{c.backViewHint}</span>
                            </span>
                            <Switch checked={backView} onCheckedChange={setBackView} aria-label={c.backView} />
                        </label>
                    )}

                    <Collapsible open={advanced} onOpenChange={setAdvanced}>
                        <CollapsibleTrigger className="text-muted-foreground hover:text-foreground flex min-h-11 w-full cursor-pointer items-center justify-between gap-2 rounded-xl text-sm font-medium transition-colors">
                            {c.advanced}
                            <ChevronDown className={cn('size-4 transition-transform', advanced && 'rotate-180')} />
                        </CollapsibleTrigger>
                        <CollapsibleContent className="space-y-4 pt-2">
                            <div className="grid gap-2">
                                <Label>{c.aspect}</Label>
                                <Choice
                                    options={RATIOS}
                                    value={aspect}
                                    onChange={setAspect}
                                    label={c.aspect}
                                    layoutId="look-aspect"
                                    ltr
                                    render={(r) => (r === '9:16' ? <span dir="auto">{`${r} · ${c.story}`}</span> : r)}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label>{c.variants}</Label>
                                <Choice options={['1', '2', '3', '4'] as const} value={String(variants) as '1' | '2' | '3' | '4'} onChange={(v) => setVariants(Number(v))} label={c.variants} layoutId="look-variants" ltr render={(v) => <span className="num">{v}</span>} />
                            </div>
                            <div className="grid gap-2">
                                <Label>{c.background}</Label>
                                <Chips options={BACKGROUNDS} value={remembered.background} onChange={(background) => setRemembered((r) => ({ ...r, background }))} label={c.background} render={(b) => c.backgrounds[b]} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="look-title">{c.title}</Label>
                                <Input id="look-title" value={title} maxLength={160} onChange={(e) => setTitle(e.target.value)} placeholder={c.titlePlaceholder} className="h-11" />
                                {errors.title && <p className="text-danger text-xs">{errors.title}</p>}
                            </div>
                        </CollapsibleContent>
                    </Collapsible>

                    {(blocked || errors.budget) && (
                        <p role="alert" className="bg-warning-soft text-warning flex items-start gap-2 rounded-xl px-3.5 py-3 text-xs leading-relaxed font-medium">
                            <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                            {c.budget}
                        </p>
                    )}
                    {otherError && <p className="text-danger text-xs">{otherError}</p>}

                    <div className="space-y-2">
                        <Button type="submit" size="lg" variant="gold" className="h-12 w-full text-[15px]" disabled={disabled}>
                            {processing ? <Spinner /> : <WandSparkles className="size-4" />}
                            {processing ? c.sending : c.submit}
                        </Button>
                        <p className="text-muted-foreground text-center text-[11px]" aria-live="polite">
                            {tray.busy ? c.waitUploads : total === 1 ? c.totalOne : tr(c.total, { n: total })}
                        </p>
                    </div>
                </div>
            </SectionCard>
        </form>
    );
}

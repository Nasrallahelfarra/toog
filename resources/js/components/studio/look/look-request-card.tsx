import { Link, router } from '@inertiajs/react';
import { AlertTriangle, Check, Columns2, Download, Expand, Images, Loader2, RefreshCw, WandSparkles } from 'lucide-react';
import { motion } from 'motion/react';
import { useEffect, useState } from 'react';
import { aspectClass, updateDesign } from '@/components/studio/design-card';
import { DesignDialog } from '@/components/studio/design-dialog';
import { DesignEditor } from '@/components/studio/design-editor';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';
import { LookRefine } from './look-refine';
import { ROLE_ORDER } from './types';
import type { LookDesign, LookFailure, LookRequestImage, LookRequestRow, View } from './types';

export type Slot = {
    key: string;
    variant: number;
    view: View;
    design: LookDesign | null;
    failure: LookFailure | null;
    state: 'waiting' | 'generating' | 'done' | 'failed';
};

export const isGenerating = (request: { status: string }) => request.status === 'pending' || request.status === 'processing';

/**
 * Every image a look should end with, in order (variant, then front/back),
 * matched with what has arrived so far.
 */
export function lookSlots(request: LookRequestRow): Slot[] {
    const back = request.presentation?.back_view ?? false;
    const views: View[] = back ? ['front', 'back'] : ['front'];
    const count = Math.max(1, request.presentation ? request.variants_count : Math.ceil((request.slots ?? request.variants_count) / views.length));
    const generating = isGenerating(request);
    const designs = [...request.designs];
    const slots: Slot[] = [];

    for (let variant = 1; variant <= count; variant++) {
        for (const view of views) {
            slots.push({ key: `${variant}-${view}`, variant, view, design: null, failure: request.failures?.find((f) => f.variant === variant && f.view === view) ?? null, state: 'waiting' });
        }
    }

    // Designs tagged with their slot go there; untagged ones (older rows) fill the rest in order.
    for (const slot of slots) {
        const i = designs.findIndex((d) => d.variant === slot.variant && (d.view ?? 'front') === slot.view);
        if (i >= 0) slot.design = designs.splice(i, 1)[0];
    }
    for (const slot of slots) {
        if (!slot.design && !slot.failure && designs.length) slot.design = designs.shift() ?? null;
    }
    // Anything still unplaced (more images than expected) is shown too.
    designs.forEach((design, i) => slots.push({ key: `extra-${design.id}`, variant: count + i + 1, view: design.view ?? 'front', design, failure: null, state: 'done' }));

    let started = false;
    for (const slot of slots) {
        if (slot.design) slot.state = 'done';
        else if (slot.failure) slot.state = 'failed';
        else if (!generating) slot.state = 'failed';
        else if (request.status === 'processing' && !started) {
            slot.state = 'generating';
            started = true;
        } else slot.state = 'waiting';
    }

    return slots;
}

function Elapsed({ since }: { since: string }) {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 1000);

        return () => window.clearInterval(timer);
    }, []);

    const seconds = Math.max(0, Math.floor((now - new Date(since).getTime()) / 1000));

    return (
        <span className="num tabular-nums" dir="ltr">
            {Math.floor(seconds / 60)}:{String(seconds % 60).padStart(2, '0')}
        </span>
    );
}

function retry(requestId: number) {
    router.post(`/store/studio/${requestId}/retry`, {}, { preserveScroll: true, preserveState: true });
}

function IconAction({ label, onClick, href, active, children }: { label: string; onClick?: () => void; href?: string; active?: boolean; children: React.ReactNode }) {
    const className = cn(
        'text-muted-foreground hover:bg-muted hover:text-foreground flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-lg transition-colors pointer-coarse:size-11',
        active && 'bg-success-soft text-success hover:bg-success-soft hover:text-success',
    );

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                {href ? (
                    href.startsWith('/store/designs/') ? (
                        <a href={href} aria-label={label} className={className}>
                            {children}
                        </a>
                    ) : (
                        <Link href={href} aria-label={label} className={className}>
                            {children}
                        </Link>
                    )
                ) : (
                    <button type="button" onClick={onClick} aria-label={label} aria-pressed={active || undefined} className={className}>
                        {children}
                    </button>
                )}
            </TooltipTrigger>
            <TooltipContent>{label}</TooltipContent>
        </Tooltip>
    );
}

function ResultTile({ design, caption }: { design: LookDesign; caption: string }) {
    const { t } = useI18n();
    const r = t.studio.look.results;
    const [open, setOpen] = useState(false);
    const [refining, setRefining] = useState(false);
    const [editing, setEditing] = useState(false);
    const approved = design.status === 'approved';

    return (
        <motion.div layout initial={{ opacity: 0, scale: 0.97 }} animate={{ opacity: 1, scale: 1 }} className="bg-card group min-w-0 overflow-hidden rounded-2xl border border-border/70 shadow-sm">
            <div className={cn('bg-muted relative overflow-hidden', aspectClass[design.aspect_ratio] ?? 'aspect-[3/4]')}>
                <img src={design.image_url} alt={design.title} loading="lazy" onClick={() => setOpen(true)} className="size-full cursor-zoom-in object-cover transition-transform duration-500 group-hover:scale-[1.03]" />
                <span className="absolute start-2 top-2 rounded-full bg-black/55 px-2 py-0.5 text-[11px] font-medium text-white backdrop-blur">{caption}</span>
                {approved && (
                    <span className="bg-success absolute end-2 top-2 flex size-7 items-center justify-center rounded-full text-white shadow" aria-label={r.approved}>
                        <Check className="size-4" />
                    </span>
                )}
                <button
                    type="button"
                    onClick={() => setOpen(true)}
                    aria-label={r.open}
                    className="absolute end-2 bottom-2 flex size-9 cursor-pointer items-center justify-center rounded-full bg-black/40 text-white opacity-0 backdrop-blur transition-opacity group-hover:opacity-100 focus-visible:opacity-100 pointer-coarse:size-11 pointer-coarse:opacity-100"
                >
                    <Expand className="size-4" />
                </button>
            </div>
            <div className="space-y-1.5 p-2">
                <Button type="button" variant="soft" className="h-10 w-full pointer-coarse:h-11" onClick={() => setRefining(true)}>
                    <WandSparkles className="size-4" />
                    <span className="truncate">{r.refine}</span>
                </Button>
                <div className="flex items-center justify-between gap-0.5">
                    <IconAction label={approved ? r.approved : r.approve} active={approved} onClick={() => updateDesign(design.id, { status: approved ? 'draft' : 'approved' })}>
                        <Check className="size-4" />
                    </IconAction>
                    <IconAction label={r.library} href="/store/library">
                        <Images className="size-4" />
                    </IconAction>
                    <IconAction label={r.download} href={`/store/designs/${design.id}/download`}>
                        <Download className="size-4" />
                    </IconAction>
                </div>
            </div>
            <DesignDialog design={design} open={open} onOpenChange={setOpen} />
            {refining && <LookRefine design={design} open={refining} onOpenChange={setRefining} onMore={() => setEditing(true)} />}
            {editing && <DesignEditor design={design} open={editing} onOpenChange={setEditing} />}
        </motion.div>
    );
}

function SlotTile({ request, slot, caption }: { request: LookRequestRow; slot: Slot; caption: string }) {
    const { t } = useI18n();
    const r = t.studio.look.results;
    const aspect = aspectClass[request.aspect_ratio] ?? 'aspect-[3/4]';

    if (slot.design) {
        return <ResultTile design={slot.design} caption={caption} />;
    }

    return (
        <div className="min-w-0 space-y-1.5">
            <div
                className={cn(
                    'relative flex flex-col items-center justify-center gap-2 overflow-hidden rounded-2xl border p-3 text-center text-xs',
                    aspect,
                    slot.state === 'failed' ? 'bg-danger-soft/60 border-danger/20 text-danger' : 'bg-muted/60 text-muted-foreground border-transparent',
                    slot.state === 'generating' && 'shimmer',
                )}
                role="status"
            >
                <span className="absolute start-2 top-2 rounded-full bg-black/40 px-2 py-0.5 text-[11px] font-medium text-white">{caption}</span>
                {slot.state === 'generating' && (
                    <>
                        <WandSparkles className="text-primary size-6 animate-pulse" />
                        <span className="text-foreground font-medium">{r.generating}</span>
                        <Elapsed since={request.created_at} />
                    </>
                )}
                {slot.state === 'waiting' && (
                    <>
                        <Loader2 className="size-5 animate-spin opacity-60" />
                        <span>{r.waiting}</span>
                    </>
                )}
                {slot.state === 'failed' && (
                    <>
                        <AlertTriangle className="size-5" />
                        <span className="leading-relaxed font-medium">{r.failed}</span>
                        {!isGenerating(request) && (
                            <Button type="button" size="sm" variant="outline" className="h-10 bg-card pointer-coarse:h-11" onClick={() => retry(request.id)}>
                                <RefreshCw className="size-3.5" />
                                {r.retry}
                            </Button>
                        )}
                    </>
                )}
            </div>
        </div>
    );
}

function InputThumb({ image, className }: { image: LookRequestImage; className?: string }) {
    const { t } = useI18n();

    return (
        <figure className={cn('min-w-0', className)}>
            <img src={image.url} alt={t.studio.look.roles[image.role]} className="bg-muted aspect-square w-full rounded-lg border object-cover" loading="lazy" />
            <figcaption className="text-muted-foreground mt-1 truncate text-center text-[10px]">{t.studio.look.roles[image.role]}</figcaption>
        </figure>
    );
}

/** The result beside the pictures it was made from: side by side on desktop, a swipe on phones. */
function CompareRow({ request, slot, caption }: { request: LookRequestRow; slot: Slot; caption: string }) {
    const { t } = useI18n();
    const r = t.studio.look.results;
    const images = request.images ?? [];
    const sketch = images.find((i) => i.role === (slot.view === 'back' ? 'sketch_back' : 'sketch_front')) ?? images.find((i) => i.role === 'sketch_front');
    const refs = [...(sketch ? [sketch] : []), ...images.filter((i) => i.role === 'fabric' || i.role === 'detail')];
    const aspect = aspectClass[request.aspect_ratio] ?? 'aspect-[3/4]';

    if (!slot.design) return null;

    return (
        <div className="-mx-4 flex snap-x snap-mandatory gap-3 overflow-x-auto px-4 pb-1 md:mx-0 md:grid md:snap-none md:grid-cols-3 md:overflow-visible md:px-0">
            <figure className="w-[72%] shrink-0 snap-center md:w-auto">
                <img src={slot.design.image_url} alt={slot.design.title} className={cn('bg-muted w-full rounded-xl border object-cover', aspect)} />
                <figcaption className="mt-1 text-center text-[11px] font-medium">
                    {r.result} · {caption}
                </figcaption>
            </figure>
            {refs.map((image) => (
                <figure key={image.id} className="w-[72%] shrink-0 snap-center md:w-auto">
                    <img src={image.url} alt={t.studio.look.roles[image.role]} className={cn('bg-muted w-full rounded-xl border object-contain', aspect)} />
                    <figcaption className="text-muted-foreground mt-1 text-center text-[11px]">{t.studio.look.roles[image.role]}</figcaption>
                </figure>
            ))}
        </div>
    );
}

/**
 * A look request: the pictures and words that went in, then one tile per
 * expected image as it arrives (front and back for each variant).
 */
export function LookRequestCard({ request }: { request: LookRequestRow }) {
    const { t, tr, relative } = useI18n();
    const s = t.studio;
    const r = s.look.results;
    const [comparing, setComparing] = useState(false);
    const slots = lookSlots(request);
    const generating = isGenerating(request);
    const done = slots.filter((slot) => slot.design).length;
    const back = request.presentation?.back_view ?? false;
    const images = [...(request.images ?? [])].sort((a, b) => ROLE_ORDER.indexOf(a.role) - ROLE_ORDER.indexOf(b.role));
    const canCompare = images.length > 0 && done > 0;
    const statusTone = { pending: 'neutral', processing: 'info', completed: 'success', failed: 'danger' } as const;
    const cols = slots.length === 1 ? 'grid-cols-1 max-w-xs' : slots.length === 3 ? 'grid-cols-2 sm:grid-cols-3' : slots.length >= 4 ? 'grid-cols-2 xl:grid-cols-4' : 'grid-cols-2';
    const caption = (slot: Slot) => (back ? `${tr(r.variant, { n: slot.variant })} · ${r.views[slot.view]}` : slots.length > 1 ? tr(r.variant, { n: slot.variant }) : r.views[slot.view]);

    return (
        <motion.section id={`request-${request.id}`} layout initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} className="bg-card scroll-mt-24 space-y-4 rounded-2xl border border-border/70 p-4 shadow-sm">
            <header className="space-y-3">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <div className="min-w-0 space-y-1">
                        <h3 className="truncate text-sm font-semibold">{request.title ?? s.form.types.look}</h3>
                        <div className="text-muted-foreground flex flex-wrap items-center gap-1.5 text-[11px]">
                            {request.presentation && <span>{s.look.composer.modes[request.presentation.mode]}</span>}
                            {request.presentation?.mode === 'model' && (
                                <>
                                    <span>·</span>
                                    <span>{s.look.composer.faces[request.presentation.face]}</span>
                                </>
                            )}
                            <span>·</span>
                            <span dir="ltr">{request.aspect_ratio}</span>
                            <span>·</span>
                            <span suppressHydrationWarning>{relative(request.created_at)}</span>
                        </div>
                    </div>
                    <div className="flex items-center gap-1.5">
                        {canCompare && (
                            <Button type="button" size="sm" variant={comparing ? 'soft' : 'outline'} className="h-9 pointer-coarse:h-11" aria-pressed={comparing} onClick={() => setComparing((v) => !v)}>
                                <Columns2 className="size-3.5" />
                                {r.compare}
                            </Button>
                        )}
                        <Badge variant={statusTone[request.status]}>
                            {generating && <Loader2 className="animate-spin" />}
                            {generating ? (
                                <span className="num" dir="ltr">
                                    {done}/{slots.length}
                                </span>
                            ) : (
                                s.requests.status[request.status]
                            )}
                        </Badge>
                    </div>
                </div>

                <div className="bg-muted/40 flex gap-3 rounded-xl p-2.5">
                    {images.length > 0 && (
                        <div className="flex shrink-0 gap-1.5">
                            {images.slice(0, 4).map((image) => (
                                <InputThumb key={image.id} image={image} className="w-12" />
                            ))}
                            {images.length > 4 && <span className="bg-muted text-muted-foreground num flex size-12 items-center justify-center rounded-lg text-xs">+{images.length - 4}</span>}
                        </div>
                    )}
                    <p dir="auto" className={cn('min-w-0 flex-1 text-xs leading-relaxed whitespace-pre-line', !request.prompt && 'text-muted-foreground')}>
                        <span className="line-clamp-4">{request.prompt || r.noPrompt}</span>
                    </p>
                </div>
            </header>

            {request.status === 'failed' && request.error && done === 0 && (
                <div className="bg-danger-soft text-danger flex items-center justify-between gap-3 rounded-xl px-3 py-2 text-xs">
                    <span className="flex items-center gap-2">
                        <AlertTriangle className="size-4 shrink-0" />
                        {request.error}
                    </span>
                </div>
            )}

            {comparing ? (
                <div className="space-y-5">
                    <p className="text-muted-foreground text-[11px] md:hidden">{r.compareHint}</p>
                    {slots.map((slot) => (
                        <CompareRow key={slot.key} request={request} slot={slot} caption={caption(slot)} />
                    ))}
                </div>
            ) : (
                <div className={cn('grid gap-3', cols)}>
                    {slots.map((slot) => (
                        <SlotTile key={slot.key} request={request} slot={slot} caption={caption(slot)} />
                    ))}
                </div>
            )}
            {generating && <p className="text-muted-foreground text-center text-xs">{s.requests.generatingHint}</p>}
        </motion.section>
    );
}

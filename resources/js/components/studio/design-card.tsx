import { Link, router } from '@inertiajs/react';
import { Check, Columns2, Download, Expand, Heart, Send, WandSparkles, X } from 'lucide-react';
import { motion } from 'motion/react';
import { useState } from 'react';
import type { DesignItem } from '@/components/evaluation/types';
import { scoreTone, toneClasses } from '@/components/mojo/tone';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';
import { CompareSlider } from './compare-slider';
import { DesignDialog } from './design-dialog';
import { DesignEditor } from './design-editor';

export const aspectClass: Record<string, string> = {
    '1:1': 'aspect-square',
    '4:5': 'aspect-[4/5]',
    '3:4': 'aspect-[3/4]',
    '9:16': 'aspect-[9/16]',
    '16:9': 'aspect-video',
};

const statusVariant = { draft: 'neutral', in_review: 'info', approved: 'success', rejected: 'danger' } as const;

export function useDesignLabels() {
    const { t } = useI18n();

    /** Variant key stored as a "variant:x" tag by the generator. */
    const variantOf = (d: DesignItem) => {
        const tag = d.tags?.find((x) => x.startsWith('variant:'))?.slice(8);

        return tag ? (t.studio.variants[tag as keyof typeof t.studio.variants] ?? tag) : null;
    };

    /** Human tags: hides generator tags, translates known ones. */
    const tagLabel = (tag: string) => {
        if (tag.startsWith('variant:')) {
            return t.studio.variants[tag.slice(8) as keyof typeof t.studio.variants] ?? tag.slice(8);
        }

        if (tag.startsWith('type:')) {
            return t.studio.tags[tag.slice(5) as keyof typeof t.studio.tags] ?? tag.slice(5);
        }

        return tag;
    };

    return { variantOf, tagLabel };
}

export function updateDesign(id: number, data: Record<string, string | boolean | string[]>) {
    router.patch(`/store/designs/${id}`, data, { preserveScroll: true, preserveState: true });
}

/**
 * One generated design with its auto-evaluation score (FR-4.7) and the
 * review actions (FR-4.8).
 */
export function DesignCard({
    design,
    thresholds,
    className,
    before,
}: {
    design: DesignItem;
    thresholds?: { accept: number; reject: number };
    className?: string;
    /** The design this one was edited from, for the before/after view. */
    before?: { image_url: string; title: string } | null;
}) {
    const { t } = useI18n();
    const d = t.studio.design;
    const { variantOf } = useDesignLabels();
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(false);
    const [comparing, setComparing] = useState(false);
    const tone = toneClasses[scoreTone(design.score, thresholds?.accept, thresholds?.reject)];
    const variant = variantOf(design);

    const action = (label: string, icon: React.ReactNode, onClick: () => void, active = false) => (
        <Tooltip>
            <TooltipTrigger asChild>
                <button
                    type="button"
                    onClick={onClick}
                    aria-label={label}
                    aria-pressed={active || undefined}
                    className={cn(
                        'text-muted-foreground hover:bg-muted hover:text-foreground flex size-8 cursor-pointer items-center justify-center rounded-lg transition-colors',
                        active && 'bg-primary/10 text-primary',
                    )}
                >
                    {icon}
                </button>
            </TooltipTrigger>
            <TooltipContent>{label}</TooltipContent>
        </Tooltip>
    );

    return (
        <motion.div layout initial={{ opacity: 0, scale: 0.97 }} animate={{ opacity: 1, scale: 1 }} className={cn('bg-card group overflow-hidden rounded-2xl border border-border/70 shadow-sm', className)}>
            <div className={cn('bg-muted relative overflow-hidden', aspectClass[design.aspect_ratio] ?? 'aspect-square')}>
                <img
                    src={design.image_url}
                    alt={design.title}
                    loading="lazy"
                    className="size-full cursor-zoom-in object-cover transition-transform duration-500 group-hover:scale-[1.04]"
                    onClick={() => setOpen(true)}
                />
                {design.score !== null && (
                    <span dir="ltr" className={cn('absolute top-2 start-2 flex items-center gap-0.5 rounded-full px-2 py-0.5 text-xs font-semibold shadow-sm backdrop-blur', tone.soft, tone.text)}>
                        <span className="num">{design.score}</span>
                        <span className="opacity-70">/100</span>
                    </span>
                )}
                <button
                    type="button"
                    onClick={() => updateDesign(design.id, { is_favorite: !design.is_favorite })}
                    aria-label={design.is_favorite ? d.unfavorite : d.favorite}
                    className="absolute top-2 end-2 flex size-8 cursor-pointer items-center justify-center rounded-full bg-black/35 text-white backdrop-blur transition-transform hover:scale-110"
                >
                    <Heart className={cn('size-4', design.is_favorite && 'fill-rose-400 text-rose-400')} />
                </button>
                <button
                    type="button"
                    onClick={() => setOpen(true)}
                    className="absolute end-2 bottom-2 flex size-8 cursor-pointer items-center justify-center rounded-full bg-black/35 text-white opacity-0 backdrop-blur transition-opacity group-hover:opacity-100"
                >
                    <Expand className="size-4" />
                </button>
            </div>
            <div className="space-y-2 p-3">
                <div className="flex items-center justify-between gap-2">
                    <span className="truncate text-sm font-medium">{variant ?? design.title}</span>
                    <Badge variant={statusVariant[design.status]}>{d.status[design.status]}</Badge>
                </div>
                <div className="flex items-center justify-between gap-1">
                    <div className="flex items-center gap-0.5">
                        {action(d.approve, <Check className="size-4" />, () => updateDesign(design.id, { status: 'approved' }), design.status === 'approved')}
                        {action(d.reject, <X className="size-4" />, () => updateDesign(design.id, { status: 'rejected' }), design.status === 'rejected')}
                        {action(d.sendReview, <Send className="size-4 rtl:-scale-x-100" />, () => updateDesign(design.id, { status: 'in_review' }), design.status === 'in_review')}
                    </div>
                    <div className="flex items-center gap-0.5">
                        {action(d.edit, <WandSparkles className="size-4" />, () => setEditing(true))}
                        {before && action(d.compare, <Columns2 className="size-4" />, () => setComparing(true))}
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <a
                                    href={`/store/designs/${design.id}/download`}
                                    className="text-muted-foreground hover:bg-muted hover:text-foreground flex size-8 items-center justify-center rounded-lg"
                                >
                                    <Download className="size-4" />
                                </a>
                            </TooltipTrigger>
                            <TooltipContent>{d.download}</TooltipContent>
                        </Tooltip>
                        {design.evaluation_id && (
                            <Link href={`/store/evaluations/${design.evaluation_id}`} className="text-primary px-1.5 text-[11px] font-medium whitespace-nowrap hover:underline">
                                {d.viewEvaluation}
                            </Link>
                        )}
                    </div>
                </div>
            </div>
            <DesignDialog design={design} open={open} onOpenChange={setOpen} />
            {editing && <DesignEditor design={design} open={editing} onOpenChange={setEditing} />}
            {before && (
                <Dialog open={comparing} onOpenChange={setComparing}>
                    <DialogContent className="sm:max-w-3xl">
                        <DialogHeader className="text-start sm:text-start">
                            <DialogTitle>{t.studio.editor.compareTitle}</DialogTitle>
                            <DialogDescription>{t.studio.editor.compareHint}</DialogDescription>
                        </DialogHeader>
                        <CompareSlider before={before.image_url} after={design.image_url} className={cn('mx-auto max-h-[70dvh]', aspectClass[design.aspect_ratio] ?? 'aspect-square')} />
                    </DialogContent>
                </Dialog>
            )}
        </motion.div>
    );
}

import { router } from '@inertiajs/react';
import {
    AlertTriangle,
    Check,
    FileText,
    Loader2,
    RefreshCw,
    Repeat2,
    Scissors,
    Trash2,
} from 'lucide-react';
import { AnimatePresence, motion } from 'motion/react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/mojo/confirm-dialog';
import { scoreTone, toneClasses } from '@/components/mojo/tone';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/hooks/use-i18n';
import { useVocabulary } from '@/hooks/use-vocabulary';
import { cn } from '@/lib/utils';
import { DesignSheet } from './design-sheet';
import { GarmentIcon } from './garment-icons';
import { SourceBadge } from './source-badge';
import type { Collection, Concept, FashionVocabulary } from './types';

/**
 * One AI-designer collection as a lookbook: its title and story, the
 * fabric it was built on, progress while concepts are drawn, and the
 * concept cards that open their design sheets.
 */
export function CollectionView({
    collection,
    vocabulary,
    onSameFabric,
}: {
    collection: Collection;
    vocabulary: FashionVocabulary;
    onSameFabric: (c: Collection) => void;
}) {
    const { t, tr, locale, field, relative } = useI18n();
    const c = t.designer.collection;
    const [open, setOpen] = useState<number | null>(null);
    const active =
        collection.status === 'pending' || collection.status === 'processing';
    const brief = collection.brief;
    const fabric = brief.fabric ? vocabulary.fabrics[brief.fabric] : null;
    const occasion = vocabulary.occasions[brief.occasion];
    const title = field(collection, 'title') || c.newCollection;
    const story = field(collection, 'story');
    const total = collection.concepts.length || brief.count;
    const cols =
        total >= 4
            ? 'sm:grid-cols-2 2xl:grid-cols-4'
            : total === 3
              ? 'sm:grid-cols-2 xl:grid-cols-3'
              : total === 2
                ? 'sm:grid-cols-2'
                : 'sm:grid-cols-2';

    return (
        <motion.section
            layout
            initial={{ opacity: 0, y: 12 }}
            animate={{ opacity: 1, y: 0 }}
            className="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm"
        >
            <header className="relative overflow-hidden border-b px-5 py-5 sm:px-6">
                <div
                    className="bg-sadu pointer-events-none absolute inset-0 text-gold opacity-[0.05]"
                    aria-hidden
                />
                <div className="relative flex flex-wrap items-start justify-between gap-4">
                    <div className="flex min-w-0 items-start gap-4">
                        {collection.fabric_image_url ? (
                            <img
                                src={collection.fabric_image_url}
                                alt={c.fabricPhoto}
                                className="size-16 shrink-0 rounded-full object-cover shadow-sm ring-4 ring-white/70 dark:ring-white/10"
                            />
                        ) : (
                            <span className="flex size-16 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                                <GarmentIcon
                                    garment={brief.garment}
                                    className="h-9 w-8"
                                />
                            </span>
                        )}
                        <div className="min-w-0 space-y-1.5">
                            <p className="text-[11px] font-medium tracking-wide text-gold-foreground dark:text-gold">
                                {c.newCollection} ·{' '}
                                {occasion?.[locale] ?? brief.occasion}
                            </p>
                            <h2 className="font-display text-2xl leading-snug font-semibold text-balance sm:text-[28px]">
                                {active && !collection.title_ar ? (
                                    <span className="shimmer inline-block h-8 w-64 rounded-lg bg-muted/70" />
                                ) : (
                                    title
                                )}
                            </h2>
                            {story && (
                                <p className="max-w-3xl text-sm leading-relaxed text-muted-foreground">
                                    {story}
                                </p>
                            )}
                            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 pt-1 text-[11px] text-muted-foreground">
                                <span className="inline-flex items-center gap-1 font-medium text-foreground/80">
                                    <GarmentIcon
                                        garment={brief.garment}
                                        className="h-4 w-3.5"
                                    />
                                    {
                                        vocabulary.garments[brief.garment]?.[
                                            locale
                                        ]
                                    }
                                </span>
                                {fabric && (
                                    <span className="inline-flex items-center gap-1">
                                        <Scissors className="size-3" />
                                        {fabric[locale]}
                                    </span>
                                )}
                                <span>
                                    {
                                        t.designer.brief.creativityStops[
                                            brief.creativity - 1
                                        ]
                                    }
                                </span>
                                <span>
                                    {vocabulary.renders[brief.render]?.[locale]}
                                </span>
                                <span>{tr(c.designs, { n: total })}</span>
                                <span suppressHydrationWarning>
                                    {relative(collection.created_at)}
                                </span>
                                {!active && (
                                    <span>
                                        {collection.from_model
                                            ? c.byModel
                                            : c.byTemplate}
                                    </span>
                                )}
                            </div>
                        </div>
                    </div>
                    {!active && (
                        <div className="flex shrink-0 gap-1.5">
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => onSameFabric(collection)}
                            >
                                <Repeat2 className="size-3.5" />
                                {c.sameFabric}
                            </Button>
                            <ConfirmDialog
                                trigger={
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        className="size-8 text-muted-foreground"
                                        aria-label={c.delete}
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                }
                                title={c.delete}
                                description={c.deleteConfirm}
                                confirmLabel={c.delete}
                                destructive
                                onConfirm={() =>
                                    router.delete(
                                        `/store/studio/collections/${collection.id}`,
                                        { preserveScroll: true },
                                    )
                                }
                            />
                        </div>
                    )}
                </div>
            </header>

            {active && <Progress collection={collection} />}

            {collection.status === 'failed' &&
                collection.concepts.every((x) => x.status !== 'ready') && (
                    <div className="mx-5 mt-5 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-danger-soft px-4 py-3 text-sm text-danger sm:mx-6">
                        <span className="flex items-center gap-2">
                            <AlertTriangle className="size-4 shrink-0" />
                            {c.failed}
                        </span>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    `/store/studio/collections/${collection.id}/retry`,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <RefreshCw className="size-3.5" />
                            {c.retry}
                        </Button>
                    </div>
                )}

            <div className={cn('grid gap-4 p-5 sm:p-6', cols)}>
                {collection.concepts.length > 0
                    ? collection.concepts.map((concept, i) => (
                          <ConceptCard
                              key={i}
                              concept={concept}
                              index={i}
                              collection={collection}
                              vocabulary={vocabulary}
                              busy={active}
                              onOpen={() => setOpen(i)}
                          />
                      ))
                    : Array.from({ length: brief.count }).map((_, i) => (
                          <Placeholder
                              key={i}
                              garment={brief.garment}
                              label={c.waiting}
                          />
                      ))}
            </div>

            <DesignSheet
                collection={collection}
                concept={
                    open !== null ? (collection.concepts[open] ?? null) : null
                }
                vocabulary={vocabulary}
                onOpenChange={(v) => !v && setOpen(null)}
            />
        </motion.section>
    );
}

function Progress({ collection }: { collection: Collection }) {
    const { t, tr } = useI18n();
    const c = t.designer.collection;
    const concepts = collection.concepts;
    const done = concepts.filter(
        (x) => x.status === 'ready' || x.status === 'failed',
    ).length;
    const planned = concepts.length > 0;
    const steps = [
        ...(collection.fabric_image_url
            ? [{ key: 'fabric', label: c.steps.fabric }]
            : []),
        { key: 'data', label: c.steps.data },
        { key: 'write', label: c.steps.write },
        {
            key: 'draw',
            label: tr(c.steps.draw, {
                i: Math.min(
                    done + 1,
                    concepts.length || collection.brief.count,
                ),
                n: concepts.length || collection.brief.count,
            }),
        },
        { key: 'score', label: c.steps.score },
    ];
    const current = planned
        ? done >= concepts.length
            ? steps.length - 1
            : steps.length - 2
        : steps.length - 3;

    return (
        <div className="border-b px-5 py-4 sm:px-6" aria-live="polite">
            <ol className="flex flex-wrap items-center gap-x-2 gap-y-2">
                {steps.map((step, i) => (
                    <li key={step.key} className="flex items-center gap-2">
                        <span
                            className={cn(
                                'flex size-5 items-center justify-center rounded-full text-[10px] transition-colors',
                                i < current
                                    ? 'bg-success text-white'
                                    : i === current
                                      ? 'bg-gold text-gold-foreground'
                                      : 'bg-muted text-muted-foreground',
                            )}
                        >
                            {i < current ? (
                                <Check className="size-3" />
                            ) : i === current ? (
                                <Loader2 className="size-3 animate-spin" />
                            ) : (
                                i + 1
                            )}
                        </span>
                        <span
                            className={cn(
                                'text-xs',
                                i === current
                                    ? 'font-medium text-foreground'
                                    : 'text-muted-foreground',
                            )}
                        >
                            {step.label}
                        </span>
                        {i < steps.length - 1 && (
                            <span className="hidden h-px w-6 bg-border sm:block" />
                        )}
                    </li>
                ))}
            </ol>
            <p className="mt-2 text-[11px] text-muted-foreground">
                {c.leaveHint}
            </p>
        </div>
    );
}

function ConceptCard({
    concept,
    index,
    collection,
    vocabulary,
    busy,
    onOpen,
}: {
    concept: Concept;
    index: number;
    collection: Collection;
    vocabulary: FashionVocabulary;
    busy: boolean;
    onOpen: () => void;
}) {
    const { t, tr, locale, field } = useI18n();
    const { hex } = useVocabulary();
    const c = t.designer.collection;
    const ready = concept.status === 'ready' && concept.image_url;
    const ratio = concept.render === 'flat' ? 'aspect-square' : 'aspect-[3/4]';
    const silhouette =
        vocabulary.silhouettes[collection.brief.garment]?.[
            concept.silhouette
        ]?.[locale];
    const tone =
        concept.score !== undefined ? scoreTone(concept.score) : 'neutral';

    return (
        <motion.article
            layout
            className="group flex flex-col overflow-hidden rounded-2xl border border-border/70 bg-background transition-shadow hover:shadow-md"
        >
            <div className={cn('relative overflow-hidden bg-muted', ratio)}>
                <AnimatePresence mode="wait">
                    {ready ? (
                        <motion.button
                            key={concept.image_url}
                            type="button"
                            onClick={onOpen}
                            initial={{ opacity: 0, scale: 1.03 }}
                            animate={{ opacity: 1, scale: 1 }}
                            transition={{ duration: 0.5 }}
                            className="block size-full cursor-zoom-in"
                            aria-label={c.sheet}
                        >
                            <img
                                src={concept.image_url!}
                                alt={field(concept, 'name')}
                                className="size-full object-cover transition-transform duration-700 group-hover:scale-[1.03]"
                            />
                        </motion.button>
                    ) : concept.status === 'failed' ? (
                        <motion.div
                            key="failed"
                            initial={{ opacity: 0 }}
                            animate={{ opacity: 1 }}
                            className="flex size-full flex-col items-center justify-center gap-3 p-4 text-center"
                        >
                            <AlertTriangle className="size-6 text-danger" />
                            <p className="text-xs text-muted-foreground">
                                {c.conceptFailed}
                            </p>
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={busy}
                                onClick={() =>
                                    router.post(
                                        `/store/studio/collections/${collection.id}/concepts/${index}/redraw`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <RefreshCw className="size-3.5" />
                                {c.redraw}
                            </Button>
                        </motion.div>
                    ) : (
                        <motion.div
                            key="drawing"
                            className="shimmer flex size-full flex-col items-center justify-center gap-2"
                        >
                            <GarmentIcon
                                garment={collection.brief.garment}
                                className="h-24 w-20 text-muted-foreground/40"
                            />
                            <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                {concept.status === 'drawing' && (
                                    <Loader2 className="size-3.5 animate-spin" />
                                )}
                                {concept.status === 'drawing'
                                    ? c.drawing
                                    : c.waiting}
                            </span>
                        </motion.div>
                    )}
                </AnimatePresence>
                <span className="pointer-events-none absolute start-3 top-2 font-display text-2xl font-semibold text-white drop-shadow-[0_1px_4px_rgba(0,0,0,0.45)]">
                    {String(index + 1).padStart(2, '0')}
                </span>
                {ready && concept.score !== undefined && (
                    <span
                        className={cn(
                            'num absolute end-2.5 top-2.5 rounded-full px-2 py-0.5 text-[11px] font-semibold shadow-sm',
                            toneClasses[tone].soft,
                            toneClasses[tone].text,
                        )}
                    >
                        {tr(c.fit, { n: concept.score })}
                    </span>
                )}
                {ready && !collection.from_model && (
                    <span className="absolute end-2.5 bottom-2.5 rounded-full bg-black/55 px-2 py-0.5 text-[10px] text-white">
                        {c.illustrative}
                    </span>
                )}
                {ready && !busy && (
                    <button
                        type="button"
                        onClick={() =>
                            router.post(
                                `/store/studio/collections/${collection.id}/concepts/${index}/redraw`,
                                {},
                                { preserveScroll: true },
                            )
                        }
                        className="absolute start-2.5 bottom-2.5 flex size-8 cursor-pointer items-center justify-center rounded-full bg-black/45 text-white opacity-0 transition-opacity group-hover:opacity-100 focus-visible:opacity-100"
                        title={c.redrawTitle}
                        aria-label={c.redrawTitle}
                    >
                        <RefreshCw className="size-3.5" />
                    </button>
                )}
            </div>
            <div className="flex flex-1 flex-col gap-2.5 p-4">
                <div className="flex flex-wrap items-center gap-1.5">
                    <SourceBadge source={concept.source} />
                    <Badge variant="neutral" className="text-[10px]">
                        {vocabulary.roles[concept.role]?.[locale]}
                    </Badge>
                </div>
                <h3 className="font-display text-[17px] leading-snug font-semibold">
                    {field(concept, 'name')}
                </h3>
                {field(concept, 'pitch') && (
                    <p className="line-clamp-2 text-xs leading-relaxed text-muted-foreground">
                        {field(concept, 'pitch')}
                    </p>
                )}
                <div className="mt-auto flex items-center justify-between gap-2 pt-1">
                    <div className="flex min-w-0 items-center gap-2">
                        <div className="flex -space-x-1.5 rtl:space-x-reverse">
                            {concept.colors.map((color) => (
                                <span
                                    key={color}
                                    className="size-4 rounded-full ring-2 ring-background"
                                    style={{ backgroundColor: hex(color) }}
                                />
                            ))}
                        </div>
                        {silhouette && (
                            <span className="truncate text-[11px] text-muted-foreground">
                                {silhouette}
                            </span>
                        )}
                    </div>
                    <Button
                        size="sm"
                        variant="ghost"
                        className="h-7 shrink-0 px-2 text-xs"
                        onClick={onOpen}
                        disabled={!ready && concept.status !== 'failed'}
                    >
                        <FileText className="size-3.5" />
                        {c.sheet}
                    </Button>
                </div>
            </div>
        </motion.article>
    );
}

function Placeholder({
    garment,
    label,
}: {
    garment: Collection['brief']['garment'];
    label: string;
}) {
    return (
        <div className="overflow-hidden rounded-2xl border border-border/70 bg-background">
            <div className="shimmer flex aspect-[3/4] flex-col items-center justify-center gap-2 bg-muted">
                <GarmentIcon
                    garment={garment}
                    className="h-24 w-20 text-muted-foreground/40"
                />
                <span className="text-xs text-muted-foreground">{label}</span>
            </div>
            <div className="space-y-2 p-4">
                <div className="shimmer h-4 w-1/2 rounded bg-muted" />
                <div className="shimmer h-3 w-3/4 rounded bg-muted" />
            </div>
        </div>
    );
}

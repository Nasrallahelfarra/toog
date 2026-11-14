import { useForm, usePoll } from '@inertiajs/react';
import { BrainCircuit, ImagePlus, Lightbulb } from 'lucide-react';
import { motion } from 'motion/react';
import { useEffect, useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet';
import { useI18n } from '@/hooks/use-i18n';
import { BriefPanel, openFabricPicker } from './brief-panel';
import type { BriefForm } from './brief-panel';
import { CollectionView } from './collection-view';
import { GarmentIcon } from './garment-icons';
import { InsightRail } from './insight-rail';
import type { Collection, DesignerProps, Insights, Prefill } from './types';

/**
 * The AI designer mode of the studio: the brief at the start, the
 * collections in the middle, and the store intelligence at the end (a
 * side sheet on smaller screens). Any insight can fill the brief.
 */
export function DesignerWorkspace({
    designer,
    collections,
    openCollection,
    insights,
    aiLive,
    initialPrefill = null,
}: {
    designer: DesignerProps;
    collections: Collection[];
    openCollection: number | null;
    insights: Insights | undefined;
    aiLive: boolean;
    initialPrefill?: Prefill | null;
}) {
    const { t, locale, field } = useI18n();
    const d = t.designer;
    const [pulse, setPulse] = useState(0);
    const [railOpen, setRailOpen] = useState(false);
    const [selected, setSelected] = useState<number | null>(
        openCollection ?? collections[0]?.id ?? null,
    );
    const active = collections.some(
        (c) => c.status === 'pending' || c.status === 'processing',
    );
    const current =
        collections.find((c) => c.id === selected) ?? collections[0] ?? null;
    const others = collections.filter((c) => c.id !== current?.id);
    const urgent =
        insights?.alerts.filter((a) => a.severity !== 'info').length ?? 0;

    // Start on the store's largest section the designer can design.
    const firstSection = designer.categories.find((c) => c.garment) ?? null;
    const form = useForm<BriefForm>({
        garment: firstSection?.garment ?? 'abaya',
        category: firstSection?.name ?? null,
        show_face: false,
        fabric: null,
        fabric_image: null,
        fabric_from: null,
        colors: designer.brand_colors.slice(0, 2),
        occasion: 'daily',
        age: 'adult',
        modesty: null,
        tier: null,
        creativity: 3,
        count: 3,
        render: 'ghost',
        notes: '',
        inspiration: null,
    });

    const focusBrief = () =>
        document
            .getElementById('designer-brief')
            ?.scrollIntoView({ behavior: 'smooth', block: 'start' });

    const applyPrefill = (p: Prefill) => {
        form.setData((data) => ({
            ...data,
            garment: p.garment ?? data.garment,
            category: p.garment
                ? (designer.categories.find((c) => c.garment === p.garment)
                      ?.name ?? null)
                : data.category,
            fabric: p.fabric ?? data.fabric,
            colors: p.colors?.length ? p.colors.slice(0, 4) : data.colors,
            occasion: p.occasion ?? data.occasion,
            notes: p.notes ?? data.notes,
            inspiration: p.inspiration ?? null,
        }));
        setPulse((n) => n + 1);
        setRailOpen(false);
        focusBrief();
    };

    const sameFabric = (c: Collection) => {
        form.setData((data) => ({
            ...data,
            garment: c.brief.garment,
            category: c.brief.category ?? null,
            modesty: c.brief.modesty ?? null,
            show_face: c.brief.show_face ?? false,
            fabric: c.brief.fabric,
            fabric_image: null,
            fabric_from: c.fabric_image_url ? c.id : null,
            colors: c.brief.colors,
            occasion: c.brief.occasion,
            render: c.brief.render,
            inspiration: null,
        }));
        setPulse((n) => n + 1);
        focusBrief();
    };

    const submit = () =>
        form.post('/store/studio/collections', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: (page) => {
                const fresh = (page.props as { collections?: Collection[] })
                    .collections?.[0];
                if (fresh) setSelected(fresh.id);
                form.setData((data) => ({
                    ...data,
                    fabric_image: null,
                    fabric_from: null,
                    inspiration: null,
                    notes: '',
                }));
            },
        });

    // Arriving from the competitor radar's "design like this": fill the brief once.
    useEffect(() => {
        if (initialPrefill) applyPrefill(initialPrefill);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const topOpportunity = useMemo<Prefill | null>(
        () =>
            insights?.gaps[0]?.prefill ??
            insights?.bestsellers[0]?.prefill ??
            null,
        [insights],
    );
    const reusedFabric = form.data.fabric_from
        ? (collections.find((c) => c.id === form.data.fabric_from)
              ?.fabric_image_url ?? null)
        : null;

    return (
        <>
            {active && <Poll />}
            <div className="grid gap-6 xl:grid-cols-[380px_minmax(0,1fr)] 2xl:grid-cols-[380px_minmax(0,1fr)_340px]">
                <div className="xl:sticky xl:top-4 xl:max-h-[calc(100svh-2rem)] xl:self-start xl:overflow-y-auto xl:pb-2">
                    <BriefPanel
                        form={form}
                        categories={designer.categories}
                        vocabulary={designer.vocabulary}
                        aiLive={aiLive}
                        pulse={pulse}
                        onSubmit={submit}
                        reusedFabricUrl={reusedFabric}
                        onClearReused={() => form.setData('fabric_from', null)}
                    />
                </div>

                <div className="min-w-0 space-y-6">
                    <div className="flex items-center justify-between gap-3 2xl:hidden">
                        <Button
                            variant="outline"
                            onClick={() => setRailOpen(true)}
                            className="relative"
                        >
                            <BrainCircuit className="size-4" />
                            {d.rail.openRail}
                            {urgent > 0 && (
                                <span className="num ms-1 rounded-full bg-danger px-1.5 text-[10px] text-white">
                                    {urgent}
                                </span>
                            )}
                        </Button>
                    </div>

                    {current ? (
                        <CollectionView
                            key={current.id}
                            collection={current}
                            vocabulary={designer.vocabulary}
                            onSameFabric={sameFabric}
                        />
                    ) : (
                        <EmptyTable
                            onUpload={openFabricPicker}
                            onOpportunity={
                                topOpportunity
                                    ? () => applyPrefill(topOpportunity)
                                    : undefined
                            }
                        />
                    )}

                    {others.length > 0 && (
                        <section className="space-y-3">
                            <h3 className="text-xs font-semibold text-muted-foreground">
                                {d.collection.recent}
                            </h3>
                            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                {others.map((c) => (
                                    <button
                                        key={c.id}
                                        type="button"
                                        onClick={() => {
                                            setSelected(c.id);
                                            window.scrollTo({
                                                top: 0,
                                                behavior: 'smooth',
                                            });
                                        }}
                                        className="group flex cursor-pointer items-center gap-3 rounded-2xl border bg-card p-2.5 text-start transition-colors hover:border-foreground/20"
                                    >
                                        <div className="grid size-16 shrink-0 grid-cols-2 gap-0.5 overflow-hidden rounded-xl">
                                            {[0, 1, 2, 3].map((i) =>
                                                c.concepts[i]?.image_url ? (
                                                    <img
                                                        key={i}
                                                        src={
                                                            c.concepts[i]
                                                                .image_url!
                                                        }
                                                        alt=""
                                                        className="size-full object-cover"
                                                    />
                                                ) : (
                                                    <span
                                                        key={i}
                                                        className="flex items-center justify-center bg-muted"
                                                    >
                                                        {i === 0 && (
                                                            <GarmentIcon
                                                                garment={
                                                                    c.brief
                                                                        .garment
                                                                }
                                                                className="h-5 w-4 text-muted-foreground/50"
                                                            />
                                                        )}
                                                    </span>
                                                ),
                                            )}
                                        </div>
                                        <div className="min-w-0">
                                            <p className="truncate font-display text-sm font-semibold">
                                                {field(c, 'title') ||
                                                    d.collection.newCollection}
                                            </p>
                                            <p className="truncate text-[11px] text-muted-foreground">
                                                {
                                                    designer.vocabulary
                                                        .garments[
                                                        c.brief.garment
                                                    ]?.[locale]
                                                }
                                                {c.brief.fabric &&
                                                    ` · ${designer.vocabulary.fabrics[c.brief.fabric]?.[locale]}`}
                                            </p>
                                        </div>
                                    </button>
                                ))}
                            </div>
                        </section>
                    )}
                </div>

                <div className="hidden 2xl:sticky 2xl:top-4 2xl:block 2xl:max-h-[calc(100svh-2rem)] 2xl:self-start 2xl:overflow-y-auto">
                    <InsightRail
                        insights={insights}
                        briefing={designer.briefing}
                        onDesign={applyPrefill}
                    />
                </div>
            </div>

            <Sheet open={railOpen} onOpenChange={setRailOpen}>
                <SheetContent
                    side={locale === 'ar' ? 'left' : 'right'}
                    className="w-full overflow-y-auto p-0 sm:max-w-md"
                >
                    <SheetTitle className="sr-only">{d.rail.title}</SheetTitle>
                    <InsightRail
                        insights={insights}
                        briefing={designer.briefing}
                        onDesign={applyPrefill}
                        className="rounded-none border-0 shadow-none"
                    />
                </SheetContent>
            </Sheet>
        </>
    );
}

function EmptyTable({
    onUpload,
    onOpportunity,
}: {
    onUpload: () => void;
    onOpportunity?: () => void;
}) {
    const { t } = useI18n();
    const e = t.designer.collection.empty;

    return (
        <motion.section
            initial={{ opacity: 0, y: 10 }}
            animate={{ opacity: 1, y: 0 }}
            className="relative overflow-hidden rounded-2xl border border-border/70 bg-card px-6 py-16 text-center shadow-sm"
        >
            <div
                className="bg-sadu pointer-events-none absolute inset-0 text-gold opacity-[0.05]"
                aria-hidden
            />
            <div className="relative mx-auto flex max-w-md flex-col items-center gap-4">
                <div className="flex items-end gap-3 text-muted-foreground/70">
                    <GarmentIcon garment="dress_daily" className="h-14 w-12" />
                    <GarmentIcon
                        garment="abaya"
                        className="h-20 w-16 text-gold"
                    />
                    <GarmentIcon garment="kaftan" className="h-14 w-12" />
                </div>
                <h2 className="font-display text-2xl font-semibold">
                    {e.title}
                </h2>
                <p className="text-sm leading-relaxed text-muted-foreground">
                    {e.body}
                </p>
                <div className="flex flex-wrap justify-center gap-2 pt-2">
                    <Button variant="gold" onClick={onUpload}>
                        <ImagePlus className="size-4" />
                        {t.designer.brief.fabricPhoto}
                    </Button>
                    {onOpportunity && (
                        <Button variant="outline" onClick={onOpportunity}>
                            <Lightbulb className="size-4" />
                            {e.topOpportunity}
                        </Button>
                    )}
                </div>
            </div>
        </motion.section>
    );
}

function Poll() {
    usePoll(3000, { only: ['collections'] });

    return null;
}

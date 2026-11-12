import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ClipboardCheck,
    Download,
    ExternalLink,
    Images,
    Ruler,
    ScanSearch,
    Scissors,
    WandSparkles,
} from 'lucide-react';
import { ColorChips } from '@/components/mojo/color-chips';
import { ScoreRing } from '@/components/mojo/score-ring';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { useI18n } from '@/hooks/use-i18n';
import { useVocabulary } from '@/hooks/use-vocabulary';
import { cn } from '@/lib/utils';
import { SourceBadge } from './source-badge';
import type { Collection, Concept, FashionVocabulary } from './types';

/**
 * The design sheet ("tech pack lite") a merchant can hand to a workshop:
 * the drawing, every construction detail, why this design, and production
 * estimates worked out from the sheet, clearly labelled as estimates.
 */
export function DesignSheet({
    collection,
    concept,
    vocabulary,
    onOpenChange,
}: {
    collection: Collection;
    concept: Concept | null;
    vocabulary: FashionVocabulary;
    onOpenChange: (open: boolean) => void;
}) {
    const { t, tr, locale, field, money } = useI18n();
    const { label } = useVocabulary();
    const s = t.designer.sheet;
    const garment = collection.brief.garment;

    if (!concept) {
        return <Dialog open={false} onOpenChange={onOpenChange} />;
    }

    const name = (
        group: keyof FashionVocabulary['details'],
        key: string | null | undefined,
    ) => (key ? (vocabulary.details[group]?.[key]?.[locale] ?? key) : '—');
    const fabric = concept.fabric ? vocabulary.fabrics[concept.fabric] : null;
    const embellishments = concept.embellishments.filter((e) => e !== 'none');
    const specs: [string, React.ReactNode][] = [
        [
            s.fields.silhouette,
            vocabulary.silhouettes[garment]?.[concept.silhouette]?.[locale] ??
                concept.silhouette,
        ],
        [s.fields.neckline, name('neckline', concept.neckline)],
        [s.fields.sleeve, name('sleeve', concept.sleeve)],
        [s.fields.length, name('length', concept.length)],
        [s.fields.closure, name('closure', concept.closure)],
        [
            s.fields.embellishment,
            embellishments.length
                ? embellishments
                      .map((e) => name('embellishment', e))
                      .join(locale === 'ar' ? '، ' : ', ')
                : name('embellishment', 'none'),
        ],
        ...(embellishments.length
            ? ([[s.fields.placement, name('placement', concept.placement)]] as [
                  string,
                  React.ReactNode,
              ][])
            : []),
        [s.fields.fabric, fabric ? fabric[locale] : '—'],
        [s.lining, s.linings[concept.lining]],
        [s.fields.colors, <ColorChips key="c" colors={concept.colors} />],
        [s.fields.pattern, label('patterns', concept.pattern)],
        [s.fields.style, label('styles', concept.style)],
        [s.sizes, garment === 'abaya' ? s.sizesAbaya : s.sizesDress],
    ];
    const evaluate = new URLSearchParams({
        name: field(concept, 'name'),
        category: concept.category,
        colors: concept.colors.join(','),
        ...(concept.pattern ? { pattern: concept.pattern } : {}),
        ...(concept.style ? { style: concept.style } : {}),
        ...(concept.season ? { season: concept.season } : {}),
        ...(concept.price ? { price: String(concept.price.mid) } : {}),
        description: field(concept, 'pitch'),
    });

    return (
        <Dialog open onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[92vh] gap-0 overflow-hidden p-0 sm:max-w-6xl">
                <div className="grid max-h-[92vh] overflow-y-auto md:grid-cols-[minmax(0,5fr)_minmax(0,6fr)] md:overflow-hidden">
                    <div className="relative flex flex-col bg-muted/50 md:max-h-[92vh] md:overflow-y-auto">
                        {concept.image_url ? (
                            <img
                                src={concept.image_url}
                                alt={field(concept, 'name')}
                                className={cn(
                                    'w-full object-cover',
                                    concept.render === 'flat'
                                        ? 'aspect-square'
                                        : 'aspect-[3/4]',
                                )}
                            />
                        ) : (
                            <div className="aspect-[3/4]" />
                        )}
                        {collection.fabric_image_url && (
                            <div className="absolute start-3 bottom-3 flex items-center gap-2 rounded-full bg-card/90 py-1 ps-1 pe-3 shadow-sm backdrop-blur">
                                <img
                                    src={collection.fabric_image_url}
                                    alt=""
                                    className="size-8 rounded-full object-cover"
                                />
                                <span className="text-[11px] font-medium">
                                    {t.designer.collection.fabric}
                                </span>
                            </div>
                        )}
                    </div>

                    <div className="flex flex-col md:max-h-[92vh] md:overflow-y-auto">
                        <div className="space-y-6 p-6">
                            <div className="flex items-start justify-between gap-4">
                                <div className="min-w-0 space-y-2">
                                    <div className="flex flex-wrap items-center gap-1.5">
                                        <SourceBadge source={concept.source} />
                                        <Badge
                                            variant="neutral"
                                            className="text-[10px]"
                                        >
                                            {
                                                vocabulary.roles[
                                                    concept.role
                                                ]?.[locale]
                                            }
                                        </Badge>
                                    </div>
                                    <DialogTitle className="font-display text-2xl leading-snug font-semibold">
                                        {field(concept, 'name')}
                                    </DialogTitle>
                                    <DialogDescription className="text-sm leading-relaxed">
                                        {field(concept, 'pitch') ||
                                            field(collection, 'title')}
                                    </DialogDescription>
                                </div>
                                {concept.score !== undefined && (
                                    <ScoreRing
                                        score={concept.score}
                                        size={64}
                                        stroke={6}
                                        label={s.score}
                                        className="shrink-0"
                                    />
                                )}
                            </div>

                            <Block title={s.specs} icon={Ruler}>
                                <dl className="divide-y rounded-xl border">
                                    {specs.map(([key, value]) => (
                                        <div
                                            key={key}
                                            className="grid grid-cols-[8.5rem_1fr] gap-3 px-3.5 py-2 text-sm"
                                        >
                                            <dt className="text-xs leading-6 text-muted-foreground">
                                                {key}
                                            </dt>
                                            <dd className="font-medium">
                                                {value}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            </Block>

                            <Block title={s.why} icon={ScanSearch}>
                                {field(concept, 'rationale') && (
                                    <p className="text-sm leading-relaxed">
                                        {field(concept, 'rationale')}
                                    </p>
                                )}
                                {concept.evidence.length > 0 ? (
                                    <ul className="space-y-2">
                                        {concept.evidence.map((e) => (
                                            <li
                                                key={e.id}
                                                className="flex items-center gap-3 rounded-xl border p-2.5"
                                            >
                                                {e.image_url ? (
                                                    <img
                                                        src={e.image_url}
                                                        alt=""
                                                        className="size-10 shrink-0 rounded-lg object-cover"
                                                    />
                                                ) : (
                                                    <span className="size-1.5 shrink-0 rounded-full bg-gold" />
                                                )}
                                                <div className="min-w-0 flex-1">
                                                    <p className="text-xs leading-relaxed">
                                                        {field(e, 'claim')}
                                                    </p>
                                                    {e.caveat && (
                                                        <p className="mt-0.5 text-[10px] text-muted-foreground">
                                                            {
                                                                s.caveats[
                                                                    e.caveat
                                                                ]
                                                            }
                                                        </p>
                                                    )}
                                                </div>
                                                {e.href &&
                                                    (e.href.startsWith(
                                                        'http',
                                                    ) ? (
                                                        <a
                                                            href={e.href}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="shrink-0 text-muted-foreground hover:text-foreground"
                                                        >
                                                            <ExternalLink className="size-3.5" />
                                                        </a>
                                                    ) : (
                                                        <Link
                                                            href={e.href}
                                                            className="shrink-0 text-muted-foreground hover:text-foreground"
                                                        >
                                                            <ExternalLink className="size-3.5" />
                                                        </Link>
                                                    ))}
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="rounded-xl bg-gold-soft/60 px-3 py-2.5 text-xs leading-relaxed">
                                        {s.noEvidence}
                                    </p>
                                )}
                            </Block>

                            <Block
                                title={s.production}
                                icon={Scissors}
                                badge={s.estimate}
                            >
                                <div className="grid gap-2 sm:grid-cols-3">
                                    <Fact
                                        label={s.meters}
                                        value={tr(s.metersValue, {
                                            min: concept.production.meters_min,
                                            max: concept.production.meters_max,
                                        })}
                                        note={s.metersNote}
                                    />
                                    <Fact
                                        label={s.complexity}
                                        value={
                                            <span className="flex items-center gap-1.5">
                                                {[1, 2, 3].map((n) => (
                                                    <span
                                                        key={n}
                                                        className={cn(
                                                            'h-1.5 w-4 rounded-full',
                                                            n <=
                                                                concept
                                                                    .production
                                                                    .complexity
                                                                ? 'bg-gold'
                                                                : 'bg-muted',
                                                        )}
                                                    />
                                                ))}
                                                <span>
                                                    {
                                                        s.complexities[
                                                            concept.production
                                                                .complexity
                                                        ]
                                                    }
                                                </span>
                                            </span>
                                        }
                                    />
                                    <Fact
                                        label={s.price}
                                        value={
                                            concept.price
                                                ? `${money(concept.price.min)} – ${money(concept.price.max)}`
                                                : '—'
                                        }
                                        note={s.priceNote}
                                    />
                                </div>
                                {field(concept, 'finishing') && (
                                    <p className="text-xs leading-relaxed">
                                        <span className="text-muted-foreground">
                                            {s.finishing}:{' '}
                                        </span>
                                        {field(concept, 'finishing')}
                                    </p>
                                )}
                                {field(concept, 'risk') && (
                                    <p className="flex gap-2 rounded-lg bg-warning-soft/70 px-3 py-2 text-xs leading-relaxed">
                                        <AlertTriangle className="mt-0.5 size-3.5 shrink-0 text-warning" />
                                        <span>
                                            <span className="font-medium">
                                                {s.risk}:{' '}
                                            </span>
                                            {field(concept, 'risk')}
                                        </span>
                                    </p>
                                )}
                            </Block>

                            <p className="border-t pt-3 text-[11px] text-muted-foreground">
                                {s.footer}
                            </p>
                        </div>

                        <div className="sticky bottom-0 mt-auto flex flex-wrap gap-2 border-t bg-card/95 px-6 py-3 backdrop-blur">
                            {concept.design_id && (
                                <Button size="sm" variant="gold" asChild>
                                    <Link
                                        href={`/store/studio?mode=idea&refine=${concept.design_id}`}
                                    >
                                        <WandSparkles className="size-3.5" />
                                        {s.refine}
                                    </Link>
                                </Button>
                            )}
                            <Button size="sm" variant="outline" asChild>
                                <Link
                                    href={`/store/evaluations/create?${evaluate.toString()}`}
                                >
                                    <ClipboardCheck className="size-3.5" />
                                    {s.evaluate}
                                </Link>
                            </Button>
                            {concept.design_id && (
                                <Button size="sm" variant="ghost" asChild>
                                    <a
                                        href={`/store/designs/${concept.design_id}/download`}
                                    >
                                        <Download className="size-3.5" />
                                        {s.download}
                                    </a>
                                </Button>
                            )}
                            <Button size="sm" variant="ghost" asChild>
                                <Link href="/store/library">
                                    <Images className="size-3.5" />
                                    {s.library}
                                </Link>
                            </Button>
                        </div>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}

function Block({
    title,
    icon: Icon,
    badge,
    children,
}: {
    title: string;
    icon: typeof Ruler;
    badge?: string;
    children: React.ReactNode;
}) {
    return (
        <section className="space-y-3">
            <h3 className="flex items-center gap-2 text-xs font-semibold">
                <span className="h-3 w-0.5 rounded-full bg-gold" />
                <Icon className="size-3.5 text-muted-foreground" />
                {title}
                {badge && (
                    <Badge
                        variant="neutral"
                        className="px-1.5 py-0 text-[10px]"
                    >
                        {badge}
                    </Badge>
                )}
            </h3>
            {children}
        </section>
    );
}

function Fact({
    label,
    value,
    note,
}: {
    label: string;
    value: React.ReactNode;
    note?: string;
}) {
    return (
        <div className="rounded-xl bg-muted/50 px-3 py-2.5">
            <div className="text-[11px] text-muted-foreground">{label}</div>
            <div className="num mt-0.5 text-sm font-semibold">{value}</div>
            {note && (
                <div className="mt-0.5 text-[10px] leading-snug text-muted-foreground">
                    {note}
                </div>
            )}
        </div>
    );
}

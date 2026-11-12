import { Info, Sparkles } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import { GarmentIcon } from '@/components/studio/designer/garment-icons';
import type { GarmentKey } from '@/components/studio/designer/types';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useI18n } from '@/hooks/use-i18n';
import { useVocabulary } from '@/hooks/use-vocabulary';
import { cn } from '@/lib/utils';
import type { Coverage, PostTags, RadarPost } from './types';

/**
 * A post's engagement as a multiple of its own account's usual. Gold grows
 * with the lift; never red or green, since a lift is neither good nor bad.
 */
export function LiftPill({
    post,
    className,
}: {
    post: Pick<RadarPost, 'lift' | 'baseline' | 'small_sample'>;
    className?: string;
}) {
    const { t, tr } = useI18n();
    const r = t.radar.posts;
    if (post.lift === null) return null;
    const tone =
        post.lift >= 3
            ? 'bg-gold text-gold-foreground'
            : post.lift >= 2
              ? 'bg-gold-soft text-gold-foreground dark:text-gold'
              : post.lift >= 1.5
                ? 'border border-gold/60 bg-card text-gold-foreground dark:text-gold'
                : 'bg-muted text-muted-foreground';

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span
                    className={cn(
                        'inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold shadow-sm',
                        tone,
                        className,
                    )}
                >
                    <bdi dir="ltr" className="num">
                        {post.lift}×
                    </bdi>
                    <span className="font-normal opacity-80">
                        {r.usualShort}
                    </span>
                </span>
            </TooltipTrigger>
            <TooltipContent>
                {tr(r.liftTip, { n: post.baseline })}
                {post.small_sample && ` · ${r.smallSample}`}
            </TooltipContent>
        </Tooltip>
    );
}

/** Up to `max` tags in a fixed order: garment, colour, pattern, then angle and format. */
export function PostTagList({
    tags,
    max = 4,
    className,
}: {
    tags: PostTags;
    max?: number;
    className?: string;
}) {
    const { t } = useI18n();
    const { label, hex } = useVocabulary();
    const r = t.radar.tags;
    const items: { key: string; node: React.ReactNode }[] = [];

    if (tags.garment) {
        items.push({
            key: 'g',
            node: (
                <>
                    <GarmentIcon garment={tags.garment} className="h-3.5 w-3" />
                    <GarmentName garment={tags.garment} />
                </>
            ),
        });
    }
    tags.colors.slice(0, 2).forEach((c) =>
        items.push({
            key: `c${c}`,
            node: (
                <>
                    <span
                        className="size-2.5 rounded-full ring-1 ring-black/10 dark:ring-white/15"
                        style={{ backgroundColor: hex(c) }}
                    />
                    {label('colors', c)}
                </>
            ),
        }),
    );
    if (tags.pattern && tags.pattern !== 'solid')
        items.push({ key: 'p', node: label('patterns', tags.pattern) });
    tags.hooks.forEach((h) =>
        items.push({
            key: `h${h}`,
            node: r.hooks[h as keyof typeof r.hooks] ?? h,
        }),
    );
    if (tags.occasion)
        items.push({
            key: 'o',
            node:
                r.occasions[tags.occasion as keyof typeof r.occasions] ??
                label('seasons', tags.occasion),
        });
    items.push({ key: 'f', node: r.formats[tags.format] });

    const shown = items.slice(0, max);
    const rest = items.length - shown.length;

    return (
        <div className={cn('flex flex-wrap gap-1', className)}>
            {shown.map((item) => (
                <span
                    key={item.key}
                    className="inline-flex items-center gap-1 rounded-full bg-muted/70 px-2 py-0.5 text-[11px] text-foreground/80"
                >
                    {item.node}
                </span>
            ))}
            {rest > 0 && (
                <span className="rounded-full px-1.5 py-0.5 text-[11px] text-muted-foreground">
                    +{rest}
                </span>
            )}
        </div>
    );
}

export function GarmentName({ garment }: { garment: GarmentKey }) {
    const { locale } = useI18n();
    const names: Record<GarmentKey, [string, string]> = {
        abaya: ['عباية', 'Abaya'],
        dress_daily: ['فستان يومي', 'Day dress'],
        dress_evening: ['فستان سهرة', 'Evening dress'],
        jalabiya: ['جلابية', 'Jalabiya'],
        kaftan: ['قفطان', 'Kaftan'],
        kimono: ['كيمونو', 'Kimono'],
        coord_set: ['طقم قطعتين', 'Co-ord set'],
        prayer_set: ['جلال صلاة', 'Prayer set'],
    };

    return <>{names[garment]?.[locale === 'ar' ? 0 : 1] ?? garment}</>;
}

/** The image of a post or product, or the garment drawn when there is none. */
export function Visual({
    src,
    garment,
    alt = '',
    className,
}: {
    src: string | null;
    garment?: GarmentKey | null;
    alt?: string;
    className?: string;
}) {
    const [broken, setBroken] = useState(false);

    return (
        <div className={cn('relative overflow-hidden bg-muted', className)}>
            {src && !broken ? (
                <img
                    src={src}
                    alt={alt}
                    loading="lazy"
                    onError={() => setBroken(true)}
                    className="size-full object-cover transition-transform duration-700 group-hover:scale-[1.03]"
                />
            ) : (
                <div className="flex size-full items-center justify-center text-muted-foreground/40">
                    {garment ? (
                        <GarmentIcon
                            garment={garment}
                            className="h-1/2 w-1/3"
                        />
                    ) : (
                        <Sparkles className="size-8" />
                    )}
                </div>
            )}
        </div>
    );
}

export function ConfidenceChip({ coverage }: { coverage: Coverage }) {
    const { t, tr } = useI18n();
    const c = t.radar.confidence;
    const tone = {
        high: 'bg-success-soft text-success',
        medium: 'bg-info-soft text-info',
        low: 'bg-warning-soft text-warning',
    }[coverage.confidence];

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span
                    className={cn(
                        'inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium',
                        tone,
                    )}
                >
                    {c[coverage.confidence]}
                </span>
            </TooltipTrigger>
            <TooltipContent>
                {tr(c.tip, { p: coverage.eligible, c: coverage.brands })}
            </TooltipContent>
        </Tooltip>
    );
}

export function HowWeRead() {
    const { t } = useI18n();

    return (
        <Popover>
            <PopoverTrigger className="inline-flex cursor-pointer items-center gap-1 text-[11px] text-muted-foreground hover:text-foreground">
                <Info className="size-3.5" />
                {t.radar.howWeRead}
            </PopoverTrigger>
            <PopoverContent className="w-80 text-xs leading-relaxed">
                {t.radar.howWeReadBody}
            </PopoverContent>
        </Popover>
    );
}

/** A section title in the studio's style: a short gold bar, title and hint. */
export function SectionHead({
    title,
    hint,
    icon: Icon,
    action,
}: {
    title: string;
    hint?: string;
    icon?: LucideIcon;
    action?: React.ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-end justify-between gap-3">
            <div className="min-w-0">
                <h2 className="flex items-center gap-2 text-[15px] font-semibold">
                    <span className="h-3.5 w-0.5 rounded-full bg-gold" />
                    {Icon && <Icon className="size-4 text-muted-foreground" />}
                    {title}
                </h2>
                {hint && (
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {hint}
                    </p>
                )}
            </div>
            {action}
        </div>
    );
}

/** Scroll to a post or product card and pulse it gold. */
export function focusItem(focus: string | null) {
    if (!focus) return;
    const el = document.getElementById(`radar-${focus.replace(':', '-')}`);
    if (!el) return;
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    el.classList.add(
        'ring-2',
        'ring-gold',
        'ring-offset-2',
        'ring-offset-background',
    );
    window.setTimeout(
        () =>
            el.classList.remove(
                'ring-2',
                'ring-gold',
                'ring-offset-2',
                'ring-offset-background',
            ),
        1600,
    );
}

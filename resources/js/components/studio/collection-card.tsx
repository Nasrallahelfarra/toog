import { Link } from '@inertiajs/react';
import { CircleCheck, Heart, Images, WandSparkles } from 'lucide-react';
import { useState } from 'react';
import { GarmentIcon } from '@/components/studio/designer/garment-icons';
import type { GarmentKey } from '@/components/studio/designer/types';
import { Button } from '@/components/ui/button';
import { useCount } from '@/hooks/use-count';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

/** A finished AI-designer collection as the library shows it. */
export type LibraryCollection = {
    id: number;
    title: string;
    story: string | null;
    garment: string | null;
    garment_key: GarmentKey | null;
    occasion: string | null;
    fabric: string | null;
    fabric_image_url: string | null;
    images: { url: string; name: string }[];
    ready: number;
    total: number;
    approved: number;
    favorites: number;
    score: number | null;
    created_at: string | null;
    open_href: string;
    designs_href: string;
};

/**
 * One collection: its designs in a mosaic with the merchant's fabric, what
 * it is, how its designs are doing, and the way into it.
 */
export function CollectionCard({
    collection: c,
}: {
    collection: LibraryCollection;
}) {
    const { t, tr, number, date } = useI18n();
    const count = useCount();
    const l = t.studio.library.collections;

    return (
        <article className="group flex h-full flex-col overflow-hidden rounded-2xl border border-border/70 bg-card shadow-[0_1px_2px_rgba(16,24,40,0.04)] transition-shadow hover:shadow-md">
            <Link href={c.designs_href} className="relative block">
                <Mosaic images={c.images} garment={c.garment_key} />
                <span className="num absolute end-3 top-3 rounded-full bg-background/85 px-2.5 py-1 text-xs font-medium shadow-sm backdrop-blur">
                    {count(c.ready, l.count)}
                </span>
                {c.fabric_image_url && (
                    <span className="absolute start-3 bottom-3 flex items-center gap-2 rounded-full bg-background/85 py-1 ps-1 pe-3 text-xs font-medium shadow-sm backdrop-blur">
                        <img
                            src={c.fabric_image_url}
                            alt=""
                            className="size-7 rounded-full object-cover ring-1 ring-border"
                        />
                        {l.fabricPhoto}
                    </span>
                )}
            </Link>

            <div className="flex flex-1 flex-col gap-3 p-4">
                <div className="space-y-1">
                    <h3 className="line-clamp-1 text-[15px] font-semibold">
                        {c.title}
                    </h3>
                    {c.story && (
                        <p className="line-clamp-2 text-xs leading-relaxed text-muted-foreground">
                            {c.story}
                        </p>
                    )}
                </div>

                <div className="flex flex-wrap gap-1.5">
                    {[c.garment, c.occasion, c.fabric]
                        .filter((x): x is string => !!x)
                        .map((x) => (
                            <span
                                key={x}
                                className="rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground"
                            >
                                {x}
                            </span>
                        ))}
                </div>

                <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                    {c.approved > 0 && (
                        <span className="inline-flex items-center gap-1 text-success">
                            <CircleCheck className="size-3.5" />
                            {tr(l.approved, { n: number(c.approved) })}
                        </span>
                    )}
                    {c.favorites > 0 && (
                        <span className="inline-flex items-center gap-1">
                            <Heart className="size-3.5" />
                            <span className="num">{number(c.favorites)}</span>
                        </span>
                    )}
                    {c.score !== null && (
                        <span>{tr(l.score, { n: number(c.score) })}</span>
                    )}
                    {c.created_at && (
                        <span className="ms-auto">{date(c.created_at)}</span>
                    )}
                </div>

                <div className="mt-auto flex gap-2 pt-1">
                    <Button size="sm" variant="gold" asChild className="flex-1">
                        <Link href={c.open_href}>
                            <WandSparkles className="size-4" />
                            {l.open}
                        </Link>
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        asChild
                        className="flex-1"
                    >
                        <Link href={c.designs_href}>
                            <Images className="size-4" />
                            {l.designs}
                        </Link>
                    </Button>
                </div>
            </div>
        </article>
    );
}

/** One picture full; two side by side; three or four with the first tall. */
function Mosaic({
    images,
    garment,
}: {
    images: LibraryCollection['images'];
    garment: GarmentKey | null;
}) {
    if (images.length === 0) {
        return (
            <div className="flex aspect-[4/3] items-center justify-center bg-muted text-muted-foreground/40">
                {garment ? (
                    <GarmentIcon garment={garment} className="h-1/2 w-1/3" />
                ) : (
                    <Images className="size-10" />
                )}
            </div>
        );
    }

    return (
        <div
            className={cn(
                'grid aspect-[4/3] gap-0.5 overflow-hidden bg-border/60',
                images.length === 2 && 'grid-cols-2',
                images.length === 3 && 'grid-cols-[1.4fr_1fr] grid-rows-2',
                images.length >= 4 && 'grid-cols-[1.4fr_1fr] grid-rows-3',
            )}
        >
            {images.map((image, i) => (
                <Tile
                    key={image.url}
                    image={image}
                    className={cn(
                        i === 0 && images.length >= 3 && 'row-span-full',
                    )}
                />
            ))}
        </div>
    );
}

function Tile({
    image,
    className,
}: {
    image: LibraryCollection['images'][number];
    className?: string;
}) {
    const [broken, setBroken] = useState(false);

    return (
        <div
            className={cn(
                'relative min-h-0 overflow-hidden bg-muted',
                className,
            )}
        >
            {!broken && (
                <img
                    src={image.url}
                    alt={image.name}
                    loading="lazy"
                    onError={() => setBroken(true)}
                    className="size-full object-cover object-top transition-transform duration-700 group-hover:scale-[1.03]"
                />
            )}
        </div>
    );
}

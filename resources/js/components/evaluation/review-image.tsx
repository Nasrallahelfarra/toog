import { Loader2, Sparkles } from 'lucide-react';
import { useState } from 'react';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

export type ImageWorkStage = 'submitting' | 'queued' | 'generating';

export function ImageGenerationLoading({ stage }: { stage: ImageWorkStage }) {
    const { t } = useI18n();
    const c = t.productReview;
    return (
        <div
            role="status"
            aria-live="polite"
            data-image-generation={stage}
            className="flex h-full w-full flex-col items-center justify-center gap-4 rounded-xl bg-gradient-to-br from-primary/10 via-background to-primary/5 px-5 py-8 text-center"
        >
            <div className="relative flex size-20 shrink-0 items-center justify-center">
                <span className="absolute inset-0 rounded-full bg-primary/10 motion-safe:animate-pulse" />
                <Loader2
                    aria-hidden="true"
                    className="absolute inset-0 size-20 stroke-1 text-primary/70 motion-safe:animate-spin"
                />
                <Sparkles aria-hidden="true" className="size-7 text-primary" />
            </div>
            <div className="max-w-xs space-y-2">
                <p className="font-semibold">{c.imageLoading[stage]}</p>
                <p className="text-xs leading-6 text-muted-foreground">
                    {c.imageLoading.hint}
                </p>
            </div>
        </div>
    );
}

export function ReviewImage({
    src,
    label,
    stage,
    reviewing = false,
}: {
    src: string | null;
    label: string;
    stage?: ImageWorkStage;
    reviewing?: boolean;
}) {
    const { t } = useI18n();
    const [loadedSrc, setLoadedSrc] = useState<string | null>(null);
    const [failedSrc, setFailedSrc] = useState<string | null>(null);
    const loading = !!src && loadedSrc !== src && failedSrc !== src;
    return (
        <figure className="min-w-0 overflow-hidden rounded-xl border">
            <figcaption className="border-b bg-muted/50 px-4 py-2 text-sm font-medium">
                {label}
            </figcaption>
            <div
                className={cn(
                    'relative flex items-center justify-center bg-muted/20 p-3',
                    stage ? 'min-h-80 sm:aspect-square' : 'aspect-square',
                )}
                aria-busy={!!stage || loading}
            >
                {stage ? (
                    <ImageGenerationLoading stage={stage} />
                ) : src && failedSrc !== src ? (
                    <>
                        <a
                            href={src}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="flex h-full w-full items-center justify-center"
                            aria-label={label}
                        >
                            <img
                                key={src}
                                src={src}
                                alt={label}
                                onLoad={() => setLoadedSrc(src)}
                                onError={() => setFailedSrc(src)}
                                className={cn(
                                    'h-full w-full object-contain',
                                    loading && 'opacity-0',
                                )}
                            />
                        </a>
                        {loading && (
                            <div
                                role="status"
                                className="absolute inset-0 flex flex-col items-center justify-center gap-3 text-sm text-muted-foreground"
                            >
                                <Loader2
                                    aria-hidden="true"
                                    className="size-8 motion-safe:animate-spin"
                                />
                                {t.productReview.imageLoading.fetching}
                            </div>
                        )}
                    </>
                ) : (
                    <span className="px-3 text-center text-sm text-muted-foreground">
                        {src && failedSrc === src
                            ? t.productReview.failedImage
                            : t.productReview.noImage}
                    </span>
                )}
            </div>
            {reviewing && src && (
                <p
                    role="status"
                    className="flex items-center gap-2 border-t bg-primary/5 px-4 py-3 text-xs leading-5"
                >
                    <Loader2
                        aria-hidden="true"
                        className="size-4 shrink-0 motion-safe:animate-spin"
                    />
                    {t.productReview.imageLoading.reviewing}
                </p>
            )}
        </figure>
    );
}

export function focusImprovementResults() {
    requestAnimationFrame(() => {
        const results = document.getElementById('improvement-results');
        results?.focus({ preventScroll: true });
        results?.scrollIntoView({ block: 'start', behavior: 'instant' });
    });
}

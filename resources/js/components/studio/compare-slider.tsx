import { ChevronsLeftRight } from 'lucide-react';
import { useState } from 'react';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

/**
 * Before/after view of an edit: the original sits on the reading-start side
 * and a draggable handle (a native range input, so it works with the
 * keyboard too) reveals how much of each image shows.
 */
export function CompareSlider({ before, after, className }: { before: string; after: string; className?: string }) {
    const { t, isRtl } = useI18n();
    const e = t.studio.editor;
    const [split, setSplit] = useState(50);
    const side = isRtl ? 'right' : 'left';

    return (
        <div className={cn('bg-muted relative overflow-hidden rounded-2xl select-none', className)}>
            <img src={after} alt={e.after} className="block size-full object-contain" draggable={false} />
            <img
                src={before}
                alt={e.before}
                draggable={false}
                className="absolute inset-0 size-full object-contain"
                style={{ clipPath: isRtl ? `inset(0 0 0 ${100 - split}%)` : `inset(0 ${100 - split}% 0 0)` }}
            />
            <div className="pointer-events-none absolute inset-y-0 w-0.5 bg-white shadow-[0_0_0_1px_rgba(0,0,0,0.15)]" style={{ [side]: `${split}%` }}>
                <span className="absolute top-1/2 left-1/2 flex size-10 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-white text-neutral-900 shadow-lg">
                    <ChevronsLeftRight className="size-4" />
                </span>
            </div>
            <span className="absolute start-3 top-3 rounded-full bg-black/55 px-2.5 py-1 text-[11px] font-medium text-white backdrop-blur">{e.before}</span>
            <span className="absolute end-3 top-3 rounded-full bg-black/55 px-2.5 py-1 text-[11px] font-medium text-white backdrop-blur">{e.after}</span>
            <input
                type="range"
                min={0}
                max={100}
                value={split}
                onChange={(event) => setSplit(Number(event.target.value))}
                aria-label={e.slider}
                dir={isRtl ? 'rtl' : 'ltr'}
                className="absolute inset-0 size-full cursor-ew-resize opacity-0"
            />
        </div>
    );
}

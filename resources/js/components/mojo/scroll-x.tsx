import { useCallback, useLayoutEffect, useRef, useState } from 'react';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

const FADE = '28px';

/**
 * A box that scrolls sideways when its content is wider than the screen,
 * and fades the edge where more is hidden, so a phone user can tell there
 * is more to swipe to. The fade goes once the end is reached.
 *
 * `fade="end"` fades only the far edge: use it when the first column is
 * sticky, so the column that names the rows never fades. `startAtEnd`
 * opens scrolled to the far end (the latest months of a month table).
 */
export function ScrollX({
    fade = 'both',
    startAtEnd = false,
    className,
    children,
    ...props
}: ComponentProps<'div'> & { fade?: 'both' | 'end' | 'none'; startAtEnd?: boolean }) {
    const ref = useRef<HTMLDivElement>(null);
    const [edges, setEdges] = useState({ start: false, end: false, rtl: false });

    const measure = useCallback(() => {
        const box = ref.current;
        if (!box) return;
        const rtl = getComputedStyle(box).direction === 'rtl';
        const max = box.scrollWidth - box.clientWidth;
        // In right-to-left the browser counts scrollLeft from 0 down to -max.
        const position = Math.abs(box.scrollLeft);
        setEdges((current) => {
            const next = { start: position > 2, end: max - position > 2, rtl };
            return current.start === next.start && current.end === next.end && current.rtl === next.rtl ? current : next;
        });
    }, []);

    useLayoutEffect(() => {
        const box = ref.current;
        if (!box) return;

        if (startAtEnd) {
            const max = box.scrollWidth - box.clientWidth;
            box.scrollLeft = getComputedStyle(box).direction === 'rtl' ? -max : max;
        }
        measure();

        const observer = new ResizeObserver(measure);
        observer.observe(box);
        if (box.firstElementChild) observer.observe(box.firstElementChild);

        return () => observer.disconnect();
    }, [measure, startAtEnd]);

    const showStart = fade === 'both' && edges.start;
    const showEnd = fade !== 'none' && edges.end;
    // The physical sides of the start and end edges.
    const left = edges.rtl ? showEnd : showStart;
    const right = edges.rtl ? showStart : showEnd;
    const mask =
        left || right
            ? `linear-gradient(to right, ${left ? 'transparent' : 'black'} 0, black ${left ? FADE : '0'}, black calc(100% - ${right ? FADE : '0px'}), ${right ? 'transparent' : 'black'} 100%)`
            : undefined;

    return (
        <div
            ref={ref}
            onScroll={measure}
            className={cn('relative w-full overflow-x-auto overscroll-x-contain', className)}
            style={mask ? { maskImage: mask, WebkitMaskImage: mask } : undefined}
            {...props}
        >
            {children}
        </div>
    );
}

import { animate, useInView } from 'motion/react';
import { useEffect, useRef } from 'react';

/**
 * Counts up to `value` when it scrolls into view.
 */
export function AnimatedNumber({
    value,
    format = (n: number) => Math.round(n).toString(),
    duration = 1.1,
    className,
}: {
    value: number;
    format?: (n: number) => string;
    duration?: number;
    className?: string;
}) {
    const ref = useRef<HTMLSpanElement>(null);
    const inView = useInView(ref, { once: true, margin: '-40px' });
    const from = useRef(0);

    useEffect(() => {
        if (!inView || !ref.current) {
            return;
        }

        const node = ref.current;
        const controls = animate(from.current, value, {
            duration,
            ease: [0.22, 1, 0.36, 1],
            onUpdate: (v) => {
                node.textContent = format(v);
            },
        });
        from.current = value;

        return () => controls.stop();
    }, [inView, value, duration, format]);

    return (
        <span ref={ref} className={className ? `num ${className}` : 'num'}>
            {format(0)}
        </span>
    );
}

import { motion } from 'motion/react';
import { toneClasses } from '@/components/mojo/tone';
import type { Tone } from '@/components/mojo/tone';
import { cn } from '@/lib/utils';

/**
 * Animated horizontal bar for a 0-100 value, with an optional label row.
 */
export function Meter({
    value,
    tone = 'navy',
    label,
    hint,
    valueLabel,
    className,
    height = 8,
    delay = 0,
}: {
    value: number;
    tone?: Tone;
    label?: React.ReactNode;
    hint?: React.ReactNode;
    valueLabel?: React.ReactNode;
    className?: string;
    height?: number;
    delay?: number;
}) {
    const pct = Math.max(0, Math.min(100, value));

    return (
        <div className={cn('space-y-1.5', className)}>
            {(label || valueLabel) && (
                <div className="flex items-baseline justify-between gap-3 text-sm">
                    <span className="text-foreground/90 truncate">{label}</span>
                    <span className={cn('num shrink-0 font-semibold', toneClasses[tone].text)}>{valueLabel ?? Math.round(pct)}</span>
                </div>
            )}
            <div className="bg-muted overflow-hidden rounded-full" style={{ height }}>
                <motion.div
                    className={cn('h-full rounded-full', toneClasses[tone].bg)}
                    initial={{ width: 0 }}
                    animate={{ width: `${pct}%` }}
                    transition={{ duration: 0.9, delay, ease: [0.22, 1, 0.36, 1] }}
                />
            </div>
            {hint && <p className="text-muted-foreground text-xs leading-relaxed">{hint}</p>}
        </div>
    );
}

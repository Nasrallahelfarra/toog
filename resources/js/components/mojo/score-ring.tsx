import { motion } from 'motion/react';
import { AnimatedNumber } from '@/components/mojo/animated-number';
import { scoreTone, toneClasses } from '@/components/mojo/tone';
import { cn } from '@/lib/utils';

/**
 * Circular 0-100 gauge that sweeps in, coloured by the decision thresholds.
 */
export function ScoreRing({
    score,
    size = 132,
    stroke = 10,
    accept = 75,
    reject = 50,
    label,
    className,
}: {
    score: number | null;
    size?: number;
    stroke?: number;
    accept?: number;
    reject?: number;
    label?: string;
    className?: string;
}) {
    const radius = (size - stroke) / 2;
    const circumference = 2 * Math.PI * radius;
    const value = Math.max(0, Math.min(100, score ?? 0));
    const tone = toneClasses[scoreTone(score, accept, reject)];

    return (
        <div className={cn('relative inline-flex items-center justify-center', className)} style={{ width: size, height: size }}>
            <svg width={size} height={size} className="-rotate-90">
                <circle cx={size / 2} cy={size / 2} r={radius} fill="none" stroke="var(--muted)" strokeWidth={stroke} />
                {/* threshold ticks */}
                {[reject, accept].map((t) => {
                    const angle = (t / 100) * 2 * Math.PI;
                    const r1 = radius - stroke / 2 - 2;
                    const r2 = radius + stroke / 2 + 2;

                    return (
                        <line
                            key={t}
                            x1={size / 2 + r1 * Math.cos(angle)}
                            y1={size / 2 + r1 * Math.sin(angle)}
                            x2={size / 2 + r2 * Math.cos(angle)}
                            y2={size / 2 + r2 * Math.sin(angle)}
                            stroke="var(--border)"
                            strokeWidth={2}
                        />
                    );
                })}
                <motion.circle
                    cx={size / 2}
                    cy={size / 2}
                    r={radius}
                    fill="none"
                    stroke={tone.stroke}
                    strokeWidth={stroke}
                    strokeLinecap="round"
                    strokeDasharray={circumference}
                    initial={{ strokeDashoffset: circumference }}
                    animate={{ strokeDashoffset: circumference * (1 - value / 100) }}
                    transition={{ duration: 1.2, ease: [0.22, 1, 0.36, 1] }}
                />
            </svg>
            <div className="absolute inset-0 flex flex-col items-center justify-center">
                <span className={cn('font-semibold tracking-tight', tone.text)} style={{ fontSize: size * 0.28 }}>
                    {score === null ? '—' : <AnimatedNumber value={value} />}
                </span>
                {label && <span className="text-muted-foreground text-xs">{label}</span>}
            </div>
        </div>
    );
}

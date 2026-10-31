import { Link } from '@inertiajs/react';
import { ArrowDownRight, ArrowUpRight } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { AnimatedNumber } from '@/components/mojo/animated-number';
import { HoverLift } from '@/components/mojo/motion';
import { toneClasses } from '@/components/mojo/tone';
import type { Tone } from '@/components/mojo/tone';
import { cn } from '@/lib/utils';

/**
 * KPI tile: animated value, optional trend delta and mini sparkline.
 */
export function StatCard({
    label,
    value,
    format,
    icon: Icon,
    tone = 'navy',
    delta,
    deltaLabel,
    hint,
    spark,
    href,
    invertDelta = false,
    children,
}: {
    label: string;
    value: number | null;
    format?: (n: number) => string;
    icon?: LucideIcon;
    tone?: Tone;
    delta?: number | null;
    deltaLabel?: string;
    hint?: ReactNode;
    spark?: number[];
    href?: string;
    invertDelta?: boolean;
    children?: ReactNode;
}) {
    const good = delta !== null && delta !== undefined && (invertDelta ? delta < 0 : delta > 0);
    const body = (
        <div className="bg-card group relative h-full overflow-hidden rounded-2xl border border-border/70 p-5 shadow-[0_1px_2px_rgba(16,24,40,0.04)] transition-shadow hover:shadow-md">
            <div className="flex items-start justify-between gap-3">
                <span className="text-muted-foreground text-sm">{label}</span>
                {Icon && (
                    <span className={cn('flex size-9 items-center justify-center rounded-xl', toneClasses[tone].soft, toneClasses[tone].text)}>
                        <Icon className="size-[18px]" />
                    </span>
                )}
            </div>
            <div className="mt-3 flex items-end justify-between gap-3">
                <div className="space-y-1">
                    <div className="text-[1.7rem] leading-none font-semibold tracking-tight">
                        {value === null ? '—' : <AnimatedNumber value={value} format={format} />}
                    </div>
                    {(delta !== null && delta !== undefined) || hint ? (
                        <div className="flex items-center gap-1.5 text-xs">
                            {delta !== null && delta !== undefined && (
                                <span className={cn('inline-flex items-center gap-0.5 rounded-md px-1.5 py-0.5 font-medium', good ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger')}>
                                    {delta >= 0 ? <ArrowUpRight className="size-3" /> : <ArrowDownRight className="size-3" />}
                                    <span className="num">{Math.abs(delta).toFixed(Math.abs(delta) < 10 ? 1 : 0)}%</span>
                                </span>
                            )}
                            {(deltaLabel || hint) && <span className="text-muted-foreground">{deltaLabel ?? hint}</span>}
                        </div>
                    ) : null}
                </div>
                {spark && spark.length > 1 && <Sparkline data={spark} tone={tone} />}
            </div>
            {children}
        </div>
    );

    return <HoverLift className="h-full">{href ? <Link href={href} prefetch>{body}</Link> : body}</HoverLift>;
}

export function Sparkline({ data, tone = 'navy', width = 96, height = 36 }: { data: number[]; tone?: Tone; width?: number; height?: number }) {
    const min = Math.min(...data);
    const max = Math.max(...data);
    const range = max - min || 1;
    const points = data.map((v, i) => [(i / (data.length - 1)) * width, height - 3 - ((v - min) / range) * (height - 6)]);
    const line = points.map(([x, y], i) => `${i ? 'L' : 'M'}${x.toFixed(1)},${y.toFixed(1)}`).join(' ');
    const id = `spark-${tone}`;

    return (
        <svg width={width} height={height} className="shrink-0 overflow-visible rtl:-scale-x-100">
            <defs>
                <linearGradient id={id} x1="0" x2="0" y1="0" y2="1">
                    <stop offset="0%" stopColor={toneClasses[tone].stroke} stopOpacity={0.25} />
                    <stop offset="100%" stopColor={toneClasses[tone].stroke} stopOpacity={0} />
                </linearGradient>
            </defs>
            <path d={`${line} L${width},${height} L0,${height} Z`} fill={`url(#${id})`} />
            <path d={line} fill="none" stroke={toneClasses[tone].stroke} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

import type { ReactNode } from 'react';
import { useI18n } from '@/hooks/use-i18n';

/**
 * Chart palette, all CSS variables so charts follow light/dark themes.
 * Order matters: series 1 navy, 2 gold, 3 teal, 4 terracotta, 5 sage.
 */
export const chartColors = ['var(--chart-1)', 'var(--chart-2)', 'var(--chart-3)', 'var(--chart-4)', 'var(--chart-5)'];

export const semanticChart = {
    accept: 'var(--success)',
    modify: 'var(--warning)',
    reject: 'var(--danger)',
    winner: 'var(--success)',
    average: 'var(--info)',
    stagnant: 'var(--danger)',
    at_risk: 'var(--warning)',
};

/**
 * Axis props that make Recharts read right-to-left in Arabic: the X axis
 * is reversed and the Y axis moves to the right edge.
 */
export function useChartAxes() {
    const { isRtl } = useI18n();

    const tick = { fill: 'var(--muted-foreground)', fontSize: 11 };

    return {
        xAxis: { reversed: isRtl, tick, tickLine: false, axisLine: false, tickMargin: 8 } as const,
        yAxis: { orientation: isRtl ? ('right' as const) : ('left' as const), tick, tickLine: false, axisLine: false, width: 44 },
        grid: { stroke: 'var(--border)', strokeDasharray: '3 4', vertical: false },
    };
}

type TooltipEntry = { name?: string | number; value?: number | string; color?: string; dataKey?: string | number; payload?: Record<string, unknown> };

/**
 * Tooltip body for Recharts' <Tooltip content={...} />.
 */
export function ChartTooltip({
    active,
    payload,
    label,
    formatter,
    labelFormatter,
}: {
    active?: boolean;
    payload?: TooltipEntry[];
    label?: string | number;
    formatter?: (value: number, name: string) => ReactNode;
    labelFormatter?: (label: string | number) => ReactNode;
}) {
    if (!active || !payload?.length) {
        return null;
    }

    return (
        <div className="bg-popover/95 min-w-36 rounded-xl border px-3 py-2 text-xs shadow-lg backdrop-blur">
            {label !== undefined && <div className="text-muted-foreground mb-1.5 font-medium">{labelFormatter ? labelFormatter(label) : label}</div>}
            <div className="space-y-1">
                {payload.map((entry, i) => (
                    <div key={i} className="flex items-center justify-between gap-4">
                        <span className="flex items-center gap-1.5">
                            <span className="size-2 rounded-full" style={{ backgroundColor: entry.color }} />
                            {entry.name}
                        </span>
                        <span className="num font-semibold">
                            {formatter ? formatter(Number(entry.value), String(entry.name)) : entry.value}
                        </span>
                    </div>
                ))}
            </div>
        </div>
    );
}

export function ChartLegend({ items }: { items: { label: string; color: string }[] }) {
    return (
        <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs">
            {items.map((item) => (
                <span key={item.label} className="text-muted-foreground flex items-center gap-1.5">
                    <span className="size-2.5 rounded-sm" style={{ backgroundColor: item.color }} />
                    {item.label}
                </span>
            ))}
        </div>
    );
}

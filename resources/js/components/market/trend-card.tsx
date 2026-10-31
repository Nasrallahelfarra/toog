import { TrendingDown, TrendingUp, Minus, Check, CircleDashed } from 'lucide-react';
import type { ReactNode } from 'react';
import { PlatformBadge } from '@/components/market/platform-badge';
import { Meter } from '@/components/mojo/meter';
import { HoverLift } from '@/components/mojo/motion';
import { useI18n } from '@/hooks/use-i18n';
import { useVocabulary } from '@/hooks/use-vocabulary';
import { cn } from '@/lib/utils';

export type Trend = {
    id: number;
    type: 'color' | 'pattern' | 'style' | 'shape' | 'material' | 'theme';
    value: string | null;
    name_ar: string;
    name_en: string;
    momentum: number;
    growth: number;
    saturation: number;
    status: 'rising' | 'stable' | 'declining';
    platforms: string[];
    in_catalog: boolean;
    detected_at: string | null;
};

export const statusStyle = {
    rising: { icon: TrendingUp, className: 'bg-success-soft text-success' },
    stable: { icon: Minus, className: 'bg-info-soft text-info' },
    declining: { icon: TrendingDown, className: 'bg-danger-soft text-danger' },
};

/**
 * One trend: what it is, how fast it moves and how crowded it already is.
 */
export function TrendCard({ trend, action, delay = 0 }: { trend: Trend; action?: ReactNode; delay?: number }) {
    const { t, field, number } = useI18n();
    const { hex } = useVocabulary();
    const status = statusStyle[trend.status];
    const StatusIcon = status.icon;
    const swatch = trend.type === 'color' && trend.value ? hex(trend.value) : null;

    return (
        <HoverLift className="h-full">
            <div className="bg-card flex h-full flex-col gap-4 rounded-2xl border border-border/70 p-4 shadow-[0_1px_2px_rgba(16,24,40,0.04)] transition-shadow hover:shadow-md">
                <div className="flex items-start justify-between gap-3">
                    <div className="flex min-w-0 items-center gap-3">
                        {swatch ? (
                            <span className="size-10 shrink-0 rounded-xl shadow-inner ring-1 ring-black/10" style={{ backgroundColor: swatch }} />
                        ) : (
                            <span className="bg-primary/8 text-primary dark:bg-primary/15 flex size-10 shrink-0 items-center justify-center rounded-xl text-xs font-semibold">
                                {t.market.types[trend.type]?.slice(0, 3)}
                            </span>
                        )}
                        <div className="min-w-0">
                            <div className="truncate font-semibold">{field(trend, 'name')}</div>
                            <div className="text-muted-foreground text-xs">{t.market.types[trend.type]}</div>
                        </div>
                    </div>
                    <span className={cn('inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium', status.className)}>
                        <StatusIcon className="size-3.5 rtl:-scale-x-100" />
                        <bdi dir="ltr" className="num">
                            {trend.growth > 0 ? '+' : ''}
                            {number(trend.growth, 0)}%
                        </bdi>
                    </span>
                </div>

                <div className="space-y-3">
                    <Meter label={t.market.momentum} value={trend.momentum} tone="navy" height={6} delay={delay} />
                    <Meter
                        label={t.market.saturation}
                        value={trend.saturation}
                        tone={trend.saturation >= 70 ? 'danger' : trend.saturation >= 45 ? 'warning' : 'success'}
                        height={6}
                        delay={delay + 0.05}
                    />
                </div>

                <div className="mt-auto flex flex-wrap items-center justify-between gap-2">
                    <div className="flex flex-wrap items-center gap-1">
                        {trend.platforms.slice(0, 4).map((p) => (
                            <PlatformBadge key={p} platform={p} withLabel={false} />
                        ))}
                    </div>
                    {action ?? (
                        <span
                            className={cn(
                                'inline-flex items-center gap-1 text-xs',
                                trend.in_catalog ? 'text-muted-foreground' : 'text-gold-foreground dark:text-gold font-medium',
                            )}
                        >
                            {trend.in_catalog ? <Check className="size-3.5" /> : <CircleDashed className="size-3.5" />}
                            {trend.in_catalog ? t.market.inCatalog : t.market.notInCatalog}
                        </span>
                    )}
                </div>
            </div>
        </HoverLift>
    );
}

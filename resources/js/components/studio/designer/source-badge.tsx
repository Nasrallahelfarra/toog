import {
    CalendarClock,
    Feather,
    Swords,
    TrendingUp,
    Trophy,
} from 'lucide-react';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';
import type { SourceKey } from './types';

const styles: Record<SourceKey, { icon: typeof Trophy; cls: string }> = {
    own_sales: { icon: Trophy, cls: 'bg-navy text-white dark:text-background' },
    competitors: {
        icon: Swords,
        cls: 'border border-foreground/20 text-foreground',
    },
    trend: { icon: TrendingUp, cls: 'bg-success-soft text-success' },
    season: { icon: CalendarClock, cls: 'bg-info-soft text-info' },
    creative: {
        icon: Feather,
        cls: 'bg-gold-soft text-gold-foreground dark:text-gold',
    },
};

/** Where a concept's idea comes from: the store's sales, competitors, a trend, the season, or the designer. */
export function SourceBadge({
    source,
    className,
}: {
    source: SourceKey;
    className?: string;
}) {
    const { t } = useI18n();
    const style = styles[source] ?? styles.creative;

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-medium whitespace-nowrap',
                style.cls,
                className,
            )}
        >
            <style.icon className="size-3" />
            {t.designer.sources[source] ?? source}
        </span>
    );
}

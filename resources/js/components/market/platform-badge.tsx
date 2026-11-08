import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

/**
 * Brand-tinted marks for the monitored platforms (no third-party logos).
 */
const marks: Record<string, { short: string; className: string }> = {
    salla: { short: 'س', className: 'bg-[#E6F7F3] text-[#0B7A63] dark:bg-[#0B7A63]/25 dark:text-[#7BE0C8]' },
    zid: { short: 'ز', className: 'bg-[#F1EBFF] text-[#5B33C7] dark:bg-[#5B33C7]/25 dark:text-[#C3B0FF]' },
    shopify: { short: 'S', className: 'bg-[#EEF7E6] text-[#4E7D23] dark:bg-[#4E7D23]/25 dark:text-[#B5E08F]' },
    instagram: { short: 'IG', className: 'bg-[#FDECF3] text-[#C1356F] dark:bg-[#C1356F]/25 dark:text-[#F7A8CA]' },
    tiktok: { short: 'TT', className: 'bg-[#EAF8FA] text-[#0E7C86] dark:bg-[#0E7C86]/25 dark:text-[#8EE3EA]' },
    facebook: { short: 'FB', className: 'bg-[#EAF1FE] text-[#1F5BC6] dark:bg-[#1F5BC6]/25 dark:text-[#A9C6FB]' },
    snapchat: { short: 'SC', className: 'bg-[#FFFAD6] text-[#8A7400] dark:bg-[#8A7400]/25 dark:text-[#FFF06A]' },
};

export function PlatformBadge({ platform, withLabel = true, className }: { platform: string; withLabel?: boolean; className?: string }) {
    const { t } = useI18n();
    const mark = marks[platform] ?? { short: platform.slice(0, 2).toUpperCase(), className: 'bg-muted text-muted-foreground' };
    const label = t.market.platforms[platform as keyof typeof t.market.platforms] ?? platform;

    return (
        <span className={cn('inline-flex items-center gap-1.5 text-xs', className)} title={label}>
            <span className={cn('flex h-5 min-w-5 items-center justify-center rounded-md px-1 text-[10px] font-bold', mark.className)}>
                {mark.short}
            </span>
            {withLabel && <span className="text-muted-foreground">{label}</span>}
        </span>
    );
}

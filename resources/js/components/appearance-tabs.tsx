import type { LucideIcon } from 'lucide-react';
import { Monitor, Moon, Sun } from 'lucide-react';
import type { HTMLAttributes } from 'react';
import type { Appearance } from '@/hooks/use-appearance';
import { useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';
import { useI18n } from '@/hooks/use-i18n';

export default function AppearanceToggleTab({
    className = '',
    ...props
}: HTMLAttributes<HTMLDivElement>) {
    const { appearance, updateAppearance } = useAppearance();
    const { t } = useI18n();

    const tabs: { value: Appearance; icon: LucideIcon; label: string }[] = [
        { value: 'light', icon: Sun, label: t.common.light },
        { value: 'dark', icon: Moon, label: t.common.dark },
        { value: 'system', icon: Monitor, label: t.common.system },
    ];

    return (
        <div
            className={cn(
                'bg-muted inline-flex gap-1 rounded-xl p-1',
                className,
            )}
            {...props}
        >
            {tabs.map(({ value, icon: Icon, label }) => (
                <button
                    key={value}
                    onClick={() => updateAppearance(value)}
                    className={cn(
                        'flex cursor-pointer items-center gap-1.5 rounded-lg px-3.5 py-1.5 transition-colors',
                        appearance === value
                            ? 'bg-card text-foreground shadow-xs'
                            : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    <Icon className="h-4 w-4" />
                    <span className="text-sm">{label}</span>
                </button>
            ))}
        </div>
    );
}

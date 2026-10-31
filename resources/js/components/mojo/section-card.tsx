import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * The standard content panel: title row with optional action, then body.
 */
export function SectionCard({
    title,
    description,
    icon: Icon,
    action,
    children,
    className,
    bodyClassName,
    flush = false,
}: {
    title?: ReactNode;
    description?: ReactNode;
    icon?: LucideIcon;
    action?: ReactNode;
    children: ReactNode;
    className?: string;
    bodyClassName?: string;
    flush?: boolean;
}) {
    return (
        <section
            className={cn(
                'bg-card text-card-foreground flex flex-col rounded-2xl border border-border/70 shadow-[0_1px_2px_rgba(16,24,40,0.04)]',
                className,
            )}
        >
            {(title || action) && (
                <header className="flex items-start justify-between gap-3 px-5 pt-5 pb-1">
                    <div className="flex items-start gap-2.5">
                        {Icon && <Icon className="text-muted-foreground mt-0.5 size-[18px] shrink-0" />}
                        <div className="space-y-0.5">
                            {title && <h2 className="text-[15px] font-semibold">{title}</h2>}
                            {description && <p className="text-muted-foreground text-xs leading-relaxed">{description}</p>}
                        </div>
                    </div>
                    {action && <div className="flex shrink-0 items-center gap-2">{action}</div>}
                </header>
            )}
            <div className={cn(flush ? 'pt-3' : 'p-5 pt-4', bodyClassName)}>{children}</div>
        </section>
    );
}

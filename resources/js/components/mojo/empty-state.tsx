import type { LucideIcon } from 'lucide-react';
import { Inbox } from 'lucide-react';
import type { ReactNode } from 'react';
import { FadeIn } from '@/components/mojo/motion';
import { cn } from '@/lib/utils';

export function EmptyState({
    icon: Icon = Inbox,
    title,
    description,
    action,
    className,
}: {
    icon?: LucideIcon;
    title: string;
    description?: ReactNode;
    action?: ReactNode;
    className?: string;
}) {
    return (
        <FadeIn className={cn('flex flex-col items-center justify-center px-6 py-14 text-center', className)}>
            <div className="relative mb-5">
                <div className="text-primary/10 bg-dots absolute -inset-6 rounded-full" />
                <div className="bg-card ring-border relative flex size-14 items-center justify-center rounded-2xl shadow-sm ring-1">
                    <Icon className="text-primary size-6" />
                </div>
            </div>
            <h3 className="text-base font-semibold">{title}</h3>
            {description && <p className="text-muted-foreground mt-1.5 max-w-sm text-sm leading-relaxed">{description}</p>}
            {action && <div className="mt-5">{action}</div>}
        </FadeIn>
    );
}

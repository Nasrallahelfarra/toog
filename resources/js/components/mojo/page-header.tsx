import { Head } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { FadeIn } from '@/components/mojo/motion';
import { cn } from '@/lib/utils';

/**
 * Page title block: sets the document title too, so pages need no <Head>.
 */
export function PageHeader({
    title,
    description,
    icon: Icon,
    actions,
    eyebrow,
    className,
}: {
    title: string;
    description?: ReactNode;
    icon?: LucideIcon;
    actions?: ReactNode;
    eyebrow?: ReactNode;
    className?: string;
}) {
    return (
        <>
            <Head title={title} />
            <FadeIn y={6} className={cn('flex flex-col gap-4 md:flex-row md:items-end md:justify-between', className)}>
                <div className="flex items-start gap-3.5">
                    {Icon && (
                        <div className="bg-primary/8 text-primary ring-primary/10 dark:bg-primary/15 hidden size-11 shrink-0 items-center justify-center rounded-xl ring-1 sm:flex">
                            <Icon className="size-5" />
                        </div>
                    )}
                    <div className="space-y-1">
                        {eyebrow && <div className="text-gold-foreground dark:text-gold text-xs font-medium">{eyebrow}</div>}
                        <h1 className="text-2xl font-semibold tracking-tight text-balance">{title}</h1>
                        {description && <p className="text-muted-foreground max-w-2xl text-sm leading-relaxed">{description}</p>}
                    </div>
                </div>
                {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
            </FadeIn>
        </>
    );
}

/**
 * Standard page body padding and vertical rhythm.
 */
export function PageBody({ children, className }: { children: ReactNode; className?: string }) {
    return <div className={cn('mx-auto flex w-full max-w-[1400px] flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8', className)}>{children}</div>;
}

import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

/**
 * Label + control + hint/error, the standard form row in the admin panel.
 */
export function Field({
    label,
    error,
    hint,
    className,
    htmlFor,
    children,
}: {
    label: ReactNode;
    error?: string;
    hint?: ReactNode;
    className?: string;
    htmlFor?: string;
    children: ReactNode;
}) {
    return (
        <div className={cn('grid content-start gap-2', className)}>
            <Label htmlFor={htmlFor}>{label}</Label>
            {children}
            {hint && !error && <p className="text-muted-foreground text-xs leading-relaxed">{hint}</p>}
            <InputError message={error} />
        </div>
    );
}

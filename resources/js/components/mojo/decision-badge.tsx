import { CheckCircle2, CircleDashed, Loader2, PenLine, XCircle } from 'lucide-react';
import { decisionTone, toneClasses } from '@/components/mojo/tone';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

type Decision = 'accept' | 'reject' | 'modify' | 'approved' | 'rejected' | 'needs_changes' | 'pending' | 'processing' | 'failed';

const icons = {
    accept: CheckCircle2,
    approved: CheckCircle2,
    reject: XCircle,
    rejected: XCircle,
    failed: XCircle,
    modify: PenLine,
    needs_changes: PenLine,
    pending: CircleDashed,
    processing: Loader2,
};

/**
 * Accept / reject / modify (or a final human decision) as a coloured pill.
 */
export function DecisionBadge({ value, size = 'sm', className }: { value: string | null | undefined; size?: 'sm' | 'lg'; className?: string }) {
    const { t } = useI18n();

    if (!value) {
        return null;
    }

    const key = value as Decision;
    const tone = toneClasses[decisionTone[key] ?? 'neutral'];
    const Icon = icons[key] ?? CircleDashed;

    return (
        <span
            className={cn(
                'inline-flex w-fit items-center gap-1.5 rounded-full font-medium whitespace-nowrap',
                tone.soft,
                tone.text,
                size === 'lg' ? 'px-3.5 py-1.5 text-sm' : 'px-2.5 py-0.5 text-xs',
                className,
            )}
        >
            <Icon className={cn(size === 'lg' ? 'size-4' : 'size-3.5', key === 'processing' && 'animate-spin')} />
            {t.common.decision[key] ?? value}
        </span>
    );
}

/**
 * Winner / average / stagnant classification pill (FR-1.3).
 */
export function PerformanceBadge({ value, className }: { value: string | null | undefined; className?: string }) {
    const { t } = useI18n();

    if (!value) {
        return <span className="text-muted-foreground text-xs">—</span>;
    }

    const tone = toneClasses[value === 'winner' ? 'success' : value === 'stagnant' ? 'danger' : 'info'];

    return (
        <span className={cn('inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium', tone.soft, tone.text, className)}>
            <span className={cn('size-1.5 rounded-full', tone.bg)} />
            {t.common.performanceClass[value as 'winner' | 'average' | 'stagnant'] ?? value}
        </span>
    );
}

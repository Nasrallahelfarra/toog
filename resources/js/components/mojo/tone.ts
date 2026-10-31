export type Tone = 'navy' | 'gold' | 'success' | 'warning' | 'danger' | 'info' | 'neutral';

/**
 * Class sets for each semantic tone, so every module colours status the
 * same way (accept = success, modify = warning, reject = danger).
 */
export const toneClasses: Record<Tone, { text: string; bg: string; soft: string; ring: string; stroke: string }> = {
    navy: { text: 'text-primary', bg: 'bg-primary', soft: 'bg-primary/8 dark:bg-primary/15', ring: 'ring-primary/20', stroke: 'var(--primary)' },
    gold: { text: 'text-gold-foreground dark:text-gold', bg: 'bg-gold', soft: 'bg-gold-soft', ring: 'ring-gold/30', stroke: 'var(--gold)' },
    success: { text: 'text-success', bg: 'bg-success', soft: 'bg-success-soft', ring: 'ring-success/25', stroke: 'var(--success)' },
    warning: { text: 'text-warning', bg: 'bg-warning', soft: 'bg-warning-soft', ring: 'ring-warning/25', stroke: 'var(--warning)' },
    danger: { text: 'text-danger', bg: 'bg-danger', soft: 'bg-danger-soft', ring: 'ring-danger/25', stroke: 'var(--danger)' },
    info: { text: 'text-info', bg: 'bg-info', soft: 'bg-info-soft', ring: 'ring-info/25', stroke: 'var(--info)' },
    neutral: { text: 'text-muted-foreground', bg: 'bg-muted-foreground', soft: 'bg-muted', ring: 'ring-border', stroke: 'var(--muted-foreground)' },
};

/**
 * Tone for a 0-100 score against the store's decision thresholds.
 */
export function scoreTone(score: number | null | undefined, accept = 75, reject = 50): Tone {
    if (score === null || score === undefined) {
        return 'neutral';
    }

    if (score >= accept) {
        return 'success';
    }

    return score < reject ? 'danger' : 'warning';
}

export const decisionTone: Record<string, Tone> = {
    accept: 'success',
    approved: 'success',
    modify: 'warning',
    needs_changes: 'warning',
    reject: 'danger',
    rejected: 'danger',
};

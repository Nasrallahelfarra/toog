import { Link } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { Initials } from '@/components/admin/badges';
import { useI18n } from '@/hooks/use-i18n';
import type { Dictionary } from '@/i18n';
import { cn } from '@/lib/utils';

export type ActivityRow = {
    id: number;
    action: string;
    user: { id: number; name: string; email: string } | null;
    store: { id: number; name: string } | null;
    subject_type: string | null;
    subject_id: number | null;
    properties: Record<string, unknown> | null;
    acting_as_admin: boolean;
    ip: string | null;
    created_at: string;
};

/**
 * Readable label for an activity key; unknown keys are humanised.
 */
export function actionLabel(t: Dictionary, action: string): string {
    return (
        t.admin.actions[action] ??
        action
            .split('.')
            .pop()!
            .replace(/_/g, ' ')
            .replace(/^\w/, (c) => c.toUpperCase())
    );
}

/**
 * Short summary of an activity's properties (reason, changed fields…).
 */
export function ActivityDetails({ properties }: { properties: Record<string, unknown> | null }) {
    const { t } = useI18n();

    if (!properties) {
        return null;
    }

    const parts: string[] = [];
    const changes = properties.changes as Record<string, { from: unknown; to: unknown }> | undefined;

    if (changes && Object.keys(changes).length) {
        parts.push(
            Object.entries(changes)
                .map(([key, c]) => `${key}: ${format(c.from)} → ${format(c.to)}`)
                .join(' · '),
        );
    }

    for (const key of ['reason', 'name', 'email', 'owner', 'field', 'report', 'format']) {
        if (properties[key]) {
            parts.push(`${key === 'reason' ? t.common.reason : key}: ${format(properties[key])}`);
        }
    }

    if (properties.from !== undefined && properties.to !== undefined) {
        parts.push(`${format(properties.from)} → ${format(properties.to)}`);
    }

    return parts.length ? <span className="text-muted-foreground line-clamp-2 text-xs">{parts.join(' — ')}</span> : null;
}

function format(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (Array.isArray(value)) {
        return value.join(', ');
    }

    return typeof value === 'string' ? value : JSON.stringify(value);
}

/**
 * Compact activity timeline used on the overview and store pages.
 */
export function ActivityFeed({ rows, showStore = true, className }: { rows: ActivityRow[]; showStore?: boolean; className?: string }) {
    const { t, relative, dateTime } = useI18n();

    return (
        <ol className={cn('divide-border/60 divide-y', className)}>
            {rows.map((row) => (
                <li key={row.id} className="flex items-start gap-3 py-3 first:pt-0 last:pb-0">
                    <Initials name={row.user?.name ?? t.admin.activity.system} className="size-8 rounded-lg" />
                    <div className="min-w-0 flex-1 space-y-0.5">
                        <p className="text-sm leading-snug">
                            <span className="font-medium">{row.user?.name ?? t.admin.activity.system}</span>{' '}
                            <span className="text-foreground/80">{actionLabel(t, row.action)}</span>
                            {showStore && row.store && (
                                <>
                                    {' · '}
                                    <Link href={`/admin/stores/${row.store.id}`} className="text-primary hover:underline">
                                        {row.store.name}
                                    </Link>
                                </>
                            )}
                        </p>
                        <ActivityDetails properties={row.properties} />
                        <div className="text-muted-foreground flex items-center gap-2 text-[11px]">
                            <time title={dateTime(row.created_at)}>{relative(row.created_at)}</time>
                            {row.acting_as_admin && (
                                <span className="text-gold-foreground dark:text-gold inline-flex items-center gap-1">
                                    <ShieldCheck className="size-3" />
                                    {t.admin.activity.viaAdmin}
                                </span>
                            )}
                        </div>
                    </div>
                </li>
            ))}
        </ol>
    );
}

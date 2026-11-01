import { usePoll } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    Circle,
    Clock3,
    Loader2,
    Package,
    ShoppingBag,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { SectionCard } from '@/components/mojo/section-card';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

export type ZidSyncProgressData = {
    id: number;
    status: 'queued' | 'running' | 'completed' | 'failed';
    phase: string;
    products_read: number;
    orders_read: number;
    product_pages: number;
    order_pages: number;
    products_saved: number;
    orders_saved: number;
    error_code: string | null;
    queued_at: string | null;
    started_at: string | null;
    updated_at: string | null;
    finished_at: string | null;
    retry_at: string | null;
};

export const syncIsActive = (sync: ZidSyncProgressData | null) =>
    sync?.status === 'queued' || sync?.status === 'running';
const steps = ['connecting', 'products', 'orders', 'saving'] as const;

function SyncPoller({
    refresh,
    onNetwork,
}: {
    refresh: string[];
    onNetwork: (failed: boolean) => void;
}) {
    usePoll(4000, {
        only: refresh,
        onSuccess: () => onNetwork(false),
        onNetworkError: () => {
            onNetwork(true);
            return false;
        },
        onError: () => onNetwork(true),
    });
    return null;
}

export function ZidSyncProgress({
    sync,
    refresh,
}: {
    sync: ZidSyncProgressData;
    refresh: string[];
}) {
    const { t, tr, number, dateTime, relative } = useI18n();
    const s = t.zidSync;
    const [now, setNow] = useState(() => Date.now());
    const [networkError, setNetworkError] = useState(false);
    const active = syncIsActive(sync);
    useEffect(() => {
        if (!active) return;
        const timer = window.setInterval(() => setNow(Date.now()), 1000);
        return () => window.clearInterval(timer);
    }, [active]);
    const completed = sync.status === 'completed';
    const failed = sync.status === 'failed';
    const phase = active ? sync.phase : sync.status;
    const current = steps.findIndex((step) => step === phase);
    const elapsed = Math.max(
        0,
        Math.floor(
            ((sync.finished_at ? Date.parse(sync.finished_at) : now) -
                Date.parse(sync.started_at ?? sync.queued_at ?? '')) /
                1000,
        ),
    );
    const stale =
        active &&
        phase !== 'waiting' &&
        sync.updated_at &&
        now - Date.parse(sync.updated_at) > 120_000;
    const Icon = completed
        ? CheckCircle2
        : failed
          ? AlertTriangle
          : sync.status === 'queued'
            ? Clock3
            : Loader2;
    const error =
        s.errors[sync.error_code as keyof typeof s.errors] ??
        s.errors.import_failed;

    return (
        <SectionCard title={s.title} description={s.hint} icon={Package}>
            {active && (
                <SyncPoller refresh={refresh} onNetwork={setNetworkError} />
            )}
            <div className="space-y-5" data-testid="zid-sync-progress">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p
                        role="status"
                        className={cn(
                            'flex items-center gap-2 font-semibold',
                            completed && 'text-success',
                            failed && 'text-danger',
                        )}
                    >
                        <Icon
                            className={cn(
                                'size-5 shrink-0',
                                sync.status === 'running' &&
                                    'animate-spin motion-reduce:animate-none',
                            )}
                        />
                        {s.stages[phase as keyof typeof s.stages] ??
                            s.stages.running}
                    </p>
                    <span className="text-xs text-muted-foreground">
                        {s.elapsed}:{' '}
                        {Number.isFinite(elapsed)
                            ? tr(s.seconds, { n: number(elapsed) })
                            : '—'}
                    </span>
                </div>
                <ol
                    className="grid grid-cols-2 gap-2 lg:grid-cols-4"
                    aria-label={s.title}
                >
                    {steps.map((step, index) => {
                        const done = completed || current > index;
                        const selected = !failed && phase === step;
                        const StepIcon = done
                            ? CheckCircle2
                            : selected
                              ? Loader2
                              : Circle;
                        return (
                            <li
                                key={step}
                                aria-current={selected ? 'step' : undefined}
                                className={cn(
                                    'flex items-center gap-2 rounded-xl border p-3 text-xs',
                                    selected
                                        ? 'border-primary/50 bg-primary/5 text-foreground'
                                        : 'text-muted-foreground',
                                    done && 'text-success',
                                )}
                            >
                                <StepIcon
                                    className={cn(
                                        'size-4 shrink-0',
                                        selected &&
                                            'animate-spin motion-reduce:animate-none',
                                    )}
                                />
                                {s.stages[step]}
                            </li>
                        );
                    })}
                </ol>
                <dl className="grid grid-cols-2 gap-3">
                    {[
                        {
                            label: completed ? s.productsSaved : s.products,
                            value: completed
                                ? sync.products_saved
                                : sync.products_read,
                            pages: sync.product_pages,
                            icon: Package,
                        },
                        {
                            label: completed ? s.ordersSaved : s.orders,
                            value: completed
                                ? sync.orders_saved
                                : sync.orders_read,
                            pages: sync.order_pages,
                            icon: ShoppingBag,
                        },
                    ].map((item) => (
                        <div
                            key={item.label}
                            className="rounded-xl bg-muted/50 p-4"
                        >
                            <dt className="flex items-center gap-2 text-xs text-muted-foreground">
                                <item.icon className="size-4 shrink-0" />
                                {item.label}
                            </dt>
                            <dd className="mt-2 text-2xl font-semibold tabular-nums">
                                {number(item.value)}
                            </dd>
                            <dd className="mt-1 text-xs text-muted-foreground">
                                {tr(s.pages, { n: number(item.pages) })}
                            </dd>
                        </div>
                    ))}
                </dl>
                <p className="text-xs leading-relaxed text-muted-foreground">
                    {completed ? s.saved : s.reading}
                </p>
                {active && phase === 'waiting' && sync.retry_at && (
                    <p
                        role="status"
                        className="rounded-xl bg-info-soft p-3 text-sm leading-relaxed text-info"
                    >
                        {tr(s.retrying, { time: dateTime(sync.retry_at) })}
                    </p>
                )}
                {(failed ||
                    networkError ||
                    sync.status === 'queued' ||
                    stale) && (
                    <p
                        role={failed || networkError ? 'alert' : undefined}
                        className={cn(
                            'rounded-xl p-3 text-sm leading-relaxed',
                            failed
                                ? 'bg-danger-soft text-danger'
                                : 'bg-warning-soft text-warning',
                        )}
                    >
                        {failed
                            ? error
                            : networkError
                              ? s.network
                              : sync.status === 'queued'
                                ? s.waiting
                                : s.slow}
                    </p>
                )}
                <div className="flex flex-wrap justify-between gap-2 text-xs text-muted-foreground">
                    <span>
                        {s.updated}:{' '}
                        <time
                            dateTime={sync.updated_at ?? undefined}
                            title={dateTime(sync.updated_at)}
                        >
                            {relative(sync.updated_at)}
                        </time>
                    </span>
                    <span>
                        {s.queuedAt}: {dateTime(sync.queued_at)}
                    </span>
                </div>
            </div>
        </SectionCard>
    );
}

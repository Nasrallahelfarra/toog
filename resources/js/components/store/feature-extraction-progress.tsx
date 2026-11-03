import { router, usePoll } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    Clock3,
    Hourglass,
    Loader2,
    RotateCcw,
    ScanSearch,
    Sparkles,
    XCircle,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { Meter } from '@/components/mojo/meter';
import { SectionCard } from '@/components/mojo/section-card';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

export type FeatureExtractionRun = {
    id: number;
    status: 'queued' | 'running' | 'completed' | 'failed';
    total: number;
    done: number;
    extracted: number;
    current: string | null;
    error: string | null;
    recent: boolean;
    queued_at: string | null;
    started_at: string | null;
    updated_at: string | null;
    finished_at: string | null;
};

export const extractionIsActive = (run: FeatureExtractionRun | null) =>
    run?.status === 'queued' || run?.status === 'running';

function ExtractionPoller({
    onNetwork,
}: {
    onNetwork: (failed: boolean) => void;
}) {
    usePoll(4000, {
        only: ['sync', 'extraction'],
        onSuccess: () => onNetwork(false),
        onNetworkError: () => {
            onNetwork(true);
            return false;
        },
        onError: () => onNetwork(true),
    });
    return null;
}

/** Live progress of a store-wide attribute extraction run. */
export function FeatureExtractionProgress({
    run,
    pending,
    disabled,
    onStart,
}: {
    run: FeatureExtractionRun;
    pending: number;
    disabled: boolean;
    onStart: () => void;
}) {
    const { t, tr, number, percent, dateTime, relative } = useI18n();
    const e = t.performance.extraction;
    const [now, setNow] = useState(() => Date.now());
    const [networkError, setNetworkError] = useState(false);
    const active = extractionIsActive(run);
    const wasActive = useRef(active);

    useEffect(() => {
        if (!active) return;
        const timer = window.setInterval(() => setNow(Date.now()), 1000);
        return () => window.clearInterval(timer);
    }, [active]);

    useEffect(() => {
        if (wasActive.current && run.status === 'completed') {
            toast.success(tr(e.done, { count: number(run.extracted) }));
            // Refresh the profile, quality and product table with the new attributes.
            router.reload();
        }
        wasActive.current = active;
    }, [active, run.status, run.extracted, e, tr, number]);

    const completed = run.status === 'completed';
    const failed = run.status === 'failed';
    // While running, count down from the run; afterwards show what is really left.
    const remaining = active ? Math.max(0, run.total - run.done) : pending;
    const share = run.total ? (run.done / run.total) * 100 : 100;
    const elapsed = Math.max(
        0,
        Math.floor(
            ((run.finished_at ? Date.parse(run.finished_at) : now) -
                Date.parse(run.started_at ?? run.queued_at ?? '')) /
                1000,
        ),
    );
    // Average pace so far, once a few products give a steady estimate.
    const eta =
        run.status === 'running' && run.done >= 3 && Number.isFinite(elapsed)
            ? Math.round((elapsed / run.done) * remaining)
            : null;
    const stale =
        run.status === 'running' &&
        run.updated_at &&
        now - Date.parse(run.updated_at) > 120_000;
    const unread = run.done - run.extracted;
    const Icon = completed
        ? CheckCircle2
        : failed
          ? AlertTriangle
          : run.status === 'queued'
            ? Clock3
            : Loader2;
    const error =
        e.errors[run.error as keyof typeof e.errors] ?? e.errors.timed_out;

    const duration = (seconds: number) => {
        if (!Number.isFinite(seconds)) return '—';
        const h = Math.floor(seconds / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        const s = seconds % 60;
        return h
            ? tr(e.hours, { h: number(h), m: number(m) })
            : m
              ? tr(e.minutes, { m: number(m), s: number(s) })
              : tr(e.seconds, { n: number(s) });
    };

    return (
        <SectionCard title={e.title} description={e.hint} icon={Sparkles}>
            {active && <ExtractionPoller onNetwork={setNetworkError} />}
            <div
                className="space-y-5"
                data-testid="feature-extraction-progress"
            >
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
                                run.status === 'running' &&
                                    'animate-spin motion-reduce:animate-none',
                            )}
                        />
                        {e.stages[run.status]}
                    </p>
                    <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                        <span>
                            {e.elapsed}: {duration(elapsed)}
                        </span>
                        {eta !== null && (
                            <span>
                                {e.eta}: {duration(eta)}
                            </span>
                        )}
                    </div>
                </div>

                <Meter
                    value={share}
                    tone={failed ? 'danger' : completed ? 'success' : 'gold'}
                    height={10}
                    label={tr(e.progress, {
                        done: number(run.done),
                        total: number(run.total),
                    })}
                    valueLabel={percent(share)}
                />

                {run.status === 'running' && run.current && (
                    <p className="flex min-w-0 items-center gap-2 rounded-xl border border-gold/30 bg-gold-soft px-3 py-2 text-sm">
                        <ScanSearch className="size-4 shrink-0 text-gold-foreground dark:text-gold" />
                        <span className="shrink-0 text-muted-foreground">
                            {e.current}:
                        </span>
                        <span className="truncate font-medium">
                            {run.current}
                        </span>
                    </p>
                )}

                <dl className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    {[
                        { label: e.read, value: run.done, icon: ScanSearch },
                        {
                            label: e.extracted,
                            value: run.extracted,
                            icon: Sparkles,
                            className: run.extracted > 0 && 'text-success',
                        },
                        {
                            label: e.unread,
                            value: unread,
                            icon: XCircle,
                            className: unread > 0 && 'text-danger',
                        },
                        {
                            label: e.remaining,
                            value: remaining,
                            icon: Hourglass,
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
                            <dd
                                className={cn(
                                    'mt-2 text-2xl font-semibold tabular-nums',
                                    item.className,
                                )}
                            >
                                {number(item.value)}
                            </dd>
                        </div>
                    ))}
                </dl>

                {completed && (
                    <p className="rounded-xl bg-success-soft p-3 text-sm leading-relaxed text-success">
                        {tr(e.summary, {
                            extracted: number(run.extracted),
                            total: number(run.total),
                        })}
                    </p>
                )}
                {(failed ||
                    networkError ||
                    run.status === 'queued' ||
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
                              ? e.network
                              : run.status === 'queued'
                                ? e.waiting
                                : e.slow}
                    </p>
                )}
                {!active && pending > 0 && (
                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border p-3">
                        <p className="text-sm leading-relaxed text-muted-foreground">
                            {tr(e.pendingHint, { count: number(pending) })}
                        </p>
                        <Button
                            size="sm"
                            variant="gold"
                            disabled={disabled}
                            onClick={onStart}
                        >
                            <RotateCcw className="size-4" />
                            {failed ? e.retry : e.continue}
                        </Button>
                    </div>
                )}

                <div className="flex flex-wrap justify-between gap-2 text-xs text-muted-foreground">
                    <span>
                        {e.updated}:{' '}
                        <time
                            dateTime={run.updated_at ?? undefined}
                            title={dateTime(run.updated_at)}
                        >
                            {relative(run.updated_at)}
                        </time>
                    </span>
                    <span>
                        {e.queuedAt}: {dateTime(run.queued_at)}
                    </span>
                </div>
            </div>
        </SectionCard>
    );
}

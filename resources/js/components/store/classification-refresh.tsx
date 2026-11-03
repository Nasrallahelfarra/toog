import { router, useForm, usePoll } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    Clock3,
    Loader2,
    RefreshCw,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/hooks/use-i18n';

export type ClassificationRun = {
    id: number;
    status: 'queued' | 'running' | 'completed' | 'failed';
    error: string | null;
    total: number;
    queued_at: string | null;
    recent: boolean;
};

const isActive = (run: ClassificationRun | null) =>
    run?.status === 'queued' || run?.status === 'running';

export function ClassificationButton({
    run,
    disabled = false,
    outline = false,
}: {
    run: ClassificationRun | null;
    disabled?: boolean;
    outline?: boolean;
}) {
    const { t } = useI18n();
    const c = t.performance.classification;
    const form = useForm({});
    const active = isActive(run) || form.processing;
    return (
        <Button
            variant={outline ? 'outline' : 'default'}
            disabled={disabled || active}
            onClick={() =>
                form.post('/store/performance/reclassify', {
                    preserveScroll: true,
                    onError: (errors) =>
                        toast.error(
                            Object.values(errors)[0] ?? c.requestFailed,
                        ),
                    onNetworkError: () => {
                        toast.error(c.requestFailed);
                        return false;
                    },
                })
            }
        >
            {active ? (
                <Loader2 className="size-4 animate-spin motion-reduce:animate-none" />
            ) : (
                <RefreshCw className="size-4" />
            )}
            {active ? c.active : t.stagnant.reclassify}
        </Button>
    );
}

function ClassificationPoller({
    onNetwork,
}: {
    onNetwork: (failed: boolean) => void;
}) {
    usePoll(4000, {
        only: ['classification'],
        onSuccess: () => onNetwork(false),
        onError: () => onNetwork(true),
        onNetworkError: () => {
            onNetwork(true);
            return false;
        },
    });
    return null;
}

export function ClassificationProgress({
    run,
}: {
    run: ClassificationRun | null;
}) {
    const { t, tr, number } = useI18n();
    const c = t.performance.classification;
    const active = isActive(run);
    const previous = useRef(run);
    const [networkError, setNetworkError] = useState(false);

    useEffect(() => {
        if (
            run?.status === 'completed' &&
            previous.current?.id === run.id &&
            isActive(previous.current)
        ) {
            toast.success(tr(c.completed, { count: number(run.total) }));
            router.reload();
        }
        previous.current = run;
    }, [run, c.completed, number, tr]);

    if (!run?.recent) return null;
    const failed = run.status === 'failed';
    const Icon = failed
        ? AlertTriangle
        : run.status === 'completed'
          ? CheckCircle2
          : run.status === 'running'
            ? Loader2
            : Clock3;
    return (
        <div
            role={failed ? 'alert' : 'status'}
            aria-live="polite"
            data-testid="classification-progress"
            className="rounded-2xl border bg-card p-4 text-sm leading-7"
        >
            {active && <ClassificationPoller onNetwork={setNetworkError} />}
            <div className="flex items-center gap-2 font-semibold">
                <Icon
                    className={`size-5 shrink-0 ${failed ? 'text-danger' : run.status === 'completed' ? 'text-success' : 'text-primary'} ${run.status === 'running' ? 'animate-spin motion-reduce:animate-none' : ''}`}
                />
                {run.status === 'completed'
                    ? tr(c.completed, { count: number(run.total) })
                    : c.stages[run.status]}
            </div>
            <p className="mt-1 text-muted-foreground">
                {failed
                    ? (c.errors[run.error as keyof typeof c.errors] ??
                      c.errors.job_failed)
                    : active
                      ? c.background
                      : c.updated}
            </p>
            {run.status === 'queued' && (
                <p className="mt-1 text-xs text-muted-foreground">
                    {c.waiting}
                </p>
            )}
            {networkError && active && (
                <p className="mt-2 text-xs text-danger">{c.network}</p>
            )}
        </div>
    );
}

import {
    AlertTriangle,
    CheckCircle2,
    Circle,
    LoaderCircle,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { Progress } from '@/components/ui/progress';
import { useI18n } from '@/hooks/use-i18n';

export type CompetitorScanData = {
    id: number;
    status: 'queued' | 'running' | 'completed' | 'failed';
    stage: string;
    progress: number;
    metrics: { pages?: number; products?: number; posts?: number } | null;
    error_code: string | null;
    error: string | null;
    created_at: string;
    started_at: string | null;
    finished_at: string | null;
};

export const isScanning = (scan: CompetitorScanData | null) =>
    scan?.status === 'queued' || scan?.status === 'running';

export function CompetitorScanProgress({
    scan,
    social = false,
    compact = false,
}: {
    scan: CompetitorScanData;
    /** Social accounts are read through Apify, with their own stage names. */
    social?: boolean;
    compact?: boolean;
}) {
    const { t, number, tr } = useI18n();
    const c = t.market.competitors.scanProgress;
    const stageLabels = social ? c.socialStages : c.stages;
    const active = isScanning(scan);
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        if (!active) return;
        const timer = window.setInterval(() => setNow(Date.now()), 1000);
        return () => window.clearInterval(timer);
    }, [active]);
    const elapsed = Math.max(
        0,
        Math.floor(
            ((scan.finished_at ? Date.parse(scan.finished_at) : now) -
                Date.parse(scan.created_at)) /
                1000,
        ),
    );
    const stages = [
        'rules',
        'storefront',
        'products',
        'saving',
        'analysis',
    ] as const;
    const title =
        scan.status === 'queued'
            ? c.queued
            : scan.status === 'completed'
              ? c.completed
              : scan.status === 'failed'
                ? c.failed
                : (stageLabels[scan.stage as (typeof stages)[number]] ??
                  c.running);
    const Icon = active
        ? LoaderCircle
        : scan.status === 'completed'
          ? CheckCircle2
          : AlertTriangle;
    const error =
        c.errors[scan.error_code as keyof typeof c.errors] ??
        c.errors.job_failed;

    return (
        <section
            aria-label={c.title}
            className={`space-y-3 rounded-xl border p-4 ${scan.status === 'failed' ? 'border-danger/30 bg-danger-soft/30' : 'bg-muted/30'}`}
        >
            <div
                className="flex items-center justify-between gap-3 text-sm font-medium"
                aria-live="polite"
            >
                <span className="flex items-center gap-2">
                    <Icon
                        className={`size-4 shrink-0 ${active ? 'animate-spin' : ''}`}
                    />
                    {title}
                </span>
                <span className="num shrink-0">{number(scan.progress)}%</span>
            </div>
            <Progress value={scan.progress} aria-label={c.title} />
            <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                {social ? (
                    <span>
                        {tr(c.posts, {
                            count: number(scan.metrics?.posts ?? 0),
                        })}
                    </span>
                ) : (
                    <>
                        <span>
                            {tr(c.pages, {
                                count: number(scan.metrics?.pages ?? 0),
                            })}
                        </span>
                        <span>
                            {tr(c.products, {
                                count: number(scan.metrics?.products ?? 0),
                            })}
                        </span>
                    </>
                )}
                {!compact && (
                    <span>{tr(c.elapsed, { seconds: number(elapsed) })}</span>
                )}
            </div>
            {!compact && (
                <details className="border-t border-border/60 pt-3 text-xs">
                    <summary className="cursor-pointer text-muted-foreground hover:text-foreground">
                        {t.market.competitors.workspace.scanDetails}
                    </summary>
                    <ol className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                        {stages.map((stage, i) => {
                            const done = scan.progress >= (i + 1) * 20;
                            const current = scan.stage === stage && active;
                            const StepIcon = done
                                ? CheckCircle2
                                : current
                                  ? LoaderCircle
                                  : Circle;
                            return (
                                <li
                                    key={stage}
                                    className={`flex items-center gap-2 ${done ? 'text-success' : current ? 'font-semibold' : 'text-muted-foreground'}`}
                                >
                                    <StepIcon
                                        className={`size-3.5 ${current ? 'animate-spin' : ''}`}
                                    />
                                    {stageLabels[stage]}
                                </li>
                            );
                        })}
                    </ol>
                    <p className="mt-4 text-muted-foreground">{c.phaseNote}</p>
                </details>
            )}
            {scan.status === 'queued' && elapsed >= 45 && (
                <p className="text-xs leading-relaxed text-warning">
                    {c.waitingLong}
                </p>
            )}
            {scan.status === 'failed' && (
                <div role="alert" className="space-y-1 text-sm text-danger">
                    <p>{error}</p>
                    {!compact && scan.error && (
                        <p className="text-xs break-words" dir="auto">
                            {scan.error}
                        </p>
                    )}
                </div>
            )}
        </section>
    );
}

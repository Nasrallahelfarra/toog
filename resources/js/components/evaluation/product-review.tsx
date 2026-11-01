import { Link, useForm, usePoll } from '@inertiajs/react';
import {
    CheckCircle2,
    ExternalLink,
    Loader2,
    ScanEye,
    Sparkles,
    Wand2,
} from 'lucide-react';
import { useState } from 'react';
import type { EvaluationDetail, Improvement, SpecialistReview } from './types';
import InputError from '@/components/input-error';
import { SectionCard } from '@/components/mojo/section-card';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

export function ProductReviewPanel({
    evaluation,
    improvements,
    canImprove,
}: {
    evaluation: EvaluationDetail;
    improvements: Improvement[];
    canImprove: boolean;
}) {
    const { t, locale } = useI18n();
    const c = t.productReview;
    const review = evaluation.specialist_review;
    const active = improvements.some(
        (r) => !['completed', 'failed'].includes(r.status),
    );
    const retry = useForm({});

    return (
        <div id="specialist-review" className="scroll-mt-24 space-y-6">
            {active && <ImprovementPoll />}
            <SectionCard
                title={c.title}
                description={c.subtitle}
                icon={ScanEye}
            >
                {review?.status === 'available' ? (
                    <>
                        <div className="flex flex-col gap-3 rounded-xl bg-primary/5 p-4 sm:flex-row sm:items-start sm:justify-between">
                            <div className="min-w-0 space-y-2">
                                <h3 className="font-semibold">
                                    {review.verdict &&
                                        c.verdicts[review.verdict]}
                                </h3>
                                <p className="text-sm leading-7">
                                    {review.summary?.[locale]}
                                </p>
                            </div>
                            <span className="shrink-0 rounded-lg border bg-background px-3 py-2 text-xs">
                                {c.confidence}:{' '}
                                {review.confidence &&
                                    c.levels[review.confidence]}
                            </span>
                        </div>
                        {evaluation.target_audience && (
                            <p className="mt-4 text-sm">
                                <strong>{c.audience}: </strong>
                                {evaluation.target_audience}
                            </p>
                        )}
                        <div className="mt-5 grid gap-3 lg:grid-cols-3">
                            {review.dimensions &&
                                (['design', 'market', 'audience'] as const).map(
                                    (key) => {
                                        const dimension =
                                            review.dimensions![key];
                                        return (
                                            <div
                                                key={key}
                                                className="min-w-0 space-y-3 rounded-xl border p-4"
                                            >
                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                    <h3 className="font-medium">
                                                        {c.dimensions[key]}
                                                    </h3>
                                                    <span className="text-xs text-muted-foreground">
                                                        {
                                                            c.assessments[
                                                                dimension
                                                                    .assessment
                                                            ]
                                                        }
                                                    </span>
                                                </div>
                                                {dimension.strengths?.[
                                                    locale
                                                ] && (
                                                    <p className="text-sm leading-6">
                                                        {
                                                            dimension.strengths[
                                                                locale
                                                            ]
                                                        }
                                                    </p>
                                                )}
                                                {dimension.concerns?.[
                                                    locale
                                                ] && (
                                                    <p className="text-sm leading-6 text-muted-foreground">
                                                        {
                                                            dimension.concerns[
                                                                locale
                                                            ]
                                                        }
                                                    </p>
                                                )}
                                            </div>
                                        );
                                    },
                                )}
                        </div>
                        {!!review.missing_data?.length && (
                            <div className="mt-4 rounded-xl bg-muted/50 p-4 text-sm">
                                <h3 className="mb-2 font-medium">
                                    {c.missing}
                                </h3>
                                <ul className="list-inside list-disc space-y-1">
                                    {review.missing_data.map((item, i) => (
                                        <li key={i}>{item[locale]}</li>
                                    ))}
                                </ul>
                            </div>
                        )}
                        <p className="mt-4 text-xs leading-6 text-muted-foreground">
                            {c.evidenceLimit}
                        </p>
                        <Recommendations
                            key={review.version}
                            review={review}
                            evaluationId={evaluation.id}
                            disabled={active || !canImprove}
                        />
                        {!canImprove && (
                            <p className="mt-3 text-sm text-warning">
                                {c.notConfigured}
                            </p>
                        )}
                        <details className="mt-5 rounded-xl border p-4 text-sm">
                            <summary className="cursor-pointer font-medium">
                                {c.sources} ({review.sources.length})
                            </summary>
                            <ul className="mt-3 space-y-2">
                                {review.sources.map((source) => (
                                    <li
                                        key={source.id}
                                        className="flex flex-wrap items-center gap-2"
                                    >
                                        <span className="text-xs text-muted-foreground">
                                            {c.sourceKinds[source.kind]}
                                        </span>
                                        {source.url ? (
                                            <a
                                                href={source.url}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="inline-flex items-center gap-1 text-primary underline underline-offset-4"
                                            >
                                                {source.title}
                                                <ExternalLink className="size-3" />
                                            </a>
                                        ) : (
                                            <span>
                                                {source.kind === 'store'
                                                    ? c.sourceKinds.store
                                                    : source.title}
                                            </span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </details>
                    </>
                ) : (
                    <div className="space-y-3 py-2">
                        <h3 className="font-medium">{c.unavailable}</h3>
                        <p className="max-w-3xl text-sm leading-7 text-muted-foreground">
                            {c.unavailableHint}
                        </p>
                        <Button
                            variant="outline"
                            disabled={retry.processing || !canImprove}
                            onClick={() =>
                                retry.post(
                                    `/store/evaluations/${evaluation.id}/retry`,
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <Sparkles className="size-4" />
                            {c.rerun}
                        </Button>
                        {!canImprove && (
                            <p className="text-sm text-muted-foreground">
                                {c.notConfigured}
                            </p>
                        )}
                    </div>
                )}
            </SectionCard>
            {!!improvements.length && (
                <section className="space-y-4" aria-label={c.results}>
                    <h2 className="text-lg font-semibold">{c.results}</h2>
                    {improvements.map((run) => (
                        <ImprovementResult key={run.id} run={run} />
                    ))}
                </section>
            )}
        </div>
    );
}

function Recommendations({
    review,
    evaluationId,
    disabled,
}: {
    review: SpecialistReview;
    evaluationId: number;
    disabled: boolean;
}) {
    const { t, locale } = useI18n();
    const c = t.productReview;
    const form = useForm({
        recommendation_ids: [] as string[],
        request_key: '',
        review_version: review.version,
    });
    const toggle = (id: string) =>
        form.setData(
            'recommendation_ids',
            form.data.recommendation_ids.includes(id)
                ? form.data.recommendation_ids.filter((value) => value !== id)
                : [...form.data.recommendation_ids, id],
        );
    const submit = () => {
        const key = form.data.request_key || crypto.randomUUID();
        form.setData('request_key', key);
        form.transform((data) => ({ ...data, request_key: key }));
        form.post(`/store/evaluations/${evaluationId}/improvements`, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <div className="mt-6 border-t pt-5">
            <h3 className="font-semibold">{c.recommendations}</h3>
            <p className="mt-1 text-sm leading-6 text-muted-foreground">
                {c.selectionHint}
            </p>
            <div className="mt-4 grid gap-3 md:grid-cols-2">
                {review.recommendations.map((r) => (
                    <article
                        key={r.id}
                        className={cn(
                            'min-w-0 space-y-3 rounded-xl border p-4',
                            form.data.recommendation_ids.includes(r.id) &&
                                'border-primary bg-primary/5',
                        )}
                    >
                        <div className="flex flex-wrap items-center justify-between gap-2 text-xs">
                            <span>
                                {r.kind === 'visual' ? c.visual : c.business}
                            </span>
                            <span className="text-muted-foreground">
                                {c.priorities[r.priority]}
                            </span>
                        </div>
                        <label className="flex items-start gap-3 font-medium">
                            {r.kind === 'visual' && (
                                <input
                                    type="checkbox"
                                    className="mt-1 size-4 shrink-0 accent-primary"
                                    checked={form.data.recommendation_ids.includes(
                                        r.id,
                                    )}
                                    disabled={disabled || form.processing}
                                    onChange={() => toggle(r.id)}
                                />
                            )}
                            {r.title[locale]}
                        </label>
                        <p className="text-sm leading-6">
                            {r.instruction[locale]}
                        </p>
                        <p className="text-xs leading-6 text-muted-foreground">
                            <strong>{c.why}: </strong>
                            {r.reason[locale]}
                        </p>
                        <p className="text-xs leading-6 text-muted-foreground">
                            <strong>{c.effect}: </strong>
                            {r.effect[locale]}
                        </p>
                        {!!r.source_ids.length && (
                            <div className="flex flex-wrap gap-2">
                                {r.source_ids.map((id) => {
                                    const source = review.sources.find(
                                        (s) => s.id === id,
                                    );
                                    return (
                                        source && (
                                            <span
                                                key={id}
                                                className="max-w-full rounded bg-muted px-2 py-1 text-xs break-words"
                                            >
                                                {source.kind === 'store'
                                                    ? c.sourceKinds.store
                                                    : source.title}
                                            </span>
                                        )
                                    );
                                })}
                            </div>
                        )}
                    </article>
                ))}
            </div>
            {!review.recommendations.length && (
                <p className="mt-3 text-sm text-muted-foreground">{c.none}</p>
            )}
            {review.recommendations.some((r) => r.kind === 'visual') && (
                <div className="mt-5 flex flex-col items-start gap-3 sm:flex-row sm:items-center">
                    <Button
                        className="h-auto min-h-10 py-2 whitespace-normal"
                        disabled={
                            disabled ||
                            form.processing ||
                            !form.data.recommendation_ids.length
                        }
                        onClick={submit}
                    >
                        {form.processing ? (
                            <Loader2 className="size-4 animate-spin" />
                        ) : (
                            <Wand2 className="size-4" />
                        )}
                        {c.apply}
                    </Button>
                    <span className="text-sm text-muted-foreground">
                        {form.data.recommendation_ids.length} {c.selected}
                    </span>
                </div>
            )}
            {Object.entries(form.errors).map(([key, message]) => (
                <InputError key={key} message={message} className="mt-2" />
            ))}
        </div>
    );
}

function ImprovementResult({ run }: { run: Improvement }) {
    const { t, locale, date } = useI18n();
    const c = t.productReview;
    const active = !['completed', 'failed'].includes(run.status);
    const verification = run.verification;
    const form = useForm({ acknowledge_issues: false });
    const attention = verification?.status !== 'passed';
    const stages = [
        'queued',
        'generating',
        'review_queued',
        'reviewing',
        'completed',
    ];
    const progress =
        run.status === 'failed'
            ? 0
            : [0, 20, 50, 70, 100][stages.indexOf(run.status)];

    return (
        <SectionCard
            title={
                <span className="flex items-center gap-2">
                    {active ? (
                        <Loader2 className="size-4 animate-spin" />
                    ) : run.status === 'completed' ? (
                        <CheckCircle2 className="size-4" />
                    ) : null}
                    {c.stages[run.status]}
                </span>
            }
            description={date(run.created_at)}
        >
            {active && (
                <div
                    role="status"
                    aria-live="polite"
                    className="mb-5 space-y-3"
                >
                    <div
                        role="progressbar"
                        aria-label={c.stages[run.status]}
                        aria-valuemin={0}
                        aria-valuemax={100}
                        aria-valuenow={progress}
                        className="h-2 overflow-hidden rounded-full bg-muted"
                    >
                        <div
                            className="h-full bg-primary transition-all"
                            style={{ width: `${progress}%` }}
                        />
                    </div>
                    <p className="text-xs leading-6 text-muted-foreground">
                        {c.waiting}
                    </p>
                </div>
            )}
            <div className="grid gap-4 sm:grid-cols-2">
                <ReviewImage src={run.before_url} label={c.before} />
                <ReviewImage src={run.after_url} label={c.after} />
            </div>
            <div className="mt-4 flex flex-wrap gap-2">
                {run.recommendations.map((r) => (
                    <span
                        key={r.id}
                        className="rounded-lg bg-muted px-3 py-1 text-xs leading-6"
                    >
                        {r.title[locale]}
                    </span>
                ))}
            </div>
            {verification && verification.status !== 'unavailable' && (
                <div className="mt-5 space-y-3 rounded-xl border p-4">
                    <h3 className="font-semibold">
                        {verification.status === 'passed'
                            ? c.passed
                            : c.attention}
                    </h3>
                    <p className="text-sm leading-7">
                        {verification.summary?.[locale]}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {verification.preserved === true
                            ? c.preserved
                            : c.notPreserved}
                    </p>
                    {verification.checks?.map((check) => (
                        <div key={check.id} className="border-t pt-3 text-sm">
                            <div className="flex flex-wrap justify-between gap-2 font-medium">
                                <span>
                                    {
                                        run.recommendations.find(
                                            (r) => r.id === check.id,
                                        )?.title[locale]
                                    }
                                </span>
                                <span>{c.checks[check.status]}</span>
                            </div>
                            <p className="mt-1 leading-6 text-muted-foreground">
                                {check.detail[locale]}
                            </p>
                        </div>
                    ))}
                    {!!verification.issues?.length && (
                        <ul className="list-inside list-disc space-y-2 text-sm">
                            {verification.issues.map((item, i) => (
                                <li key={i}>{item[locale]}</li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
            {run.status === 'failed' && (
                <div
                    role="alert"
                    className="mt-4 space-y-3 rounded-xl border p-4"
                >
                    <p className="text-sm leading-7">
                        {run.error ? c.errors[run.error] : c.unverified}
                    </p>
                    <Button
                        variant="outline"
                        disabled={form.processing}
                        onClick={() =>
                            form.post(`/store/improvements/${run.id}/retry`, {
                                preserveScroll: true,
                            })
                        }
                    >
                        {run.after_url ? c.retryReview : c.retryGenerate}
                    </Button>
                </div>
            )}
            {run.status === 'completed' && (
                <div className="mt-5 space-y-4">
                    {run.review?.summary && (
                        <p className="text-sm leading-7">
                            {run.review.summary[locale]}
                        </p>
                    )}
                    {run.score !== null && (
                        <p className="text-sm">
                            {c.score}:{' '}
                            <strong className="num">{run.score}/100</strong>
                            <span className="mt-1 block text-xs leading-6 text-muted-foreground">
                                {c.scoreHint}
                            </span>
                        </p>
                    )}
                    {attention && !run.approved_at && (
                        <label className="flex items-start gap-3 text-sm leading-6">
                            <input
                                type="checkbox"
                                checked={form.data.acknowledge_issues}
                                onChange={(ev) =>
                                    form.setData(
                                        'acknowledge_issues',
                                        ev.target.checked,
                                    )
                                }
                                className="mt-1 size-4 shrink-0 accent-primary"
                            />
                            {c.acknowledge}
                        </label>
                    )}
                    <div className="flex flex-wrap gap-2">
                        <Button
                            disabled={
                                !!run.approved_at ||
                                form.processing ||
                                (attention && !form.data.acknowledge_issues)
                            }
                            onClick={() =>
                                form.post(
                                    `/store/improvements/${run.id}/approve`,
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {run.approved_at ? c.approved : c.approve}
                        </Button>
                        {run.result_id && (
                            <>
                                <Button variant="outline" asChild>
                                    <Link
                                        href={`/store/evaluations/${run.result_id}`}
                                    >
                                        {c.fullReview}
                                    </Link>
                                </Button>
                                <Button variant="ghost" asChild>
                                    <Link
                                        href={`/store/evaluations/${run.result_id}#specialist-review`}
                                    >
                                        {c.more}
                                    </Link>
                                </Button>
                            </>
                        )}
                        {run.design_id && (
                            <Button variant="ghost" asChild>
                                <a
                                    href={`/store/designs/${run.design_id}/download`}
                                >
                                    {c.download}
                                </a>
                            </Button>
                        )}
                    </div>
                </div>
            )}
            {Object.entries(form.errors).map(([key, message]) => (
                <InputError key={key} message={message} className="mt-2" />
            ))}
        </SectionCard>
    );
}

function ReviewImage({ src, label }: { src: string | null; label: string }) {
    const { t } = useI18n();
    const [failed, setFailed] = useState(false);
    return (
        <figure className="min-w-0 overflow-hidden rounded-xl border">
            <figcaption className="border-b bg-muted/50 px-4 py-2 text-sm font-medium">
                {label}
            </figcaption>
            <div className="flex aspect-square items-center justify-center bg-muted/20 p-3">
                {src && !failed ? (
                    <a
                        href={src}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="flex h-full w-full items-center justify-center"
                        aria-label={label}
                    >
                        <img
                            src={src}
                            alt={label}
                            onError={() => setFailed(true)}
                            className="h-full w-full object-contain"
                        />
                    </a>
                ) : (
                    <span className="text-sm text-muted-foreground">
                        {failed
                            ? t.productReview.failedImage
                            : t.productReview.noImage}
                    </span>
                )}
            </div>
        </figure>
    );
}

function ImprovementPoll() {
    usePoll(2000, { only: ['improvements', 'versions', 'designs'] });
    return null;
}

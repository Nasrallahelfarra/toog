import { router } from '@inertiajs/react';
import { Loader2, Wand2 } from 'lucide-react';
import { useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/hooks/use-i18n';
import { focusImprovementResults } from './review-image';
import type { EvaluationDetail, Improvement } from './types';

const PRIORITY = { high: 0, medium: 1, low: 2 } as const;

/** The server accepts at most this many recommendations per run. */
const MAX_RECOMMENDATIONS = 6;

/**
 * "Generate improved version" on a reviewed evaluation: applies the
 * specialist's visual recommendations (highest priority first) to the
 * product image and shows the run in the results. When a run is already
 * going it shows that one; when nothing can be generated (no image, no
 * visual recommendation, AI not configured) it opens the review, which
 * says why.
 */
export function GenerateImprovedButton({
    evaluation,
    improvements,
    canImprove,
}: {
    evaluation: EvaluationDetail;
    improvements: Improvement[];
    canImprove: boolean;
}) {
    const { t } = useI18n();
    const s = t.evaluation.show;
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    // One key per attempt, so a double click never starts two runs.
    const requestKey = useRef('');
    const review = evaluation.specialist_review;
    const ids =
        review?.status === 'available'
            ? review.recommendations
                  .filter((r) => r.kind === 'visual')
                  .sort((a, b) => PRIORITY[a.priority] - PRIORITY[b.priority])
                  .slice(0, MAX_RECOMMENDATIONS)
                  .map((r) => r.id)
            : [];
    const active = improvements.some(
        (run) => !['completed', 'failed'].includes(run.status),
    );
    const canGenerate =
        canImprove && ids.length > 0 && Boolean(evaluation.image_url);

    const openReview = () =>
        document
            .getElementById('specialist-review')
            ?.scrollIntoView({ behavior: 'smooth', block: 'start' });

    const generate = () => {
        if (active) {
            focusImprovementResults();
            return;
        }
        if (!canGenerate || !review) {
            openReview();
            return;
        }
        requestKey.current ||= crypto.randomUUID();
        setError(null);
        router.post(
            `/store/evaluations/${evaluation.id}/improvements`,
            {
                recommendation_ids: ids,
                request_key: requestKey.current,
                review_version: review.version,
            },
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
                onSuccess: () => {
                    requestKey.current = '';
                    focusImprovementResults();
                },
                onError: (errors) =>
                    setError(Object.values(errors)[0] ?? s.improvedFailed),
            },
        );
    };

    return (
        <div className="flex flex-col items-start gap-2">
            <Button size="sm" variant="soft" onClick={generate} disabled={busy}>
                {busy || active ? (
                    <Loader2 className="size-3.5 animate-spin" />
                ) : (
                    <Wand2 className="size-3.5" />
                )}
                {active ? s.improvedRunning : s.generateImproved}
            </Button>
            <p
                aria-live="polite"
                className={
                    error
                        ? 'text-xs text-danger'
                        : 'text-xs leading-relaxed text-muted-foreground'
                }
            >
                {error ??
                    (canGenerate ? s.improvedHint : s.improvedUnavailable)}
            </p>
        </div>
    );
}

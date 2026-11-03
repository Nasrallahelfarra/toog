import { Link, router, useForm, usePoll } from '@inertiajs/react';
import {
    AlertCircle,
    ArrowUpRight,
    CheckCircle2,
    Clock3,
    Loader2,
    Package,
    Sparkles,
    Turtle,
    WandSparkles,
} from 'lucide-react';
import { AnimatePresence, motion, useReducedMotion } from 'motion/react';
import { useEffect, useState } from 'react';
import type { SpecialistReview } from '@/components/evaluation/types';
import { PerformanceBadge } from '@/components/mojo/decision-badge';
import { EmptyState } from '@/components/mojo/empty-state';
import { SectionCard } from '@/components/mojo/section-card';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

type Recovery = {
    id: number;
    status: 'pending' | 'processing' | 'completed' | 'failed';
    review: SpecialistReview | null;
    error: string | null;
    created_at: string;
    started_at: string | null;
    phase: 'queued' | 'context' | 'image' | 'model' | 'saving';
    queue: { found: boolean; ahead: number; busy: string | null } | null;
};

export type StagnantProduct = {
    id: number;
    name: string;
    sku: string | null;
    category: string | null;
    image_url: string | null;
    price: string | null;
    stock: number;
    units_sold: number;
    turnover_days: number | null;
    performance_score: number | null;
    performance: 'stagnant' | 'at_risk';
    classification_reason:
        | 'no_sales_90'
        | 'slow_turnover'
        | 'low_performance'
        | 'early_no_sales';
    recovery: Recovery | null;
};

export type StagnantSummary = {
    total: number;
    stagnant: number;
    at_risk: number;
    needs_reclassification: boolean;
    stock: number;
    stock_value: number;
    unclassified: number;
};
export type StagnantDashboard = StagnantSummary & {
    products: StagnantProduct[];
};

function RecommendationPoll({
    dashboard,
    onNetwork,
}: {
    dashboard: boolean;
    onNetwork: (failed: boolean) => void;
}) {
    usePoll(3000, {
        only: [dashboard ? 'stagnant' : 'products'],
        onSuccess: () => onNetwork(false),
        onError: () => onNetwork(true),
        onHttpException: () => {
            onNetwork(true);
            return false;
        },
        onNetworkError: () => {
            onNetwork(true);
            return false;
        },
    });
    return null;
}

function ProductImage({
    product,
    className,
}: {
    product: StagnantProduct;
    className?: string;
}) {
    const [failed, setFailed] = useState(false);
    return (
        <div
            className={cn(
                'flex shrink-0 items-center justify-center overflow-hidden rounded-xl bg-muted',
                className,
            )}
        >
            {product.image_url && !failed ? (
                <img
                    src={product.image_url}
                    alt={product.name}
                    loading="lazy"
                    onError={() => setFailed(true)}
                    className="h-full w-full object-contain"
                />
            ) : (
                <Package className="size-8 text-muted-foreground/50" />
            )}
        </div>
    );
}

export function StagnantDashboardCard({ data }: { data: StagnantDashboard }) {
    const { t, tr, number } = useI18n();
    return (
        <SectionCard
            title={t.stagnant.title}
            description={tr(t.stagnant.dashboardHint, {
                stagnant: number(data.stagnant),
                atRisk: number(data.at_risk),
            })}
            icon={Turtle}
            action={
                <Button variant="ghost" size="sm" asChild>
                    <Link href="/store/stagnant">
                        {t.stagnant.all}
                        <ArrowUpRight className="size-4 rtl:-scale-x-100" />
                    </Link>
                </Button>
            }
        >
            {data.needs_reclassification && (
                <p className="mb-4 rounded-xl bg-warning/10 p-3 text-sm leading-7 text-warning">
                    {t.stagnant.refreshNeeded}
                </p>
            )}
            {data.unclassified > 0 && (
                <p className="mb-4 rounded-xl bg-muted/50 p-3 text-sm leading-7 text-muted-foreground">
                    {tr(t.stagnant.unclassified, {
                        count: number(data.unclassified),
                    })}
                </p>
            )}
            <StagnantProductGrid
                products={data.products}
                dashboard
                stale={data.needs_reclassification}
            />
        </SectionCard>
    );
}

export function StagnantProductGrid({
    products,
    dashboard = false,
    filtered = false,
    stale = false,
}: {
    products: StagnantProduct[];
    dashboard?: boolean;
    filtered?: boolean;
    stale?: boolean;
}) {
    const { t, number, money, isRtl } = useI18n();
    const s = t.stagnant;
    const reduced = useReducedMotion();
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const selected = products.find((p) => p.id === selectedId);
    const request = useForm({});
    const [requestError, setRequestError] = useState(false);
    const [networkError, setNetworkError] = useState(false);
    const refresh = () =>
        router.reload({
            only: [dashboard ? 'stagnant' : 'products'],
            onSuccess: () => {
                setNetworkError(false);
                setRequestError(false);
            },
            onError: () => setNetworkError(true),
            onHttpException: () => {
                setNetworkError(true);
                return false;
            },
            onNetworkError: () => {
                setNetworkError(true);
                return false;
            },
        });
    const active = products.some(
        (p) =>
            p.recovery?.status === 'pending' ||
            p.recovery?.status === 'processing',
    );

    const recommend = (product: StagnantProduct, regenerate = false) => {
        setSelectedId(product.id);
        setRequestError(false);
        if (
            product.recovery &&
            product.recovery.status !== 'failed' &&
            !regenerate
        )
            return;
        request.transform(() =>
            regenerate && product.recovery
                ? { replace_recommendation_id: product.recovery.id }
                : {},
        );
        request.post(`/store/stagnant/${product.id}/recommendation`, {
            preserveScroll: true,
            onError: () => setRequestError(true),
            onHttpException: () => {
                setRequestError(true);
                return false;
            },
            onNetworkError: () => {
                setRequestError(true);
                return false;
            },
        });
    };

    return (
        <>
            {active && (
                <RecommendationPoll
                    dashboard={dashboard}
                    onNetwork={setNetworkError}
                />
            )}
            {products.length ? (
                <div
                    className={cn(
                        'grid gap-4 sm:grid-cols-2',
                        dashboard ? '2xl:grid-cols-4' : 'xl:grid-cols-3',
                    )}
                >
                    {products.map((product, index) => {
                        const status = product.recovery?.status;
                        const pending =
                            status === 'pending' || status === 'processing';
                        return (
                            <motion.article
                                key={product.id}
                                initial={
                                    reduced ? false : { opacity: 0, y: 12 }
                                }
                                animate={{ opacity: 1, y: 0 }}
                                transition={{
                                    duration: 0.3,
                                    delay: reduced
                                        ? 0
                                        : Math.min(index * 0.04, 0.3),
                                }}
                                className="group flex min-w-0 flex-col rounded-2xl border bg-card p-4 transition-shadow hover:shadow-md"
                            >
                                <div className="relative mb-4">
                                    <ProductImage
                                        key={product.image_url}
                                        product={product}
                                        className={
                                            dashboard
                                                ? 'h-36 w-full'
                                                : 'h-48 w-full'
                                        }
                                    />
                                    <span className="absolute start-2 top-2 rounded-full bg-background/95">
                                        <PerformanceBadge
                                            value={product.performance}
                                        />
                                    </span>
                                </div>
                                <div className="mb-3 flex-1 space-y-1">
                                    <h3 className="line-clamp-2 leading-6 font-semibold">
                                        {product.name}
                                    </h3>
                                    <p className="text-xs text-muted-foreground">
                                        {product.category ?? '—'}
                                        {product.sku && (
                                            <span className="ms-2" dir="ltr">
                                                {product.sku}
                                            </span>
                                        )}
                                    </p>
                                    <p className="pt-1 text-sm font-medium">
                                        {money(product.price)}
                                    </p>
                                </div>
                                <dl className="mb-4 grid grid-cols-2 gap-2 rounded-xl bg-muted/50 p-3 text-xs">
                                    <div>
                                        <dt className="text-muted-foreground">
                                            {s.stock}
                                        </dt>
                                        <dd className="mt-1 text-base font-semibold">
                                            {number(product.stock)}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground">
                                            {s.units}
                                        </dt>
                                        <dd className="mt-1 text-base font-semibold">
                                            {number(product.units_sold)}
                                        </dd>
                                    </div>
                                </dl>
                                {!stale && (
                                    <p className="mb-4 text-xs leading-6 text-muted-foreground">
                                        {
                                            s.reasons[
                                                product.classification_reason
                                            ]
                                        }
                                    </p>
                                )}
                                <Button
                                    variant={
                                        status === 'completed'
                                            ? 'outline'
                                            : 'default'
                                    }
                                    className="w-full"
                                    disabled={request.processing}
                                    onClick={() => recommend(product)}
                                >
                                    {pending ? (
                                        <Loader2 className="size-4 motion-safe:animate-spin" />
                                    ) : (
                                        <Sparkles className="size-4" />
                                    )}
                                    {pending
                                        ? s.pending
                                        : status === 'completed'
                                          ? s.view
                                          : status === 'failed'
                                            ? s.retry
                                            : s.request}
                                </Button>
                            </motion.article>
                        );
                    })}
                </div>
            ) : (
                <EmptyState
                    icon={Turtle}
                    title={filtered ? s.noResults : s.empty}
                    description={filtered ? undefined : s.emptyHint}
                    action={
                        <Button asChild variant="outline">
                            <Link
                                href={
                                    filtered
                                        ? '/store/stagnant'
                                        : '/store/performance'
                                }
                            >
                                {filtered ? s.reset : s.performance}
                            </Link>
                        </Button>
                    }
                />
            )}
            <Dialog
                open={!!selected}
                onOpenChange={(open) => {
                    if (!open) setSelectedId(null);
                }}
            >
                <DialogContent
                    className="max-h-[90dvh] overflow-y-auto rounded-2xl p-0 sm:max-w-3xl"
                    dir={isRtl ? 'rtl' : 'ltr'}
                >
                    <DialogHeader className="border-b bg-primary/5 p-6 pe-12 text-start sm:text-start">
                        <div className="mb-2 flex items-center gap-2 text-xs font-medium text-primary">
                            <Sparkles className="size-4" />
                            {s.expert}
                        </div>
                        <DialogTitle className="leading-7">
                            {selected?.name}
                        </DialogTitle>
                        <DialogDescription>{s.expertHint}</DialogDescription>
                    </DialogHeader>
                    {selected && (
                        <div className="space-y-5 p-6 pt-0">
                            <div className="flex items-center gap-4 rounded-xl border p-3">
                                <ProductImage
                                    key={selected.image_url}
                                    product={selected}
                                    className="size-20"
                                />
                                <dl className="grid flex-1 grid-cols-2 gap-3 text-xs sm:grid-cols-3">
                                    <div>
                                        <dt className="text-muted-foreground">
                                            {s.stock}
                                        </dt>
                                        <dd className="mt-1 font-semibold">
                                            {number(selected.stock)}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground">
                                            {s.units}
                                        </dt>
                                        <dd className="mt-1 font-semibold">
                                            {number(selected.units_sold)}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground">
                                            {s.turnover}
                                        </dt>
                                        <dd className="mt-1 font-semibold">
                                            {number(selected.turnover_days)}
                                        </dd>
                                    </div>
                                </dl>
                            </div>
                            {!stale && (
                                <p className="rounded-xl bg-muted/50 p-3 text-sm leading-7">
                                    {s.reasons[selected.classification_reason]}
                                </p>
                            )}
                            <AnimatePresence mode="wait" initial={false}>
                                <motion.div
                                    key={
                                        requestError
                                            ? 'error'
                                            : (selected.recovery?.status ??
                                              'pending')
                                    }
                                    initial={
                                        reduced ? false : { opacity: 0, y: 8 }
                                    }
                                    animate={{ opacity: 1, y: 0 }}
                                    exit={{ opacity: 0 }}
                                    transition={{
                                        duration: reduced ? 0 : 0.25,
                                    }}
                                >
                                    {requestError ||
                                    selected.recovery?.status === 'failed' ? (
                                        <div
                                            role="alert"
                                            className="space-y-3 rounded-xl border border-danger/20 bg-danger/5 p-5"
                                        >
                                            <h3 className="flex items-center gap-2 font-semibold">
                                                <AlertCircle className="size-5 text-danger" />
                                                {s.failed}
                                            </h3>
                                            <p className="text-sm leading-7 text-muted-foreground">
                                                {s.errors[
                                                    (requestError
                                                        ? 'request_failed'
                                                        : (selected.recovery
                                                              ?.error ??
                                                          'review_failed')) as keyof typeof s.errors
                                                ] ?? s.errors.review_failed}
                                            </p>
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    requestError
                                                        ? refresh()
                                                        : recommend(selected)
                                                }
                                                disabled={request.processing}
                                            >
                                                {requestError
                                                    ? s.refreshStatus
                                                    : s.retry}
                                            </Button>
                                        </div>
                                    ) : selected.recovery?.status ===
                                          'completed' &&
                                      selected.recovery.review?.status ===
                                          'available' ? (
                                        <RecoveryResult
                                            key={selected.recovery.id}
                                            recovery={selected.recovery}
                                            regenerate={() =>
                                                recommend(selected, true)
                                            }
                                            requesting={request.processing}
                                        />
                                    ) : (
                                        <>
                                            <ReviewLoading
                                                recovery={selected.recovery}
                                                networkError={networkError}
                                                refresh={refresh}
                                                sending={request.processing}
                                            />
                                            {!selected.recovery &&
                                                !request.processing &&
                                                !networkError && (
                                                    <Button
                                                        className="w-full"
                                                        onClick={() =>
                                                            recommend(selected)
                                                        }
                                                    >
                                                        {s.request}
                                                    </Button>
                                                )}
                                        </>
                                    )}
                                </motion.div>
                            </AnimatePresence>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

function ReviewLoading({
    recovery,
    networkError,
    refresh,
    sending,
}: {
    recovery: Recovery | null;
    networkError: boolean;
    refresh: () => void;
    sending: boolean;
}) {
    const { t, tr, number } = useI18n();
    const s = t.stagnant;
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 1000);
        return () => window.clearInterval(timer);
    }, []);
    const phase = recovery?.phase ?? 'queued';
    const queued = recovery?.status === 'pending';
    const elapsed = Math.max(
        0,
        Math.floor(
            (now -
                Date.parse(
                    recovery?.started_at ??
                        recovery?.created_at ??
                        new Date(now).toISOString(),
                )) /
                1000,
        ),
    );
    const phases = ['context', 'image', 'model', 'saving'] as const;
    const current = phases.indexOf(phase as (typeof phases)[number]);
    return (
        <div
            role="status"
            aria-live="polite"
            className="space-y-5 py-5 text-center"
        >
            <div className="relative mx-auto flex size-20 items-center justify-center rounded-2xl bg-primary/10">
                <span className="absolute inset-0 rounded-2xl border border-primary/25 motion-safe:animate-ping" />
                <Sparkles className="size-8 text-primary motion-safe:animate-pulse" />
            </div>
            <div>
                <h3 className="text-lg font-semibold">
                    {!recovery
                        ? sending
                            ? s.sending
                            : s.checkStatus
                        : s.phases[phase]}
                </h3>
                <p className="mt-2 text-sm leading-7 text-muted-foreground">
                    {!recovery ? s.sendingHint : s.phaseHints[phase]}
                </p>
                {recovery && (
                    <p
                        className="mt-2 text-xs text-muted-foreground"
                        aria-live="off"
                    >
                        {tr(queued ? s.queueElapsed : s.elapsed, {
                            minutes: number(Math.floor(elapsed / 60)),
                            seconds: number(elapsed % 60),
                        })}
                    </p>
                )}
                {queued && recovery.queue && (
                    <p className="mt-2 text-xs text-muted-foreground">
                        {recovery.queue.ahead > 0
                            ? tr(s.queueAhead, {
                                  count: number(recovery.queue.ahead),
                              })
                            : s.queueWaiting}
                        {recovery.queue.busy && ` ${s.queueBusy}`}
                    </p>
                )}
            </div>
            <ol className="grid gap-2 text-xs sm:grid-cols-2">
                {phases.map((step, index) => (
                    <li
                        key={step}
                        aria-current={current === index ? 'step' : undefined}
                        className={cn(
                            'flex items-center justify-center gap-2 rounded-xl p-3',
                            current === index
                                ? 'bg-primary/10 text-primary ring-1 ring-primary/25'
                                : 'bg-muted/60 text-muted-foreground',
                        )}
                    >
                        {current > index ? (
                            <CheckCircle2 className="size-3.5 text-success" />
                        ) : current === index ? (
                            <Loader2 className="size-3.5 motion-safe:animate-spin" />
                        ) : (
                            <Clock3 className="size-3.5" />
                        )}
                        {s.phases[step]}
                    </li>
                ))}
            </ol>
            <div aria-hidden className="space-y-2">
                {[100, 85, 65].map((width) => (
                    <div
                        key={width}
                        style={{ width: `${width}%` }}
                        className="h-3 rounded-full bg-muted motion-safe:animate-pulse"
                    />
                ))}
            </div>
            <p className="text-xs leading-6 text-muted-foreground">
                {s.waiting}
            </p>
            {queued && elapsed >= 60 && (
                <p className="text-xs leading-6 text-muted-foreground">
                    {s.queueSlow}
                </p>
            )}
            {networkError && (
                <p role="alert" className="text-sm text-danger">
                    {s.network}
                </p>
            )}
            <Button
                variant="outline"
                size="sm"
                onClick={refresh}
                disabled={sending}
            >
                {s.refreshStatus}
            </Button>
        </div>
    );
}

function RecoveryResult({
    recovery,
    regenerate,
    requesting,
}: {
    recovery: Recovery;
    regenerate: () => void;
    requesting: boolean;
}) {
    const { t, locale, dateTime } = useI18n();
    const reduced = useReducedMotion();
    const review = recovery.review!;
    const c = t.productReview;
    const s = t.stagnant;
    const [selectedAction, setSelectedAction] = useState<string | null>(null);
    const visualOptions = review.recommendations.filter(
        (item) => item.kind === 'visual',
    );
    return (
        <div className="space-y-5">
            <div
                role="status"
                className="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground"
            >
                <span className="flex items-center gap-1.5 text-success">
                    <CheckCircle2 className="size-4" />
                    {s.saved}
                </span>
                <span>{dateTime(recovery.created_at)}</span>
            </div>
            <div className="rounded-2xl border border-primary/15 bg-primary/5 p-5">
                <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h3 className="font-semibold">
                        {review.verdict && c.verdicts[review.verdict]}
                    </h3>
                    {review.confidence && (
                        <span className="rounded-full bg-background px-2.5 py-1 text-xs">
                            {c.confidence}: {c.levels[review.confidence]}
                        </span>
                    )}
                </div>
                <p className="text-sm leading-8 whitespace-pre-line">
                    {review.summary?.[locale]}
                </p>
            </div>
            {visualOptions.length > 0 && (
                <div className="rounded-xl border border-primary/25 bg-primary/5 p-4">
                    <h3 className="flex items-center gap-2 font-semibold">
                        <WandSparkles className="size-4 text-primary" />
                        {s.visualOptions}
                    </h3>
                    <p className="mt-2 text-sm leading-7 text-muted-foreground">
                        {s.chooseHint}
                    </p>
                </div>
            )}
            <div className="space-y-3">
                {review.recommendations.map((item, index) => (
                    <motion.section
                        key={item.id}
                        initial={reduced ? false : { opacity: 0, y: 12 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{
                            duration: 0.3,
                            delay: reduced ? 0 : index * 0.08,
                        }}
                        className={cn(
                            'overflow-hidden rounded-2xl border transition-colors',
                            selectedAction === item.id &&
                                'border-primary bg-primary/5 ring-1 ring-primary/30',
                        )}
                    >
                        <div className="space-y-3 p-4 sm:p-5">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="flex size-7 items-center justify-center rounded-lg bg-primary/10 text-xs font-semibold text-primary">
                                    {index + 1}
                                </span>
                                <h4 className="flex-1 text-sm font-semibold">
                                    {item.title[locale]}
                                </h4>
                                <span
                                    className={cn(
                                        'rounded-full px-2 py-1 text-[11px]',
                                        item.priority === 'high'
                                            ? 'bg-danger/10 text-danger'
                                            : 'bg-muted text-muted-foreground',
                                    )}
                                >
                                    {c.priorities[item.priority]}
                                </span>
                            </div>
                            <p className="text-sm leading-7">
                                <strong>
                                    {item.kind === 'visual'
                                        ? s.visualImpact
                                        : c.why}
                                    :{' '}
                                </strong>
                                {item.reason[locale]}
                            </p>
                            <p className="text-sm leading-7 text-muted-foreground">
                                <strong>
                                    {item.kind === 'visual'
                                        ? s.persuasion
                                        : c.effect}
                                    :{' '}
                                </strong>
                                {item.effect[locale]}
                            </p>
                            <div className="rounded-xl bg-muted/50 p-3 text-sm leading-7">
                                <span className="mb-1 block text-xs font-medium text-muted-foreground">
                                    {item.kind === 'visual'
                                        ? c.visual
                                        : s.businessStep}
                                </span>
                                {item.instruction[locale]}
                            </div>
                        </div>
                        {item.kind === 'visual' && (
                            <div className="space-y-2 border-t bg-primary/5 p-4">
                                <Button
                                    type="button"
                                    variant={
                                        selectedAction === item.id
                                            ? 'default'
                                            : 'outline'
                                    }
                                    aria-pressed={selectedAction === item.id}
                                    onClick={() => setSelectedAction(item.id)}
                                    className="w-full sm:w-auto"
                                >
                                    {selectedAction === item.id && (
                                        <CheckCircle2 className="size-4" />
                                    )}
                                    {selectedAction === item.id
                                        ? s.selected
                                        : s.choose}
                                    <span className="sr-only">
                                        : {item.title[locale]}
                                    </span>
                                </Button>
                            </div>
                        )}
                    </motion.section>
                ))}
                {!review.recommendations.length && (
                    <p className="text-sm text-muted-foreground">
                        {s.noActions}
                    </p>
                )}
            </div>
            {visualOptions.length > 0 && (
                <div className="sticky bottom-0 space-y-2 rounded-xl border bg-background/95 p-4 shadow-lg backdrop-blur">
                    <p aria-live="polite" className="text-sm font-medium">
                        {selectedAction
                            ? `${s.selected}: ${visualOptions.find((item) => item.id === selectedAction)?.title[locale]}`
                            : s.chooseFirst}
                    </p>
                    {selectedAction ? (
                        <Button asChild className="w-full">
                            <Link
                                href={`/store/studio?recovery=${recovery.id}&action=${encodeURIComponent(selectedAction)}`}
                            >
                                <WandSparkles className="size-4" />
                                {s.apply}
                            </Link>
                        </Button>
                    ) : (
                        <Button disabled className="w-full">
                            <WandSparkles className="size-4" />
                            {s.apply}
                        </Button>
                    )}
                    <p className="text-xs leading-6 text-muted-foreground">
                        {s.applyHint}
                    </p>
                </div>
            )}
            {!!review.missing_data?.length && (
                <details className="rounded-xl border p-4 text-sm">
                    <summary className="cursor-pointer font-medium">
                        {c.missing}
                    </summary>
                    <ul className="mt-3 list-inside list-disc space-y-2 text-muted-foreground">
                        {review.missing_data.map((item, i) => (
                            <li key={i}>{item[locale]}</li>
                        ))}
                    </ul>
                </details>
            )}
            {!!review.sources.length && (
                <details className="rounded-xl border p-4 text-sm">
                    <summary className="cursor-pointer font-medium">
                        {c.sources} ({review.sources.length})
                    </summary>
                    <ul className="mt-3 space-y-2 text-muted-foreground">
                        {review.sources.map((source) => (
                            <li key={source.id}>
                                {source.url ? (
                                    <a
                                        href={source.url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="text-primary underline"
                                    >
                                        {source.title}
                                    </a>
                                ) : source.kind === 'store' ? (
                                    c.sourceKinds.store
                                ) : (
                                    source.title
                                )}
                            </li>
                        ))}
                    </ul>
                </details>
            )}
            <p className="text-xs leading-6 text-muted-foreground">
                {c.evidenceLimit}
            </p>
            <div className="space-y-2 border-t pt-4">
                <Button
                    variant="outline"
                    onClick={regenerate}
                    disabled={requesting}
                >
                    <Sparkles className="size-4" />
                    {s.regenerate}
                </Button>
                <p className="text-xs leading-6 text-muted-foreground">
                    {s.regenerateHint}
                </p>
            </div>
        </div>
    );
}

import { Link, usePoll } from '@inertiajs/react';
import {
    AlertCircle,
    ArrowUpRight,
    CheckCircle2,
    Clock3,
    Gem,
    Loader2,
    Package,
    Palette,
    Scissors,
    Shapes,
    Sparkles,
    Turtle,
    WandSparkles,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { AnimatePresence, motion, useReducedMotion } from 'motion/react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import type { SpecialistReview } from '@/components/evaluation/types';
import { ColorChips } from '@/components/mojo/color-chips';
import { PerformanceBadge } from '@/components/mojo/decision-badge';
import { EmptyState } from '@/components/mojo/empty-state';
import { SectionCard } from '@/components/mojo/section-card';
import { MarketingPlanCard } from '@/components/store/marketing-plan';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/hooks/use-i18n';
import { useVocabulary } from '@/hooks/use-vocabulary';
import { cn } from '@/lib/utils';

export type Recovery = {
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

/** Keeps the cards' recommendation status fresh while one is being prepared. */
function RecommendationPoll({ dashboard }: { dashboard: boolean }) {
    usePoll(3000, {
        only: [dashboard ? 'stagnant' : 'products'],
        onHttpException: () => false,
        onNetworkError: () => false,
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
                    className="h-full w-full object-contain transition-transform duration-500 group-hover:scale-[1.03] motion-reduce:transition-none motion-reduce:group-hover:scale-100"
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

/**
 * Slow movers as cards. A card opens the product page, where its stats live
 * and where the expert recommendation is requested and read.
 */
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
    const { t, number, money } = useI18n();
    const s = t.stagnant;
    const reduced = useReducedMotion();
    const active = products.some(
        (p) =>
            p.recovery?.status === 'pending' ||
            p.recovery?.status === 'processing',
    );

    return (
        <>
            {active && <RecommendationPoll dashboard={dashboard} />}
            {products.length ? (
                <div
                    className={cn(
                        'grid gap-4 sm:grid-cols-2',
                        dashboard ? '2xl:grid-cols-4' : 'xl:grid-cols-3',
                    )}
                >
                    {products.map((product, index) => (
                        <motion.article
                            key={product.id}
                            initial={reduced ? false : { opacity: 0, y: 12 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{
                                duration: 0.3,
                                delay: reduced
                                    ? 0
                                    : Math.min(index * 0.04, 0.3),
                            }}
                            className="group relative flex min-w-0 flex-col rounded-2xl border bg-card p-4 transition-[box-shadow,border-color] focus-within:ring-2 focus-within:ring-ring/60 hover:border-primary/40 hover:shadow-md"
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
                                    {/* Stretched link: the whole card opens the product. */}
                                    <Link
                                        href={`/store/products/${product.id}?from=stagnant`}
                                        className="outline-none group-hover:text-primary after:absolute after:inset-0 after:rounded-2xl"
                                    >
                                        {product.name}
                                    </Link>
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
                                    {s.reasons[product.classification_reason]}
                                </p>
                            )}
                            <div className="mt-auto flex items-center justify-between gap-2 border-t pt-3 text-xs">
                                <RecoveryStatus recovery={product.recovery} />
                                <span
                                    aria-hidden
                                    className="inline-flex shrink-0 items-center gap-1 font-medium text-primary"
                                >
                                    {s.openProduct}
                                    <ArrowUpRight className="size-3.5 rtl:-scale-x-100" />
                                </span>
                            </div>
                        </motion.article>
                    ))}
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
        </>
    );
}

/** Where the product's expert recommendation stands, as one quiet line. */
function RecoveryStatus({ recovery }: { recovery: Recovery | null }) {
    const { t } = useI18n();
    const s = t.stagnant.recoveryStatus;
    const status = recovery?.status;
    const working = status === 'pending' || status === 'processing';
    const view: { icon: LucideIcon; label: string; tone: string } = working
        ? { icon: Loader2, label: s.pending, tone: 'text-primary' }
        : status === 'completed'
          ? { icon: CheckCircle2, label: s.completed, tone: 'text-success' }
          : status === 'failed'
            ? { icon: AlertCircle, label: s.failed, tone: 'text-danger' }
            : {
                  icon: Sparkles,
                  label: s.none,
                  tone: 'text-muted-foreground',
              };
    return (
        <span
            className={cn(
                'inline-flex min-w-0 items-center gap-1.5',
                view.tone,
            )}
        >
            <view.icon
                className={cn(
                    'size-3.5 shrink-0',
                    working && 'motion-safe:animate-spin',
                )}
            />
            <span className="truncate">{view.label}</span>
        </span>
    );
}

/**
 * The expert recommendation for one product in every state: the request
 * button, queued/working progress, failure with retry, and the result.
 * Rendered on the product page, the one place a recommendation is requested.
 */
export function RecommendationBody({
    recovery,
    requestError,
    networkError,
    sending,
    onRequest,
    onRegenerate,
    onRefresh,
}: {
    recovery: Recovery | null;
    requestError: boolean;
    networkError: boolean;
    sending: boolean;
    onRequest: () => void;
    onRegenerate: () => void;
    onRefresh: () => void;
}) {
    const { t } = useI18n();
    const s = t.stagnant;
    const reduced = useReducedMotion();

    return (
        <AnimatePresence mode="wait" initial={false}>
            <motion.div
                key={requestError ? 'error' : (recovery?.status ?? 'pending')}
                initial={reduced ? false : { opacity: 0, y: 8 }}
                animate={{ opacity: 1, y: 0 }}
                exit={{ opacity: 0 }}
                transition={{
                    duration: reduced ? 0 : 0.25,
                }}
            >
                {requestError || recovery?.status === 'failed' ? (
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
                                    : (recovery?.error ??
                                      'review_failed')) as keyof typeof s.errors
                            ] ?? s.errors.review_failed}
                        </p>
                        <Button
                            variant="outline"
                            onClick={() =>
                                requestError ? onRefresh() : onRequest()
                            }
                            disabled={sending}
                        >
                            {requestError ? s.refreshStatus : s.retry}
                        </Button>
                    </div>
                ) : recovery?.status === 'completed' &&
                  recovery.review?.status === 'available' ? (
                    <RecoveryResult
                        key={recovery.id}
                        recovery={recovery}
                        regenerate={() => onRegenerate()}
                        requesting={sending}
                    />
                ) : (
                    <>
                        <ReviewLoading
                            recovery={recovery}
                            networkError={networkError}
                            refresh={onRefresh}
                            sending={sending}
                        />
                        {!recovery && !sending && !networkError && (
                            <Button
                                className="w-full"
                                onClick={() => onRequest()}
                            >
                                {s.request}
                            </Button>
                        )}
                    </>
                )}
            </motion.div>
        </AnimatePresence>
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

type Recommendation = SpecialistReview['recommendations'][number];

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
    const review = recovery.review!;
    const c = t.productReview;
    const s = t.stagnant;
    const designs = review.recommendations.filter(
        (item) => item.kind === 'visual',
    );
    const actions = review.recommendations.filter(
        (item) => item.kind !== 'visual',
    );
    return (
        <div className="space-y-6">
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
            {designs.length > 0 && (
                <section aria-labelledby="new-designs" className="space-y-4">
                    <div>
                        <h3
                            id="new-designs"
                            className="flex items-center gap-2 text-base font-semibold"
                        >
                            <WandSparkles className="size-4 text-primary" />
                            {s.designs}
                        </h3>
                        <p className="mt-1 text-sm leading-7 text-muted-foreground">
                            {s.designsHint}
                        </p>
                    </div>
                    <div className="grid gap-4 lg:grid-cols-3">
                        {designs.map((item, index) => (
                            <DesignCard
                                key={item.id}
                                item={item}
                                index={index}
                                recoveryId={recovery.id}
                            />
                        ))}
                    </div>
                    <p className="text-xs leading-6 text-muted-foreground">
                        {s.applyHint}
                    </p>
                </section>
            )}
            {review.marketing_plan && (
                <MarketingPlanCard plan={review.marketing_plan} />
            )}
            {actions.length > 0 && (
                <section aria-labelledby="business-steps" className="space-y-3">
                    <h3 id="business-steps" className="font-semibold">
                        {s.businessTitle}
                    </h3>
                    {actions.map((item, index) => (
                        <BusinessAction
                            key={item.id}
                            item={item}
                            index={index}
                        />
                    ))}
                </section>
            )}
            {!review.recommendations.length && (
                <p className="text-sm text-muted-foreground">{s.noActions}</p>
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

/**
 * One suggested new design of the product: its cut, style, colours and
 * details, why it may sell better, and a direct way to draw it in the studio.
 */
function DesignCard({
    item,
    index,
    recoveryId,
}: {
    item: Recommendation;
    index: number;
    recoveryId: number;
}) {
    const { t, tr, locale, number } = useI18n();
    const { label } = useVocabulary();
    const reduced = useReducedMotion();
    const s = t.stagnant;
    const spec = item.design;
    const looks = [
        spec?.style && label('styles', spec.style),
        spec?.pattern && label('patterns', spec.pattern),
    ].filter((value): value is string => !!value);
    return (
        <motion.article
            initial={reduced ? false : { opacity: 0, y: 12 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{
                duration: 0.3,
                delay: reduced ? 0 : index * 0.08,
            }}
            className="flex min-w-0 flex-col overflow-hidden rounded-2xl border border-primary/20 bg-card transition-shadow hover:shadow-md"
        >
            <div className="flex items-center gap-2 border-b bg-primary/5 px-4 py-3 text-xs font-medium text-primary">
                <WandSparkles className="size-3.5" />
                {tr(s.designLabel, { n: number(index + 1) })}
            </div>
            <div className="flex-1 space-y-3 p-4">
                <h4 className="leading-7 font-semibold">
                    {item.title[locale]}
                </h4>
                {spec ? (
                    <dl className="divide-y divide-primary/10 rounded-xl bg-primary/5 text-sm ring-1 ring-primary/10">
                        <SpecRow icon={Scissors} label={s.spec.cut}>
                            {spec.cut[locale]}
                        </SpecRow>
                        {looks.length > 0 && (
                            <SpecRow icon={Shapes} label={s.spec.look}>
                                <span className="flex flex-wrap gap-1.5">
                                    {looks.map((look) => (
                                        <span
                                            key={look}
                                            className="rounded-full bg-background px-2.5 py-0.5 text-xs"
                                        >
                                            {look}
                                        </span>
                                    ))}
                                </span>
                            </SpecRow>
                        )}
                        {spec.colors.length > 0 && (
                            <SpecRow icon={Palette} label={s.spec.colors}>
                                <ColorChips colors={spec.colors} />
                            </SpecRow>
                        )}
                        <SpecRow icon={Gem} label={s.spec.details}>
                            {spec.details[locale]}
                        </SpecRow>
                    </dl>
                ) : (
                    <div className="rounded-xl bg-primary/5 p-3 text-sm leading-7 ring-1 ring-primary/10">
                        <span className="mb-1 block text-xs font-medium text-primary">
                            {s.designChange}
                        </span>
                        {item.instruction[locale]}
                    </div>
                )}
                <p className="text-sm leading-7">
                    <strong>{s.designWhy}: </strong>
                    {item.reason[locale]}
                </p>
                <p className="text-sm leading-7 text-muted-foreground">
                    <strong>{s.designAppeal}: </strong>
                    {item.effect[locale]}
                </p>
            </div>
            <div className="border-t bg-primary/5 p-4">
                <Button asChild className="w-full">
                    <Link
                        href={`/store/studio?recovery=${recoveryId}&action=${encodeURIComponent(item.id)}`}
                    >
                        <WandSparkles className="size-4" />
                        {s.tryDesign}
                        <span className="sr-only">: {item.title[locale]}</span>
                    </Link>
                </Button>
            </div>
        </motion.article>
    );
}

function SpecRow({
    icon: Icon,
    label,
    children,
}: {
    icon: LucideIcon;
    label: string;
    children: ReactNode;
}) {
    return (
        <div className="space-y-1.5 p-3">
            <dt className="flex items-center gap-1.5 text-xs font-medium text-primary">
                <Icon className="size-3.5" />
                {label}
            </dt>
            <dd className="leading-7">{children}</dd>
        </div>
    );
}

function BusinessAction({
    item,
    index,
}: {
    item: Recommendation;
    index: number;
}) {
    const { t, locale, number } = useI18n();
    const reduced = useReducedMotion();
    const c = t.productReview;
    const s = t.stagnant;
    return (
        <motion.section
            initial={reduced ? false : { opacity: 0, y: 12 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{
                duration: 0.3,
                delay: reduced ? 0 : index * 0.08,
            }}
            className="space-y-3 rounded-2xl border p-4 sm:p-5"
        >
            <div className="flex flex-wrap items-center gap-2">
                <span className="flex size-7 items-center justify-center rounded-lg bg-primary/10 text-xs font-semibold text-primary">
                    {number(index + 1)}
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
                <strong>{c.why}: </strong>
                {item.reason[locale]}
            </p>
            <p className="text-sm leading-7 text-muted-foreground">
                <strong>{c.effect}: </strong>
                {item.effect[locale]}
            </p>
            <div className="rounded-xl bg-muted/50 p-3 text-sm leading-7">
                <span className="mb-1 block text-xs font-medium text-muted-foreground">
                    {s.businessStep}
                </span>
                {item.instruction[locale]}
            </div>
        </motion.section>
    );
}

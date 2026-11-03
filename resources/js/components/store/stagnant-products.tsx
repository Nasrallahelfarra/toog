import { Link, useForm, usePoll } from '@inertiajs/react';
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
import { useState } from 'react';
import type { SpecialistReview } from '@/components/evaluation/types';
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
    recovery: Recovery | null;
};

export type StagnantSummary = {
    total: number;
    stock: number;
    stock_value: number;
};
export type StagnantDashboard = StagnantSummary & {
    products: StagnantProduct[];
};

function RecommendationPoll({ dashboard }: { dashboard: boolean }) {
    usePoll(3000, { only: [dashboard ? 'stagnant' : 'products'] });
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
                count: number(data.total),
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
            <StagnantProductGrid products={data.products} dashboard />
        </SectionCard>
    );
}

export function StagnantProductGrid({
    products,
    dashboard = false,
    filtered = false,
}: {
    products: StagnantProduct[];
    dashboard?: boolean;
    filtered?: boolean;
}) {
    const { t, number, money, isRtl } = useI18n();
    const s = t.stagnant;
    const reduced = useReducedMotion();
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const selected = products.find((p) => p.id === selectedId);
    const request = useForm({});
    const [requestError, setRequestError] = useState(false);
    const active = products.some(
        (p) =>
            p.recovery?.status === 'pending' ||
            p.recovery?.status === 'processing',
    );

    const recommend = (product: StagnantProduct) => {
        setSelectedId(product.id);
        setRequestError(false);
        if (product.recovery && product.recovery.status !== 'failed') return;
        request.post(`/store/stagnant/${product.id}/recommendation`, {
            preserveScroll: true,
            onError: () => setRequestError(true),
        });
    };

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
                                    <span className="absolute start-2 top-2 inline-flex items-center gap-1 rounded-full border border-danger/15 bg-background/95 px-2 py-1 text-[11px] font-medium text-danger">
                                        <Turtle className="size-3" />
                                        {t.common.performanceClass.stagnant}
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
                                                    recommend(selected)
                                                }
                                                disabled={request.processing}
                                            >
                                                {s.retry}
                                            </Button>
                                        </div>
                                    ) : selected.recovery?.status ===
                                          'completed' &&
                                      selected.recovery.review?.status ===
                                          'available' ? (
                                        <RecoveryResult
                                            recovery={selected.recovery}
                                        />
                                    ) : (
                                        <ReviewLoading />
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

function ReviewLoading() {
    const { t } = useI18n();
    const s = t.stagnant;
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
                <h3 className="text-lg font-semibold">{s.loading}</h3>
                <p className="mt-2 text-sm leading-7 text-muted-foreground">
                    {s.loadingHint}
                </p>
            </div>
            <ol className="grid gap-2 text-xs sm:grid-cols-3">
                {s.steps.map((step) => (
                    <li
                        key={step}
                        className="flex items-center justify-center gap-2 rounded-xl bg-muted/60 p-3"
                    >
                        <Clock3 className="size-3.5 text-primary" />
                        {step}
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
        </div>
    );
}

function RecoveryResult({ recovery }: { recovery: Recovery }) {
    const { t, locale, dateTime } = useI18n();
    const reduced = useReducedMotion();
    const review = recovery.review!;
    const c = t.productReview;
    const s = t.stagnant;
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
                        className="overflow-hidden rounded-2xl border"
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
                                <strong>{c.why}: </strong>
                                {item.reason[locale]}
                            </p>
                            <p className="text-sm leading-7 text-muted-foreground">
                                <strong>{c.effect}: </strong>
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
                                <Button asChild className="w-full sm:w-auto">
                                    <Link
                                        href={`/store/studio?recovery=${recovery.id}&action=${encodeURIComponent(item.id)}`}
                                    >
                                        <WandSparkles className="size-4" />
                                        {s.apply}
                                    </Link>
                                </Button>
                                <p className="text-xs leading-6 text-muted-foreground">
                                    {s.applyHint}
                                </p>
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
        </div>
    );
}

import { Link, router, usePoll } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    CalendarClock,
    GitCompareArrows,
    History,
    Lightbulb,
    ListChecks,
    RefreshCw,
    ShieldCheck,
    Sparkles,
    Trash2,
    Wand2,
} from 'lucide-react';
import { motion } from 'motion/react';
import { Analyzing } from '@/components/evaluation/analyzing';
import { CriteriaBreakdown } from '@/components/evaluation/criteria-breakdown';
import { DecisionPanel } from '@/components/evaluation/decision-panel';
import { EvidencePanel } from '@/components/evaluation/evidence-panel';
import { ProductReviewPanel } from '@/components/evaluation/product-review';
import {
    useChangeSummary,
    useModificationText,
} from '@/components/evaluation/modification-text';
import { CRITERIA } from '@/components/evaluation/types';
import type {
    EvaluationDetail,
    Factor,
    Improvement,
    Thresholds,
    VersionRow,
} from '@/components/evaluation/types';
import { ColorChips } from '@/components/mojo/color-chips';
import { ConfirmDialog } from '@/components/mojo/confirm-dialog';
import { DecisionBadge } from '@/components/mojo/decision-badge';
import { FadeIn, Stagger, StaggerItem } from '@/components/mojo/motion';
import { PageBody, PageHeader } from '@/components/mojo/page-header';
import { ScoreRing } from '@/components/mojo/score-ring';
import { SectionCard } from '@/components/mojo/section-card';
import { decisionTone, scoreTone, toneClasses } from '@/components/mojo/tone';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/hooks/use-i18n';
import { useVocabulary } from '@/hooks/use-vocabulary';
import { cn } from '@/lib/utils';

type Props = {
    evaluation: EvaluationDetail;
    versions: VersionRow[];
    designs: {
        id: number;
        title: string;
        image_url: string;
        score: number | null;
        status: string;
    }[];
    thresholds: Thresholds;
    improvements: Improvement[];
    canImprove: boolean;
};

export default function EvaluationShow({
    evaluation: ev,
    versions,
    designs,
    thresholds,
    improvements,
    canImprove,
}: Props) {
    const { t, tr, field, pick, date, money, locale } = useI18n();
    const { label } = useVocabulary();
    const s = t.evaluation.show;
    const modText = useModificationText();
    const changeSummary = useChangeSummary();
    const finished = ev.status === 'completed' || ev.status === 'failed';
    const breakdown = ev.breakdown;
    const tone =
        toneClasses[
            ev.recommendation ? decisionTone[ev.recommendation] : 'neutral'
        ];
    const explanation = field(ev, 'explanation');
    const launch = breakdown?.evidence.launch;
    const hasReview = ev.specialist_review?.status === 'available';

    const header = (
        <PageHeader
            title={ev.name}
            icon={Sparkles}
            eyebrow={
                <span className="flex flex-wrap items-center gap-2">
                    {ev.category && <span>{ev.category}</span>}
                    <Badge variant="info">
                        {tr(t.evaluation.list.version, { n: ev.version })}
                    </Badge>
                    {ev.source === 'design' && (
                        <Badge variant="gold">{s.designOrigin}</Badge>
                    )}
                    {ev.batch && (
                        <Link
                            href={`/store/batches/${ev.batch.id}`}
                            className="underline-offset-4 hover:underline"
                        >
                            {tr(s.batch, { name: ev.batch.name })}
                        </Link>
                    )}
                </span>
            }
            actions={
                <>
                    <Button variant="ghost" asChild>
                        <Link href="/store/evaluations">
                            <ArrowRight className="size-4 ltr:rotate-180" />
                            {t.common.back}
                        </Link>
                    </Button>
                    {versions.length > 1 && (
                        <Button variant="outline" asChild>
                            <Link href={`/store/evaluations/${ev.id}/compare`}>
                                <GitCompareArrows className="size-4" />
                                {s.compare}
                            </Link>
                        </Button>
                    )}
                    {finished && ev.source !== 'design' && (
                        <Button variant="outline" asChild>
                            <Link
                                href={`/store/evaluations/create?parent=${ev.id}`}
                            >
                                <RefreshCw className="size-4" />
                                {s.newVersion}
                            </Link>
                        </Button>
                    )}
                    <ConfirmDialog
                        destructive
                        description={s.deleteConfirm}
                        confirmLabel={t.common.delete}
                        onConfirm={() =>
                            router.delete(`/store/evaluations/${ev.id}`)
                        }
                        trigger={
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label={t.common.delete}
                            >
                                <Trash2 className="size-4 text-danger" />
                            </Button>
                        }
                    />
                </>
            }
        />
    );

    if (!finished) {
        return (
            <PageBody className="max-w-5xl">
                <Poll />
                {header}
                <SectionCard>
                    <Analyzing />
                </SectionCard>
            </PageBody>
        );
    }

    if (ev.status === 'failed' || !breakdown) {
        return (
            <PageBody className="max-w-5xl">
                {header}
                <SectionCard>
                    <div className="flex flex-col items-center gap-4 py-10 text-center">
                        <span className="flex size-14 items-center justify-center rounded-2xl bg-danger-soft text-danger">
                            <AlertTriangle className="size-7" />
                        </span>
                        <div className="space-y-1">
                            <h2 className="text-lg font-semibold">
                                {s.failedTitle}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {s.failedBody}
                            </p>
                            {ev.error && (
                                <p
                                    className="mt-3 rounded-lg bg-muted px-3 py-2 font-mono text-xs text-muted-foreground"
                                    dir="ltr"
                                >
                                    {ev.error}
                                </p>
                            )}
                        </div>
                        <Button
                            onClick={() =>
                                router.post(`/store/evaluations/${ev.id}/retry`)
                            }
                        >
                            <RefreshCw className="size-4" />
                            {s.retry}
                        </Button>
                    </div>
                </SectionCard>
            </PageBody>
        );
    }

    const positives = (ev.top_factors ?? []).filter(
        (f) => f.impact === 'positive',
    );
    // Weak criteria that the stored factors left out still count against
    // the product, so derive them from the breakdown.
    const negatives = [
        ...(ev.top_factors ?? []).filter((f) => f.impact === 'negative'),
        ...CRITERIA.filter(
            (key) =>
                (breakdown.criteria[key]?.score ?? 100) < 55 &&
                !(ev.top_factors ?? []).some((f) => f.criterion === key),
        ).map((key): Factor => ({
            criterion: key,
            impact: 'negative',
            detail_ar: t.evaluation.criteria[key].hint,
            detail_en: t.evaluation.criteria[key].hint,
        })),
    ];

    return (
        <PageBody>
            {header}
            <ProductReviewPanel
                evaluation={ev}
                improvements={improvements}
                canImprove={canImprove}
            />

            {/* Hero: score, decision, explanation */}
            <FadeIn className="relative overflow-hidden rounded-3xl border border-border/70 bg-card shadow-sm">
                <div className={cn('absolute inset-x-0 top-0 h-1', tone.bg)} />
                <div
                    className={cn(
                        'pointer-events-none absolute -end-24 -top-24 size-72 rounded-full opacity-60 blur-3xl',
                        tone.soft,
                    )}
                />
                <div className="relative grid gap-6 p-6 md:grid-cols-[auto_1fr] lg:grid-cols-[auto_1fr_auto] lg:p-8">
                    <div className="flex flex-col items-center gap-3">
                        <ScoreRing
                            score={ev.score}
                            size={176}
                            stroke={12}
                            accept={thresholds.accept}
                            reject={thresholds.reject}
                            label={t.productReview.score}
                        />
                        <p className="text-[11px] text-muted-foreground">
                            {tr(s.thresholds, thresholds)}
                        </p>
                    </div>

                    <div className="flex min-w-0 flex-col gap-4">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="text-sm text-muted-foreground">
                                {s.recommendation}
                            </span>
                            <motion.div
                                initial={{ scale: 0.8, opacity: 0 }}
                                animate={{ scale: 1, opacity: 1 }}
                                transition={{ delay: 0.5, type: 'spring' }}
                            >
                                <DecisionBadge
                                    value={ev.recommendation}
                                    size="lg"
                                />
                            </motion.div>
                            <Badge variant={ev.is_novel ? 'gold' : 'neutral'}>
                                {ev.is_novel ? s.novel : s.proven}
                            </Badge>
                        </div>
                        <div className="space-y-2">
                            <h2 className="text-sm font-semibold">
                                {s.explanation}
                            </h2>
                            <p className="text-[15px] leading-loose text-foreground/90">
                                {explanation}
                            </p>
                            <span className="inline-flex items-center gap-1.5 text-[11px] text-muted-foreground">
                                <Sparkles className="size-3" />
                                {breakdown.ai_written
                                    ? s.aiWritten
                                    : s.templateWritten}
                            </span>
                        </div>
                        {!hasReview &&
                            ev.recommendation !== 'accept' &&
                            ev.expected_score !== null &&
                            ev.expected_score > (ev.score ?? 0) && (
                                <div className="flex flex-wrap items-center gap-3">
                                    <span className="inline-flex items-center gap-2 rounded-xl bg-success-soft px-3 py-2 text-sm text-success">
                                        {s.expectedAfter}
                                        <strong className="num text-base">
                                            {ev.expected_score}
                                        </strong>
                                    </span>
                                    <Button variant="gold" asChild>
                                        <Link
                                            href={`/store/studio?evaluation=${ev.id}`}
                                        >
                                            <Wand2 className="size-4" />
                                            {s.generateImproved}
                                        </Link>
                                    </Button>
                                </div>
                            )}
                    </div>

                    <div className="hidden lg:block">
                        <div className="size-44 overflow-hidden rounded-2xl bg-muted ring-1 ring-border">
                            {ev.image_url ? (
                                <img
                                    src={ev.image_url}
                                    alt={ev.name}
                                    className="size-full object-cover"
                                />
                            ) : (
                                <div className="flex size-full flex-col items-center justify-center gap-2">
                                    <ColorChips
                                        colors={ev.colors}
                                        showLabels={false}
                                        size="md"
                                    />
                                    <span className="text-xs text-muted-foreground">
                                        {s.noImage}
                                    </span>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </FadeIn>

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <SectionCard title={s.criteria} icon={ListChecks}>
                        <CriteriaBreakdown
                            breakdown={breakdown}
                            factors={ev.top_factors}
                        />
                    </SectionCard>

                    {(positives.length > 0 || negatives.length > 0) && (
                        <SectionCard title={s.topFactors}>
                            <div className="grid gap-4 md:grid-cols-2">
                                {[
                                    {
                                        items: positives,
                                        title: s.positive,
                                        tone: 'success' as const,
                                    },
                                    {
                                        items: negatives,
                                        title: s.negative,
                                        tone: 'danger' as const,
                                    },
                                ].map((group) => (
                                    <div
                                        key={group.title}
                                        className="space-y-2"
                                    >
                                        <h4
                                            className={cn(
                                                'text-xs font-semibold',
                                                toneClasses[group.tone].text,
                                            )}
                                        >
                                            {group.title}
                                        </h4>
                                        {group.items.length === 0 && (
                                            <p className="text-sm text-muted-foreground">
                                                —
                                            </p>
                                        )}
                                        {group.items.map((f, i) => (
                                            <motion.div
                                                key={f.criterion + i}
                                                initial={{
                                                    opacity: 0,
                                                    x: locale === 'ar' ? 8 : -8,
                                                }}
                                                animate={{ opacity: 1, x: 0 }}
                                                transition={{
                                                    delay: 0.1 + i * 0.06,
                                                }}
                                                className={cn(
                                                    'rounded-xl border-s-[3px] p-3',
                                                    toneClasses[group.tone]
                                                        .soft,
                                                    group.tone === 'success'
                                                        ? 'border-success'
                                                        : 'border-danger',
                                                )}
                                            >
                                                <div className="text-sm font-medium">
                                                    {t.evaluation.criteria[
                                                        f.criterion
                                                    ]?.label ??
                                                        field(f, 'label')}
                                                </div>
                                                <p className="mt-0.5 text-xs leading-relaxed text-muted-foreground">
                                                    {field(f, 'detail')}
                                                </p>
                                            </motion.div>
                                        ))}
                                    </div>
                                ))}
                            </div>
                        </SectionCard>
                    )}

                    {!hasReview && (ev.modifications?.length ?? 0) > 0 && (
                        <SectionCard
                            title={s.modifications}
                            description={s.modificationsHint}
                            icon={Wand2}
                            action={
                                <Button size="sm" variant="gold" asChild>
                                    <Link
                                        href={`/store/studio?evaluation=${ev.id}`}
                                    >
                                        <Wand2 className="size-3.5" />
                                        {s.generateImproved}
                                    </Link>
                                </Button>
                            }
                        >
                            <Stagger className="space-y-2.5">
                                {ev.modifications!.map((m, i) => {
                                    const text = modText(m);

                                    return (
                                        <StaggerItem
                                            key={i}
                                            className="flex items-start gap-3 rounded-xl border p-3.5 transition-colors hover:border-primary/30"
                                        >
                                            <span className="flex size-7 shrink-0 items-center justify-center rounded-lg bg-primary/8 text-xs font-semibold text-primary">
                                                {i + 1}
                                            </span>
                                            <div className="min-w-0 flex-1">
                                                <div className="text-sm font-medium">
                                                    {text.title}
                                                </div>
                                                {text.detail && (
                                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                                        {text.detail}
                                                    </p>
                                                )}
                                                <span className="mt-1 inline-block text-[11px] text-muted-foreground">
                                                    {
                                                        t.evaluation.criteria[
                                                            m.criterion
                                                        ]?.label
                                                    }
                                                </span>
                                            </div>
                                            <span className="num shrink-0 rounded-lg bg-success-soft px-2 py-1 text-sm font-semibold text-success">
                                                +{m.expected_gain}
                                            </span>
                                        </StaggerItem>
                                    );
                                })}
                            </Stagger>
                            <div className="mt-4 flex items-center justify-between rounded-xl bg-muted/50 px-4 py-3">
                                <span className="text-sm">
                                    {s.expectedAfter}
                                </span>
                                <span className="flex items-center gap-2">
                                    <span className="num text-muted-foreground">
                                        {ev.score}
                                    </span>
                                    <ArrowRight className="size-4 text-muted-foreground rtl:rotate-180" />
                                    <span
                                        className={cn(
                                            'num text-xl font-semibold',
                                            toneClasses[
                                                scoreTone(
                                                    ev.expected_score,
                                                    thresholds.accept,
                                                    thresholds.reject,
                                                )
                                            ].text,
                                        )}
                                    >
                                        {ev.expected_score}
                                    </span>
                                </span>
                            </div>
                        </SectionCard>
                    )}

                    {(ev.alternatives?.length ?? 0) > 0 && (
                        <SectionCard
                            title={s.alternatives}
                            description={s.alternativesHint}
                            icon={Lightbulb}
                        >
                            <div className="grid gap-3 md:grid-cols-3">
                                {ev.alternatives!.map((alt, i) => {
                                    const merged = {
                                        ...ev,
                                        ...alt.change,
                                    } as Record<string, unknown>;
                                    const query = new URLSearchParams({
                                        name: ev.name,
                                        ...(ev.category
                                            ? { category: ev.category }
                                            : {}),
                                        ...(ev.price
                                            ? {
                                                  price: String(
                                                      Math.round(
                                                          Number(ev.price),
                                                      ),
                                                  ),
                                              }
                                            : {}),
                                        colors: (
                                            (merged.colors as string[]) ?? []
                                        ).join(','),
                                        ...(typeof merged.pattern === 'string'
                                            ? {
                                                  pattern: String(
                                                      merged.pattern,
                                                  ),
                                              }
                                            : {}),
                                        ...(typeof merged.material === 'string'
                                            ? {
                                                  material: String(
                                                      merged.material,
                                                  ),
                                              }
                                            : {}),
                                        ...(typeof merged.style === 'string'
                                            ? { style: String(merged.style) }
                                            : {}),
                                        ...(typeof merged.season === 'string'
                                            ? { season: String(merged.season) }
                                            : {}),
                                    });

                                    return (
                                        <div
                                            key={i}
                                            className="flex flex-col gap-3 rounded-xl border p-4"
                                        >
                                            <span
                                                className={cn(
                                                    'num text-2xl font-semibold',
                                                    toneClasses[
                                                        scoreTone(
                                                            alt.score,
                                                            thresholds.accept,
                                                            thresholds.reject,
                                                        )
                                                    ].text,
                                                )}
                                            >
                                                {alt.score}
                                            </span>
                                            <p className="flex-1 text-xs leading-relaxed text-muted-foreground">
                                                {changeSummary(alt.change)}
                                            </p>
                                            {Array.isArray(
                                                alt.change.colors,
                                            ) && (
                                                <ColorChips
                                                    colors={
                                                        alt.change
                                                            .colors as string[]
                                                    }
                                                    showLabels={false}
                                                />
                                            )}
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                asChild
                                            >
                                                <Link
                                                    href={`/store/evaluations/create?${query.toString()}`}
                                                >
                                                    {s.evaluateAlternative}
                                                </Link>
                                            </Button>
                                        </div>
                                    );
                                })}
                            </div>
                        </SectionCard>
                    )}

                    <EvidencePanel
                        evidence={breakdown.evidence}
                        price={ev.price ? Number(ev.price) : null}
                    />
                </div>

                <div className="space-y-6">
                    <div className="space-y-6 lg:sticky lg:top-20">
                        <DecisionPanel evaluation={ev} />

                        <SectionCard title={s.launch} icon={CalendarClock}>
                            {launch ? (
                                <div className="space-y-1">
                                    <div className="text-xl font-semibold">
                                        {date(launch.date)}
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        {tr(s.launchFor, {
                                            event:
                                                locale === 'ar'
                                                    ? launch.event_ar
                                                    : launch.event_en,
                                        })}
                                    </p>
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    {s.launchAnytime}
                                </p>
                            )}
                        </SectionCard>

                        <SectionCard title={s.culturalFlags} icon={ShieldCheck}>
                            {ev.cultural_flags?.length ? (
                                <ul className="space-y-2">
                                    {ev.cultural_flags.map((f, i) => (
                                        <li
                                            key={i}
                                            className="flex items-start gap-2 text-sm"
                                        >
                                            <Badge
                                                variant={
                                                    f.severity === 'high'
                                                        ? 'danger'
                                                        : f.severity ===
                                                            'medium'
                                                          ? 'warning'
                                                          : 'neutral'
                                                }
                                            >
                                                {s.severity[f.severity]}
                                            </Badge>
                                            <span>{field(f, 'issue')}</span>
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="flex items-center gap-2 text-sm text-success">
                                    <ShieldCheck className="size-4" />
                                    {s.culturalOk}
                                </p>
                            )}
                        </SectionCard>

                        {(breakdown.advice?.length ?? 0) > 0 && (
                            <SectionCard title={s.advice} icon={Lightbulb}>
                                <ul className="space-y-2">
                                    {breakdown.advice!.map((a, i) => (
                                        <li
                                            key={i}
                                            className="flex gap-2 text-sm leading-relaxed"
                                        >
                                            <span className="mt-2 size-1.5 shrink-0 rounded-full bg-gold" />
                                            {pick(a)}
                                        </li>
                                    ))}
                                </ul>
                            </SectionCard>
                        )}
                    </div>
                </div>
            </div>

            <div className="grid gap-6 lg:grid-cols-3">
                <SectionCard title={s.product} className="lg:col-span-1">
                    <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                        <Detail
                            term={t.evaluation.form.price}
                            value={ev.price ? money(ev.price) : '—'}
                        />
                        <Detail
                            term={t.evaluation.form.material}
                            value={label('materials', ev.material)}
                        />
                        <Detail
                            term={t.evaluation.form.pattern}
                            value={label('patterns', ev.pattern)}
                        />
                        <Detail
                            term={t.evaluation.form.style}
                            value={label('styles', ev.style)}
                        />
                        <Detail
                            term={t.evaluation.form.season}
                            value={label('seasons', ev.season)}
                        />
                        <div className="col-span-2 space-y-1.5">
                            <dt className="text-xs text-muted-foreground">
                                {t.evaluation.form.colors}
                            </dt>
                            <dd>
                                <ColorChips colors={ev.colors} />
                            </dd>
                        </div>
                        {ev.description && (
                            <div className="col-span-2 space-y-1">
                                <dt className="text-xs text-muted-foreground">
                                    {t.evaluation.form.description}
                                </dt>
                                <dd className="leading-relaxed">
                                    {ev.description}
                                </dd>
                            </div>
                        )}
                    </dl>
                </SectionCard>

                <SectionCard title={s.versions} icon={History}>
                    <ol className="relative space-y-3 border-s ps-4">
                        {versions.map((v) => (
                            <li key={v.id} className="relative">
                                <span
                                    className={cn(
                                        'absolute -start-[21px] top-1.5 size-2.5 rounded-full ring-4 ring-card',
                                        v.id === ev.id
                                            ? 'bg-gold'
                                            : 'bg-border',
                                    )}
                                />
                                <Link
                                    href={`/store/evaluations/${v.id}`}
                                    className={cn(
                                        'flex items-center justify-between gap-2 rounded-lg px-2 py-1.5 transition-colors',
                                        v.id === ev.id
                                            ? 'bg-muted'
                                            : 'hover:bg-muted/60',
                                    )}
                                >
                                    <span className="text-sm">
                                        {tr(t.evaluation.list.version, {
                                            n: v.version,
                                        })}
                                        <span className="ms-2 text-xs text-muted-foreground">
                                            {date(v.created_at)}
                                        </span>
                                    </span>
                                    <span className="flex items-center gap-2">
                                        <span className="num text-sm font-semibold">
                                            {v.score ?? '—'}
                                        </span>
                                        <DecisionBadge
                                            value={v.recommendation ?? v.status}
                                        />
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ol>
                    {ev.source !== 'design' && (
                        <Button
                            variant="outline"
                            size="sm"
                            className="mt-4 w-full"
                            asChild
                        >
                            <Link
                                href={`/store/evaluations/create?parent=${ev.id}`}
                            >
                                <RefreshCw className="size-3.5" />
                                {s.newVersion}
                            </Link>
                        </Button>
                    )}
                </SectionCard>

                <SectionCard title={s.designs} icon={Wand2}>
                    {designs.length === 0 ? (
                        <div className="flex flex-col items-start gap-3">
                            <p className="text-sm text-muted-foreground">—</p>
                            <Button size="sm" variant="soft" asChild>
                                <Link
                                    href={
                                        hasReview
                                            ? '#specialist-review'
                                            : `/store/studio?evaluation=${ev.id}`
                                    }
                                >
                                    <Wand2 className="size-3.5" />
                                    {s.generateImproved}
                                </Link>
                            </Button>
                        </div>
                    ) : (
                        <div className="grid grid-cols-3 gap-2">
                            {designs.map((d) => (
                                <Link
                                    key={d.id}
                                    href="/store/library"
                                    className="group relative aspect-square overflow-hidden rounded-xl bg-muted"
                                >
                                    <img
                                        src={d.image_url}
                                        alt={d.title}
                                        className="size-full object-cover transition-transform group-hover:scale-105"
                                    />
                                    {d.score !== null && (
                                        <span className="num absolute end-1 bottom-1 rounded-md bg-black/60 px-1.5 text-[11px] font-semibold text-white">
                                            {d.score}
                                        </span>
                                    )}
                                </Link>
                            ))}
                        </div>
                    )}
                </SectionCard>
            </div>
        </PageBody>
    );
}

/** Polls the server while the evaluation is still running. */
function Poll() {
    usePoll(2000, {
        only: [
            'evaluation',
            'versions',
            'designs',
            'improvements',
            'canImprove',
        ],
    });

    return null;
}

function Detail({ term, value }: { term: string; value: string }) {
    return (
        <div className="space-y-0.5">
            <dt className="text-xs text-muted-foreground">{term}</dt>
            <dd className="font-medium">{value}</dd>
        </div>
    );
}

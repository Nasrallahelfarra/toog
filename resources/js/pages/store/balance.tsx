import { Link } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, ArrowRight, BarChart3, CheckCircle2, Layers, Scale, SlidersHorizontal } from 'lucide-react';
import { motion } from 'motion/react';
import { Bar, BarChart, CartesianGrid, Cell, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { ChartLegend, ChartTooltip, useChartAxes } from '@/components/mojo/chart';
import { ColorChips } from '@/components/mojo/color-chips';
import { EmptyState } from '@/components/mojo/empty-state';
import { FadeIn, Stagger, StaggerItem } from '@/components/mojo/motion';
import { Meter } from '@/components/mojo/meter';
import { PageBody, PageHeader } from '@/components/mojo/page-header';
import { ScoreRing } from '@/components/mojo/score-ring';
import { SectionCard } from '@/components/mojo/section-card';
import { toneClasses } from '@/components/mojo/tone';
import type { Tone } from '@/components/mojo/tone';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useI18n } from '@/hooks/use-i18n';
import { useVocabulary } from '@/hooks/use-vocabulary';
import { cn } from '@/lib/utils';

type Category = {
    category: string;
    products: number;
    diversity: number;
    top_signature: { colors: string[]; pattern: string | null; style: string | null; share: number } | null;
    novel_ratio: number | null;
    target_novel: number;
    winners: number;
    stagnant: number;
    recent_launches: number;
    recommended_per_month: number;
    status: 'healthy' | 'repetitive' | 'stale' | 'overloaded';
};

type Alert = {
    type: 'repetitive' | 'overloaded' | 'low_innovation' | 'too_experimental';
    severity: 'warning' | 'danger';
    category: string | null;
    params: Record<string, number | null>;
};

type Props = {
    analysis: {
        overall: {
            diversity: number;
            novel_ratio: number;
            proven_ratio: number;
            target_novel: number;
            target_proven: number;
            products: number;
            recent_launches: number;
        };
        categories: Category[];
        alerts: Alert[];
    };
    repetitiveBelow: number;
};

const statusTone: Record<Category['status'], Tone> = {
    healthy: 'success',
    repetitive: 'warning',
    stale: 'info',
    overloaded: 'danger',
};

const alertLink: Record<Alert['type'], string> = {
    repetitive: '/store/studio',
    overloaded: '/store/performance',
    low_innovation: '/store/market',
    too_experimental: '/store/evaluations/create',
};

export default function Balance({ analysis, repetitiveBelow }: Props) {
    const { t } = useI18n();
    const { overall, categories, alerts } = analysis;

    return (
        <PageBody>
            <PageHeader
                title={t.balance.title}
                description={t.balance.description}
                icon={Scale}
                actions={
                    <Button variant="outline" asChild>
                        <Link href="/store/settings/evaluation">
                            <SlidersHorizontal className="size-4" />
                            {t.balance.changeTarget}
                        </Link>
                    </Button>
                }
            />

            {categories.length === 0 ? (
                <EmptyState icon={Scale} title={t.balance.empty} description={t.balance.emptyHint} />
            ) : (
                <>
                    <div className="grid gap-4 lg:grid-cols-3">
                        <DiversityCard overall={overall} repetitiveBelow={repetitiveBelow} />
                        <RatioCard overall={overall} />
                        <AlertsCard alerts={alerts} />
                    </div>

                    <CategoriesTable categories={categories} repetitiveBelow={repetitiveBelow} />

                    <PaceChart categories={categories} />
                </>
            )}
        </PageBody>
    );
}

function DiversityCard({ overall, repetitiveBelow }: { overall: Props['analysis']['overall']; repetitiveBelow: number }) {
    const { t, tr, number } = useI18n();
    const level = overall.diversity >= 65 ? 'high' : overall.diversity >= repetitiveBelow ? 'medium' : 'low';

    return (
        <FadeIn>
            <SectionCard title={t.balance.diversity} description={t.balance.diversityHint} icon={Layers} className="h-full">
                <div className="flex items-center gap-5">
                    <ScoreRing score={overall.diversity} accept={65} reject={repetitiveBelow} size={124} label="/100" />
                    <div className="space-y-2">
                        <span
                            className={cn(
                                'inline-flex rounded-full px-2.5 py-1 text-xs font-medium',
                                level === 'high' ? 'bg-success-soft text-success' : level === 'medium' ? 'bg-warning-soft text-warning' : 'bg-danger-soft text-danger',
                            )}
                        >
                            {t.balance.levels[level]}
                        </span>
                        <p className="text-muted-foreground text-sm">
                            <strong className="text-foreground num">{number(overall.products)}</strong> {t.balance.products}
                        </p>
                        <p className="text-muted-foreground text-xs">{tr(t.balance.target, { proven: overall.target_proven, novel: overall.target_novel })}</p>
                    </div>
                </div>
            </SectionCard>
        </FadeIn>
    );
}

function RatioCard({ overall }: { overall: Props['analysis']['overall'] }) {
    const { t, tr, number } = useI18n();
    const hasLaunches = overall.recent_launches > 0;
    const actual = [
        { name: t.balance.proven, value: overall.proven_ratio, color: 'var(--chart-1)' },
        { name: t.balance.novel, value: overall.novel_ratio, color: 'var(--chart-2)' },
    ];
    const target = [
        { name: t.balance.proven, value: overall.target_proven, color: 'color-mix(in oklch, var(--chart-1) 35%, transparent)' },
        { name: t.balance.novel, value: overall.target_novel, color: 'color-mix(in oklch, var(--chart-2) 45%, transparent)' },
    ];
    const gap = overall.novel_ratio - overall.target_novel;
    const state = Math.abs(gap) <= 8 ? 'onTarget' : gap < 0 ? 'belowTarget' : 'aboveTarget';

    return (
        <FadeIn delay={0.05}>
            <SectionCard title={t.balance.ratio} description={t.balance.ratioHint} icon={Scale} className="h-full">
                {hasLaunches ? (
                    <div className="flex items-center gap-5">
                        <div className="relative size-32 shrink-0">
                            <ResponsiveContainer width="100%" height="100%">
                                <PieChart>
                                    {/* Outer thin ring = target split */}
                                    <Pie data={target} dataKey="value" innerRadius="88%" outerRadius="100%" startAngle={90} endAngle={-270} stroke="none" isAnimationActive={false}>
                                        {target.map((d) => (
                                            <Cell key={d.name} fill={d.color} />
                                        ))}
                                    </Pie>
                                    <Pie data={actual} dataKey="value" innerRadius="55%" outerRadius="80%" startAngle={90} endAngle={-270} stroke="none" animationDuration={900}>
                                        {actual.map((d) => (
                                            <Cell key={d.name} fill={d.color} />
                                        ))}
                                    </Pie>
                                </PieChart>
                            </ResponsiveContainer>
                            <div className="absolute inset-0 flex flex-col items-center justify-center">
                                <span className="num text-lg font-semibold">
                                    {overall.proven_ratio}/{overall.novel_ratio}
                                </span>
                            </div>
                        </div>
                        <div className="space-y-2">
                            <span
                                className={cn(
                                    'inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium',
                                    state === 'onTarget' ? 'bg-success-soft text-success' : 'bg-warning-soft text-warning',
                                )}
                            >
                                {state === 'onTarget' ? <CheckCircle2 className="size-3.5" /> : <AlertTriangle className="size-3.5" />}
                                {t.balance[state]}
                            </span>
                            <ChartLegend items={actual.map((d) => ({ label: `${d.name} ${d.value}%`, color: d.color }))} />
                            <p className="text-muted-foreground text-xs">{tr(t.balance.target, { proven: overall.target_proven, novel: overall.target_novel })}</p>
                            <p className="text-muted-foreground text-xs">
                                <strong className="num text-foreground">{number(overall.recent_launches)}</strong> {t.balance.recentLaunches}
                            </p>
                        </div>
                    </div>
                ) : (
                    <p className="text-muted-foreground py-8 text-center text-sm">{t.balance.noLaunches}</p>
                )}
            </SectionCard>
        </FadeIn>
    );
}

function AlertsCard({ alerts }: { alerts: Alert[] }) {
    const { t, tr, isRtl } = useI18n();
    const Arrow = isRtl ? ArrowLeft : ArrowRight;

    return (
        <FadeIn delay={0.1}>
            <SectionCard title={t.balance.alerts} icon={AlertTriangle} className="h-full">
                {alerts.length === 0 ? (
                    <div className="flex flex-col items-center gap-2 py-6 text-center">
                        <CheckCircle2 className="text-success size-8" />
                        <p className="text-muted-foreground text-sm">{t.balance.noAlerts}</p>
                    </div>
                ) : (
                    <Stagger className="space-y-2.5">
                        {alerts.map((alert, i) => {
                            const tone = toneClasses[alert.severity === 'danger' ? 'danger' : 'warning'];
                            const vars = Object.fromEntries(
                                Object.entries({ ...alert.params, category: alert.category ?? '' }).map(([k, v]) => [k, v ?? '—']),
                            ) as Record<string, string | number>;

                            return (
                                <StaggerItem key={i}>
                                    <div className={cn('rounded-xl p-3', tone.soft)}>
                                        <p className={cn('text-sm leading-relaxed', tone.text)}>{tr(t.balance.alertText[alert.type], vars)}</p>
                                        <Link href={alertLink[alert.type]} className={cn('mt-1.5 inline-flex items-center gap-1 text-xs font-medium hover:underline', tone.text)}>
                                            {t.balance.alertAction[alert.type]}
                                            <Arrow className="size-3" />
                                        </Link>
                                    </div>
                                </StaggerItem>
                            );
                        })}
                    </Stagger>
                )}
            </SectionCard>
        </FadeIn>
    );
}

function CategoriesTable({ categories, repetitiveBelow }: { categories: Category[]; repetitiveBelow: number }) {
    const { t, tr, number } = useI18n();
    const { label } = useVocabulary();

    return (
        <SectionCard title={t.balance.categories} description={t.balance.categoriesHint} icon={BarChart3} flush>
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead className="ps-5">{t.balance.category}</TableHead>
                        <TableHead className="min-w-32">{t.balance.diversity}</TableHead>
                        <TableHead>{t.balance.topLook}</TableHead>
                        <TableHead className="whitespace-normal">{t.balance.novelRatio}</TableHead>
                        <TableHead className="whitespace-normal">
                            {t.balance.winners} / {t.balance.stagnant}
                        </TableHead>
                        <TableHead className="whitespace-normal">{t.balance.launches}</TableHead>
                        <TableHead className="whitespace-normal">{t.balance.recommended}</TableHead>
                        <TableHead className="pe-5">{t.balance.statusLabel}</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {categories.map((c, i) => {
                        const tone = c.diversity >= 65 ? 'success' : c.diversity >= repetitiveBelow ? 'warning' : 'danger';
                        const novelGap = c.novel_ratio === null ? null : c.novel_ratio - c.target_novel;

                        return (
                            <motion.tr
                                key={c.category}
                                initial={{ opacity: 0, y: 6 }}
                                animate={{ opacity: 1, y: 0 }}
                                transition={{ delay: i * 0.05 }}
                                className="hover:bg-muted/40 border-b border-border/60 transition-colors"
                            >
                                <TableCell className="ps-5">
                                    <div className="font-medium">{c.category}</div>
                                    <div className="text-muted-foreground text-xs">
                                        {number(c.products)} {t.balance.products}
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <Meter value={c.diversity} tone={tone} valueLabel={c.diversity} height={6} delay={i * 0.05} className="min-w-28" />
                                </TableCell>
                                <TableCell>
                                    {c.top_signature ? (
                                        <div className="flex items-center gap-2">
                                            <ColorChips colors={c.top_signature.colors} showLabels={false} />
                                            <span className="text-xs">
                                                {[label('patterns', c.top_signature.pattern), label('styles', c.top_signature.style)].filter((x) => x !== '—').join(' · ')}
                                            </span>
                                            <span className="text-muted-foreground num text-xs">({c.top_signature.share}%)</span>
                                        </div>
                                    ) : (
                                        '—'
                                    )}
                                </TableCell>
                                <TableCell>
                                    {c.novel_ratio === null ? (
                                        <span className="text-muted-foreground">—</span>
                                    ) : (
                                        <span className={cn('num font-medium', novelGap !== null && Math.abs(novelGap) > 8 ? 'text-warning' : 'text-foreground')}>
                                            {c.novel_ratio}%
                                            <span className="text-muted-foreground font-normal"> / {c.target_novel}%</span>
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell className="num">
                                    <span className="text-success font-medium">{c.winners}</span>
                                    <span className="text-muted-foreground"> / </span>
                                    <span className="text-danger font-medium">{c.stagnant}</span>
                                </TableCell>
                                <TableCell className="num">{c.recent_launches}</TableCell>
                                <TableCell>
                                    <span className="bg-primary/8 text-primary dark:bg-primary/15 num rounded-full px-2.5 py-1 text-xs font-semibold">
                                        {tr(t.balance.perMonth, { count: c.recommended_per_month })}
                                    </span>
                                </TableCell>
                                <TableCell className="pe-5">
                                    <span className={cn('inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium', toneClasses[statusTone[c.status]].soft, toneClasses[statusTone[c.status]].text)}>
                                        {t.balance.status[c.status]}
                                    </span>
                                </TableCell>
                            </motion.tr>
                        );
                    })}
                </TableBody>
            </Table>
        </SectionCard>
    );
}

function PaceChart({ categories }: { categories: Category[] }) {
    const { t, number } = useI18n();
    const axes = useChartAxes();
    const data = categories.map((c) => ({
        category: c.category,
        actual: Math.round((c.recent_launches / 6) * 10) / 10,
        suggested: c.recommended_per_month,
    }));

    return (
        <SectionCard title={t.balance.pace} description={t.balance.paceHint} icon={BarChart3}>
            <div className="h-64">
                <ResponsiveContainer width="100%" height="100%">
                    <BarChart data={data} barGap={6} margin={{ top: 8, right: 8, left: 8, bottom: 0 }}>
                        <CartesianGrid {...axes.grid} />
                        <XAxis dataKey="category" {...axes.xAxis} />
                        <YAxis {...axes.yAxis} allowDecimals={false} />
                        <Tooltip cursor={{ fill: 'var(--muted)', opacity: 0.5 }} content={<ChartTooltip formatter={(v) => number(v, 1)} />} />
                        <Bar dataKey="actual" name={t.balance.actualPace} fill="var(--chart-1)" radius={[6, 6, 0, 0]} maxBarSize={36} animationDuration={900} />
                        <Bar dataKey="suggested" name={t.balance.suggestedPace} fill="var(--chart-2)" radius={[6, 6, 0, 0]} maxBarSize={36} animationDuration={900} />
                    </BarChart>
                </ResponsiveContainer>
            </div>
            <ChartLegend
                items={[
                    { label: t.balance.actualPace, color: 'var(--chart-1)' },
                    { label: t.balance.suggestedPace, color: 'var(--chart-2)' },
                ]}
            />
        </SectionCard>
    );
}

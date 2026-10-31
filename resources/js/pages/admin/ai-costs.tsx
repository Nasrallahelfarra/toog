import { Link, router } from '@inertiajs/react';
import { AlertTriangle, BadgeDollarSign, Cpu, Download, FileInput, FileOutput, Gauge, ImageIcon, ScanSearch, Timer } from 'lucide-react';
import { motion } from 'motion/react';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { ChartLegend, ChartTooltip, chartColors, useChartAxes } from '@/components/mojo/chart';
import { EmptyState } from '@/components/mojo/empty-state';
import { Meter } from '@/components/mojo/meter';
import { FadeIn, Stagger, StaggerItem } from '@/components/mojo/motion';
import { PageBody, PageHeader } from '@/components/mojo/page-header';
import { SectionCard } from '@/components/mojo/section-card';
import { StatCard } from '@/components/mojo/stat-card';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

type Period = '7' | '30' | '90' | 'month';
type Num = number | string;

type Props = {
    period: Period;
    from: string;
    totals: {
        cost: number;
        calls: number;
        input_tokens: number;
        output_tokens: number;
        images: number;
        failures: number;
        failure_rate: number;
        avg_latency_ms: number;
        avg_evaluation_cost: number;
    };
    daily: ({ date: string; cost: number; calls: number } & Record<string, number | string>)[];
    operations: string[];
    byStore: {
        store: { id: number; name: string; status: string } | null;
        cost: number;
        calls: number;
        images: number;
        failures: number;
        month_cost: number;
        budget: number | null;
        budget_usage: number | null;
    }[];
    byModel: { provider: string; model: string; cost: Num; calls: Num; input_tokens: Num; output_tokens: Num; images: Num }[];
    byOperation: { operation: string; cost: Num; calls: Num; latency: Num; failures: Num }[];
    failures: { id: number; store: { id: number; name: string } | null; provider: string; model: string; operation: string; error: string | null; latency_ms: number; created_at: string }[];
};

const opColors = [...chartColors, 'var(--info)'];

export default function AiCosts({ period, totals, daily, operations, byStore, byModel, byOperation, failures }: Props) {
    const { t, usd, number, compact, percent, date, dateTime, tr } = useI18n();
    const c = t.admin.costs;
    const axes = useChartAxes();
    const opLabel = (op: string) => t.admin.operations[op as keyof typeof t.admin.operations] ?? op;
    const totalOpCost = byOperation.reduce((s, o) => s + Number(o.cost), 0) || 1;

    return (
        <PageBody>
            <PageHeader
                title={c.title}
                description={c.subtitle}
                icon={BadgeDollarSign}
                actions={
                    <>
                        <div className="bg-muted inline-flex rounded-xl p-1">
                            {(Object.keys(c.periods) as Period[]).map((p) => (
                                <button
                                    key={p}
                                    type="button"
                                    onClick={() => router.get('/admin/ai-costs', { period: p }, { preserveState: true, preserveScroll: true, replace: true })}
                                    className={cn(
                                        'relative cursor-pointer rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                                        period === p ? 'text-foreground' : 'text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {period === p && (
                                        <motion.span layoutId="cost-period" className="bg-card absolute inset-0 rounded-lg shadow-sm" transition={{ type: 'spring', stiffness: 400, damping: 34 }} />
                                    )}
                                    <span className="relative">{c.periods[p]}</span>
                                </button>
                            ))}
                        </div>
                        <Button variant="outline" asChild>
                            <a href={`/admin/ai-costs/export?period=${period}`}>
                                <Download className="size-4" />
                                {c.exportCsv}
                            </a>
                        </Button>
                    </>
                }
            />

            <Stagger className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StaggerItem>
                    <StatCard label={c.totalCost} value={totals.cost} format={(n) => usd(n)} icon={BadgeDollarSign} tone="success" spark={daily.map((d) => d.cost)} />
                </StaggerItem>
                <StaggerItem>
                    <StatCard label={c.calls} value={totals.calls} icon={Cpu} tone="info" />
                </StaggerItem>
                <StaggerItem>
                    <StatCard label={c.avgEvaluation} value={totals.avg_evaluation_cost} format={(n) => usd(n, 4)} icon={ScanSearch} tone="gold" />
                </StaggerItem>
                <StaggerItem>
                    <StatCard
                        label={c.failureRate}
                        value={totals.failure_rate}
                        format={(n) => percent(n, 1)}
                        icon={AlertTriangle}
                        tone={totals.failure_rate > 5 ? 'danger' : 'neutral'}
                        hint={`${number(totals.failures)} ${c.failures}`}
                    />
                </StaggerItem>
                <StaggerItem>
                    <StatCard label={c.tokensIn} value={totals.input_tokens} format={(n) => compact(n)} icon={FileInput} />
                </StaggerItem>
                <StaggerItem>
                    <StatCard label={c.tokensOut} value={totals.output_tokens} format={(n) => compact(n)} icon={FileOutput} />
                </StaggerItem>
                <StaggerItem>
                    <StatCard label={c.images} value={totals.images} icon={ImageIcon} tone="gold" />
                </StaggerItem>
                <StaggerItem>
                    <StatCard label={c.avgLatency} value={totals.avg_latency_ms / 1000} format={(n) => tr(c.seconds, { n: n.toFixed(1) })} icon={Timer} tone="neutral" />
                </StaggerItem>
            </Stagger>

            <FadeIn delay={0.1}>
                <SectionCard
                    title={c.dailyByOperation}
                    action={<ChartLegend items={operations.map((op, i) => ({ label: opLabel(op), color: opColors[i % opColors.length] }))} />}
                >
                    <div className="h-[280px]">
                        <ResponsiveContainer width="100%" height="100%">
                            <BarChart data={daily} margin={{ top: 8, right: 4, left: 4, bottom: 0 }}>
                                <CartesianGrid {...axes.grid} />
                                <XAxis dataKey="date" {...axes.xAxis} minTickGap={20} tickFormatter={(v: string) => date(v, { day: 'numeric', month: 'short' })} />
                                <YAxis {...axes.yAxis} tickFormatter={(v: number) => `$${v.toFixed(v < 1 ? 2 : 0)}`} />
                                <Tooltip
                                    cursor={{ fill: 'var(--muted)', opacity: 0.5 }}
                                    content={<ChartTooltip formatter={(v) => usd(v, 4)} labelFormatter={(l) => date(String(l), { weekday: 'short', day: 'numeric', month: 'short' })} />}
                                />
                                {operations.map((op, i) => (
                                    <Bar
                                        key={op}
                                        dataKey={op}
                                        name={opLabel(op)}
                                        stackId="ops"
                                        fill={opColors[i % opColors.length]}
                                        radius={i === operations.length - 1 ? [4, 4, 0, 0] : 0}
                                        animationDuration={800}
                                    />
                                ))}
                            </BarChart>
                        </ResponsiveContainer>
                    </div>
                </SectionCard>
            </FadeIn>

            <FadeIn delay={0.15}>
                <SectionCard title={c.byStore} flush>
                    {byStore.length === 0 ? (
                        <EmptyState icon={BadgeDollarSign} title={t.common.noData} className="py-8" />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t.admin.stores.store}</TableHead>
                                    <TableHead className="text-end">{c.periodCost}</TableHead>
                                    <TableHead className="text-end">{c.calls}</TableHead>
                                    <TableHead className="text-end">{c.images}</TableHead>
                                    <TableHead className="text-end">{c.failures}</TableHead>
                                    <TableHead className="min-w-52">{c.monthSpend}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {byStore.map((row, i) => (
                                    <TableRow key={row.store?.id ?? `none-${i}`}>
                                        <TableCell>
                                            {row.store ? (
                                                <Link href={`/admin/stores/${row.store.id}?tab=ai`} className="font-medium hover:underline">
                                                    {row.store.name}
                                                </Link>
                                            ) : (
                                                <span className="text-muted-foreground">{c.noStore}</span>
                                            )}
                                        </TableCell>
                                        <TableCell className="num text-end font-semibold">{usd(row.cost)}</TableCell>
                                        <TableCell className="num text-end">{number(row.calls)}</TableCell>
                                        <TableCell className="num text-end">{number(row.images)}</TableCell>
                                        <TableCell className={cn('num text-end', row.failures > 0 && 'text-danger')}>{number(row.failures)}</TableCell>
                                        <TableCell>
                                            {row.budget ? (
                                                <Meter
                                                    value={row.budget_usage ?? 0}
                                                    height={5}
                                                    label={<span className="text-xs">{`${usd(row.month_cost)} / ${usd(row.budget, 0)}`}</span>}
                                                    valueLabel={<span className="text-xs">{percent(row.budget_usage)}</span>}
                                                    tone={(row.budget_usage ?? 0) >= 100 ? 'danger' : (row.budget_usage ?? 0) >= 80 ? 'warning' : 'navy'}
                                                />
                                            ) : (
                                                <span className="text-xs">
                                                    <span className="num font-medium">{usd(row.month_cost)}</span>
                                                    <span className="text-muted-foreground"> · {c.noBudget}</span>
                                                </span>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </SectionCard>
            </FadeIn>

            <div className="grid gap-6 lg:grid-cols-2">
                <FadeIn delay={0.2}>
                    <SectionCard title={c.byOperation} icon={Gauge} className="h-full">
                        {byOperation.length === 0 ? (
                            <p className="text-muted-foreground text-sm">{t.common.noData}</p>
                        ) : (
                            <div className="space-y-4">
                                {byOperation.map((op, i) => (
                                    <Meter
                                        key={op.operation}
                                        label={
                                            <span className="flex items-center gap-2">
                                                <span className="size-2.5 rounded-sm" style={{ backgroundColor: opColors[operations.indexOf(op.operation) % opColors.length] }} />
                                                {opLabel(op.operation)}
                                                <span className="text-muted-foreground text-xs">· {number(op.calls)}</span>
                                            </span>
                                        }
                                        valueLabel={usd(op.cost)}
                                        value={(Number(op.cost) / totalOpCost) * 100}
                                        tone="navy"
                                        height={6}
                                        delay={i * 0.08}
                                    />
                                ))}
                            </div>
                        )}
                    </SectionCard>
                </FadeIn>

                <FadeIn delay={0.25}>
                    <SectionCard title={c.byModel} icon={Cpu} className="h-full" flush>
                        {byModel.length === 0 ? (
                            <p className="text-muted-foreground px-5 pb-5 text-sm">{t.common.noData}</p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>{c.model}</TableHead>
                                        <TableHead className="text-end">{c.calls}</TableHead>
                                        <TableHead className="text-end">{c.tokensIn}</TableHead>
                                        <TableHead className="text-end">{c.tokensOut}</TableHead>
                                        <TableHead className="text-end">{c.totalCost}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {byModel.map((m) => (
                                        <TableRow key={`${m.provider}-${m.model}`}>
                                            <TableCell>
                                                <span dir="ltr" className="font-mono text-xs">
                                                    {m.model}
                                                </span>
                                                <span className="text-muted-foreground block text-[11px]">{m.provider}</span>
                                            </TableCell>
                                            <TableCell className="num text-end">{number(m.calls)}</TableCell>
                                            <TableCell className="num text-end text-xs">{compact(m.input_tokens)}</TableCell>
                                            <TableCell className="num text-end text-xs">{compact(m.output_tokens)}</TableCell>
                                            <TableCell className="num text-end font-semibold">{usd(m.cost)}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </SectionCard>
                </FadeIn>
            </div>

            <FadeIn delay={0.3}>
                <SectionCard title={c.recentFailures} icon={AlertTriangle}>
                    {failures.length === 0 ? (
                        <p className="text-muted-foreground text-sm">{c.noFailures}</p>
                    ) : (
                        <ul className="divide-border/60 divide-y">
                            {failures.map((f) => (
                                <li key={f.id} className="flex flex-col gap-1 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between">
                                    <div className="min-w-0">
                                        <p className="text-sm font-medium">
                                            {opLabel(f.operation)}
                                            {f.store && <span className="text-muted-foreground font-normal"> · {f.store.name}</span>}
                                        </p>
                                        <p className="text-danger truncate text-xs" dir="ltr">
                                            {f.error ?? t.common.error}
                                        </p>
                                    </div>
                                    <span className="text-muted-foreground shrink-0 text-xs">
                                        <span dir="ltr" className="font-mono">
                                            {f.model}
                                        </span>{' '}
                                        · {dateTime(f.created_at)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </FadeIn>
        </PageBody>
    );
}

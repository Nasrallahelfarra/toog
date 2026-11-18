import { Link } from '@inertiajs/react';
import { ArrowDownRight, ArrowLeft, ArrowUpRight, ImageOff, Minus, Trophy } from 'lucide-react';
import { AnimatePresence, LayoutGroup, motion } from 'motion/react';
import type { ReactNode } from 'react';
import { useMemo, useState } from 'react';
import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { Initials, UserStatusBadge } from '@/components/admin/badges';
import { AnimatedNumber } from '@/components/mojo/animated-number';
import { useChartAxes } from '@/components/mojo/chart';
import { EmptyState } from '@/components/mojo/empty-state';
import { Meter } from '@/components/mojo/meter';
import { ease } from '@/components/mojo/motion';
import { SectionCard } from '@/components/mojo/section-card';
import type { TeamMember } from '@/components/store/team/shared';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/hooks/use-i18n';
import { useRevenueVisible } from '@/hooks/use-revenue-visible';
import { cn } from '@/lib/utils';

export type BoardMetric = 'units' | 'products' | 'avg_per_product' | 'avg_per_month' | 'revenue';

const DECIMALS: Record<BoardMetric, number> = { units: 0, products: 0, avg_per_product: 1, avg_per_month: 1, revenue: 0 };

/**
 * The team ranked by one measure: a bar per member on a shared baseline,
 * longest first, with the team average marked across them. Rows glide to
 * their new place when the measure changes. Picking a member drives the
 * profile beside it; hovering previews them.
 */
export function TeamLeaderboard({
    members,
    metric,
    onMetric,
    focus,
    onFocus,
    onPreview,
}: {
    members: TeamMember[];
    metric: BoardMetric;
    onMetric: (metric: BoardMetric) => void;
    focus: number | null;
    onFocus: (id: number) => void;
    onPreview: (id: number | null) => void;
}) {
    const { t, tr, number, money } = useI18n();
    const b = t.team.board;
    const showRevenue = useRevenueVisible();
    const metrics: BoardMetric[] = showRevenue ? ['units', 'revenue', 'products', 'avg_per_product', 'avg_per_month'] : ['units', 'products', 'avg_per_product', 'avg_per_month'];
    const value = (member: TeamMember) => Number(member[metric] ?? 0);
    const format = (n: number) => (metric === 'revenue' ? money(n) : number(n, DECIMALS[metric]));

    const ranked = useMemo(
        () => [...members].sort((a, b) => Number(b[metric] ?? 0) - Number(a[metric] ?? 0) || b.units - a.units || a.name.localeCompare(b.name)),
        [members, metric],
    );
    const peak = Math.max(1, ...ranked.map(value));
    const average = ranked.reduce((sum, member) => sum + value(member), 0) / Math.max(1, ranked.length);
    const averageAt = (average / peak) * 100;
    const leader = ranked[0] && value(ranked[0]) > 0 ? ranked[0].id : null;

    return (
        <SectionCard
            title={b.title}
            description={b.hint}
            icon={Trophy}
            className="h-full"
            bodyClassName="flex flex-1 flex-col gap-4"
        >
            <div role="tablist" aria-label={b.title} className="flex flex-wrap gap-1.5">
                {metrics.map((key) => (
                    <button
                        key={key}
                        type="button"
                        role="tab"
                        aria-selected={metric === key}
                        onClick={() => onMetric(key)}
                        className={cn(
                            'focus-visible:ring-ring cursor-pointer rounded-full border px-3 py-1 text-xs font-medium transition-colors focus-visible:ring-2 focus-visible:outline-none',
                            metric === key ? 'border-primary bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted hover:text-foreground border-border',
                        )}
                    >
                        {b.metrics[key]}
                    </button>
                ))}
            </div>

            <LayoutGroup>
                <ol className="space-y-1.5" onMouseLeave={() => onPreview(null)}>
                    {ranked.map((member, index) => {
                        const n = value(member);
                        const active = member.id === focus;

                        return (
                            <motion.li key={member.id} layout transition={{ type: 'spring', stiffness: 420, damping: 38 }}>
                                <button
                                    type="button"
                                    onClick={() => onFocus(member.id)}
                                    onMouseEnter={() => onPreview(member.id)}
                                    onFocus={() => onPreview(member.id)}
                                    onBlur={() => onPreview(null)}
                                    aria-pressed={active}
                                    className={cn(
                                        'group focus-visible:ring-ring relative grid w-full cursor-pointer grid-cols-[1.75rem_2.25rem_minmax(0,1fr)] items-center gap-x-3 rounded-xl px-2.5 py-2.5 text-start transition-colors focus-visible:ring-2 focus-visible:outline-none',
                                        active ? 'bg-primary/8 dark:bg-primary/15' : 'hover:bg-muted/60',
                                        member.status === 'disabled' && 'opacity-70',
                                    )}
                                >
                                    {active && (
                                        <motion.span
                                            layoutId="team-focus"
                                            className="bg-primary absolute inset-y-2 start-0 w-[3px] rounded-full"
                                            transition={{ type: 'spring', stiffness: 380, damping: 32 }}
                                        />
                                    )}
                                    <span className="flex justify-center">
                                        {member.id === leader ? (
                                            <span className="bg-gold-soft text-gold-foreground dark:text-gold flex size-7 items-center justify-center rounded-full">
                                                <Trophy className="size-3.5" />
                                            </span>
                                        ) : (
                                            <span className="num text-muted-foreground text-sm">{index + 1}</span>
                                        )}
                                    </span>
                                    <Initials name={member.name} />
                                    <span className="min-w-0 space-y-1.5">
                                        <span className="flex items-baseline justify-between gap-3">
                                            <span className={cn('truncate text-sm', active ? 'font-semibold' : 'font-medium')} dir="auto">
                                                {member.name}
                                            </span>
                                            <span className="num shrink-0 text-sm font-semibold">{format(n)}</span>
                                        </span>
                                        <span className="bg-muted relative block h-2.5 rounded-full">
                                            <motion.span
                                                className={cn('bg-chart-1 absolute inset-y-0 start-0 rounded-full transition-opacity', active ? 'opacity-100' : 'opacity-45 group-hover:opacity-75')}
                                                initial={{ width: 0 }}
                                                animate={{ width: `${n > 0 ? Math.max(2, (n / peak) * 100) : 0}%` }}
                                                transition={{ duration: 0.8, delay: index * 0.04, ease }}
                                            />
                                            {average > 0 && (
                                                <span
                                                    aria-hidden
                                                    className="border-foreground/50 absolute -inset-y-1 border-s border-dashed"
                                                    style={{ insetInlineStart: `${averageAt}%` }}
                                                />
                                            )}
                                        </span>
                                        <span className="text-muted-foreground block truncate text-[11px]">
                                            {tr(t.catalog.productsCount, { count: number(member.products) })} · {tr(t.team.compare.selling, { count: number(member.selling) })}
                                        </span>
                                    </span>
                                </button>
                            </motion.li>
                        );
                    })}
                </ol>
            </LayoutGroup>

            {average > 0 && <p className="text-muted-foreground mt-auto text-xs">{tr(b.averageLine, { value: format(average) })}</p>}
        </SectionCard>
    );
}

/**
 * One member in focus: who they are, their figures against the team
 * average, and their months drawn over everyone else's. The member is the
 * point, so they take the accent and the rest of the team stays grey.
 */
export function MemberFocus({
    member,
    members,
    months,
    actions,
    totalPages,
    accessLine,
}: {
    member: TeamMember;
    members: TeamMember[];
    months: string[];
    actions: ReactNode;
    totalPages: number;
    accessLine: (granted: string[], total: number) => ReactNode;
}) {
    const { t, tr, number, money, percent, isRtl } = useI18n();
    const f = t.team.focus;
    const showRevenue = useRevenueVisible();
    const teamAverage = members.reduce((sum, row) => sum + row.units, 0) / Math.max(1, members.length);
    const gap = teamAverage > 0 ? ((member.units - teamAverage) / teamAverage) * 100 : null;

    const figures: { label: string; value: number; format: (n: number) => string }[] = [
        { label: f.units, value: member.units, format: (n) => number(n) },
        { label: f.products, value: member.products, format: (n) => number(n) },
        { label: f.selling, value: member.selling, format: (n) => number(n) },
        { label: f.avgPerProduct, value: member.avg_per_product, format: (n) => number(n, 1) },
        { label: f.avgPerMonth, value: member.avg_per_month, format: (n) => number(n, 1) },
        ...(showRevenue ? [{ label: t.team.compare.revenue, value: member.revenue ?? 0, format: (n: number) => money(n) }] : []),
    ];

    return (
        <SectionCard className="h-full" bodyClassName="flex flex-1 flex-col gap-5">
            <AnimatePresence mode="wait" initial={false}>
                <motion.div
                    key={member.id}
                    initial={{ opacity: 0, y: 8 }}
                    animate={{ opacity: 1, y: 0 }}
                    exit={{ opacity: 0, y: -6 }}
                    transition={{ duration: 0.22, ease }}
                    className="space-y-5"
                >
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="flex min-w-0 items-center gap-3">
                            <Initials name={member.name} className="size-12 rounded-2xl text-sm" />
                            <div className="min-w-0">
                                <h2 className="flex flex-wrap items-center gap-2 text-lg leading-tight font-semibold">
                                    <span className="truncate" dir="auto">
                                        {member.name}
                                    </span>
                                    {member.status === 'disabled' && <UserStatusBadge status={member.status} />}
                                </h2>
                                <p className="text-muted-foreground truncate text-xs" dir="ltr">
                                    {member.email}
                                </p>
                                {accessLine(member.permissions, totalPages)}
                            </div>
                        </div>
                        <div className="flex items-center gap-1.5">
                            <Button asChild variant="outline" size="sm">
                                <Link href={`/store/team/${member.id}`} prefetch>
                                    {f.open}
                                    <ArrowLeft className="size-4 ltr:rotate-180" />
                                </Link>
                            </Button>
                            {actions}
                        </div>
                    </div>

                    {member.products === 0 ? (
                        <p className="bg-muted/60 text-muted-foreground rounded-xl px-4 py-3 text-sm">{f.noProducts}</p>
                    ) : (
                        gap !== null && <AverageGap gap={gap} />
                    )}

                    <dl className={cn('grid grid-cols-2 gap-2 sm:grid-cols-3', showRevenue ? 'xl:grid-cols-6' : 'xl:grid-cols-5')}>
                        {figures.map((figure, index) => (
                            <div key={figure.label} className={cn('rounded-xl border border-border/60 p-3', index === 0 && 'bg-primary/5 dark:bg-primary/10')}>
                                <dt className="text-muted-foreground text-[11px]">{figure.label}</dt>
                                <dd className="mt-1 text-lg leading-none font-semibold">
                                    <AnimatedNumber value={figure.value} format={figure.format} duration={0.7} />
                                </dd>
                            </div>
                        ))}
                    </dl>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Meter value={member.share} tone="navy" height={6} label={f.share} valueLabel={percent(member.share, 1)} />
                            <p className="flex flex-wrap gap-x-3 text-xs">
                                {member.winners > 0 && <span className="text-success">{tr(t.team.compare.winners, { count: number(member.winners) })}</span>}
                                {member.stagnant > 0 && <span className="text-danger">{tr(t.team.compare.stagnant, { count: number(member.stagnant) })}</span>}
                            </p>
                        </div>
                        {member.best && (
                            <div className="flex min-w-0 items-center gap-3">
                                <BestThumb src={member.best.image_url} alt={member.best.name} />
                                <div className="min-w-0">
                                    <p className="text-muted-foreground text-[11px]">{f.best}</p>
                                    <p className="truncate text-sm font-medium" dir="auto">
                                        {member.best.name}
                                    </p>
                                    <p className="num text-muted-foreground text-xs">{tr(t.team.compare.bestUnits, { units: number(member.best.units) })}</p>
                                </div>
                            </div>
                        )}
                    </div>
                </motion.div>
            </AnimatePresence>

            <TeamTrend member={member} members={members} months={months} isRtl={isRtl} />
        </SectionCard>
    );
}

/** How far the member's sales sit from the average member's. */
function AverageGap({ gap }: { gap: number }) {
    const { t, tr, percent } = useI18n();
    const f = t.team.focus;
    const level = Math.abs(gap) < 1 ? 'at' : gap > 0 ? 'above' : 'below';
    const Icon = level === 'at' ? Minus : level === 'above' ? ArrowUpRight : ArrowDownRight;
    const pct = percent(Math.abs(gap));

    return (
        <p
            className={cn(
                'inline-flex w-fit items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium',
                level === 'above' && 'bg-success-soft text-success',
                level === 'below' && 'bg-danger-soft text-danger',
                level === 'at' && 'bg-muted text-muted-foreground',
            )}
        >
            <Icon className="size-3.5" />
            {level === 'at' ? f.atAverage : tr(level === 'above' ? f.aboveAverage : f.belowAverage, { pct })}
        </p>
    );
}

type TrendRow = { period: string; average: number; [member: string]: number | string };

/**
 * Units sold month by month: the member in focus over the team average,
 * with every other member as a quiet line behind them. One measure, one
 * axis; hover reads off the month for the member, the average and whoever
 * led that month.
 */
function TeamTrend({ member, members, months, isRtl }: { member: TeamMember; members: TeamMember[]; months: string[]; isRtl: boolean }) {
    const { t, tr, number, compact, date } = useI18n();
    const f = t.team.focus;
    const axes = useChartAxes();
    const [hidden, setHidden] = useState(false);
    const others = members.filter((row) => row.id !== member.id);

    const data: TrendRow[] = useMemo(
        () =>
            months.map((key, i) => {
                const row: TrendRow = { period: `${key}-01`, average: 0 };
                let sum = 0;
                members.forEach((item) => {
                    const units = item.monthly[i]?.units ?? 0;
                    row[`m${item.id}`] = units;
                    sum += units;
                });
                row.average = Math.round((sum / Math.max(1, members.length)) * 10) / 10;

                return row;
            }),
        [members, months],
    );

    const empty = members.every((row) => row.monthly.every((point) => point.units === 0));
    const monthLabel = (value: string | number) => date(String(value), { month: 'short', year: '2-digit' });
    const showOthers = others.length > 0 && !hidden;

    return (
        <div className="mt-auto space-y-3 border-t pt-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-sm font-semibold">{f.trendTitle}</h3>
                <div className="text-muted-foreground flex flex-wrap items-center gap-x-4 gap-y-1 text-xs">
                    <LegendKey className="bg-chart-1 h-[3px]" label={tr(f.legendMember, { name: member.name })} strong />
                    <LegendKey className="border-foreground/60 h-0 border-t-2 border-dashed" label={f.legendAverage} />
                    {others.length > 0 && (
                        <button type="button" onClick={() => setHidden((v) => !v)} aria-pressed={!hidden} className={cn('hover:text-foreground cursor-pointer', hidden && 'line-through opacity-60')}>
                            <LegendKey className="bg-muted-foreground/40 h-[2px]" label={f.legendOthers} />
                        </button>
                    )}
                </div>
            </div>

            {empty ? (
                <EmptyState title={f.noSales} className="py-8" />
            ) : (
                <div className="h-64" role="img" aria-label={f.trendTitle}>
                    <ResponsiveContainer width="100%" height="100%">
                        <LineChart data={data} margin={{ top: 10, right: isRtl ? 0 : 12, left: isRtl ? 12 : 0, bottom: 0 }}>
                            <CartesianGrid {...axes.grid} />
                            <XAxis dataKey="period" {...axes.xAxis} tickFormatter={monthLabel} minTickGap={16} />
                            <YAxis {...axes.yAxis} tickFormatter={(v: number) => compact(v)} allowDecimals={false} />
                            <Tooltip
                                cursor={{ stroke: 'var(--muted-foreground)', strokeDasharray: '3 3', strokeWidth: 1 }}
                                content={<TrendTooltip member={member} members={members} format={(n) => number(n, Number.isInteger(n) ? 0 : 1)} />}
                            />
                            {showOthers &&
                                others.map((row) => (
                                    <Line
                                        key={row.id}
                                        type="monotone"
                                        dataKey={`m${row.id}`}
                                        name={row.name}
                                        stroke="var(--muted-foreground)"
                                        strokeOpacity={0.3}
                                        strokeWidth={1.5}
                                        dot={false}
                                        activeDot={false}
                                        isAnimationActive={false}
                                    />
                                ))}
                            <Line
                                key="average"
                                type="monotone"
                                dataKey="average"
                                name={f.legendAverage}
                                stroke="var(--foreground)"
                                strokeOpacity={0.55}
                                strokeWidth={2}
                                strokeDasharray="5 5"
                                dot={false}
                                activeDot={false}
                                isAnimationActive={false}
                            />
                            <Line
                                key={`focus-${member.id}`}
                                type="monotone"
                                dataKey={`m${member.id}`}
                                name={member.name}
                                stroke="var(--chart-1)"
                                strokeWidth={2.5}
                                dot={{ r: 3, fill: 'var(--chart-1)', stroke: 'var(--card)', strokeWidth: 2 }}
                                activeDot={{ r: 5, fill: 'var(--chart-1)', stroke: 'var(--card)', strokeWidth: 2 }}
                                animationDuration={650}
                            />
                        </LineChart>
                    </ResponsiveContainer>
                </div>
            )}
        </div>
    );
}

function LegendKey({ className, label, strong = false }: { className: string; label: string; strong?: boolean }) {
    return (
        <span className="inline-flex items-center gap-1.5">
            <span className={cn('inline-block w-4 rounded-full', className)} />
            <span className={cn('max-w-40 truncate', strong && 'text-foreground font-medium')} dir="auto">
                {label}
            </span>
        </span>
    );
}

function TrendTooltip({
    active,
    payload,
    label,
    member,
    members,
    format,
}: {
    active?: boolean;
    payload?: { payload?: TrendRow }[];
    label?: string | number;
    member: TeamMember;
    members: TeamMember[];
    format: (n: number) => string;
}) {
    const { t, date } = useI18n();
    const f = t.team.focus;
    const row = payload?.[0]?.payload;

    if (!active || !row) {
        return null;
    }

    const units = (item: TeamMember) => Number(row[`m${item.id}`] ?? 0);
    const top = [...members].sort((a, b) => units(b) - units(a))[0];
    const lines: { key: string; name: string; value: number; swatch: string; strong?: boolean }[] = [
        { key: 'member', name: member.name, value: units(member), swatch: 'bg-chart-1 h-[3px]', strong: true },
        { key: 'average', name: f.legendAverage, value: Number(row.average), swatch: 'border-foreground/60 h-0 border-t-2 border-dashed' },
        ...(top && top.id !== member.id && units(top) > 0
            ? [{ key: 'top', name: `${f.topOfMonth}: ${top.name}`, value: units(top), swatch: 'bg-muted-foreground/40 h-[2px]' }]
            : []),
    ];

    return (
        <div className="bg-popover/95 min-w-44 rounded-xl border px-3 py-2 text-xs shadow-lg backdrop-blur">
            <div className="text-muted-foreground mb-1.5 font-medium">{date(String(label), { month: 'long', year: 'numeric' })}</div>
            <div className="space-y-1">
                {lines.map((line) => (
                    <div key={line.key} className="flex items-center justify-between gap-4">
                        <span className="text-muted-foreground flex min-w-0 items-center gap-1.5">
                            <span className={cn('inline-block w-3 shrink-0 rounded-full', line.swatch)} />
                            <span className="max-w-40 truncate" dir="auto">
                                {line.name}
                            </span>
                        </span>
                        <span className={cn('num', line.strong ? 'text-sm font-semibold' : 'font-medium')}>{format(line.value)}</span>
                    </div>
                ))}
            </div>
        </div>
    );
}

function BestThumb({ src, alt }: { src: string | null; alt: string }) {
    const [broken, setBroken] = useState(false);

    return src && !broken ? (
        <img src={src} alt={alt} loading="lazy" onError={() => setBroken(true)} className="bg-muted size-12 shrink-0 rounded-xl object-cover" />
    ) : (
        <span className="bg-muted text-muted-foreground/60 flex size-12 shrink-0 items-center justify-center rounded-xl">
            <ImageOff className="size-4" />
        </span>
    );
}

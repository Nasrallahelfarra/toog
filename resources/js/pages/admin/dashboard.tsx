import { Link } from '@inertiajs/react';
import {
    ArrowUpRight,
    BadgeDollarSign,
    CircleAlert,
    Cpu,
    LayoutDashboard,
    PauseCircle,
    Plus,
    ScanSearch,
    Store,
    UserX,
    Users,
    WalletCards,
    WandSparkles,
} from 'lucide-react';
import { Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { ActivityFeed } from '@/components/admin/activity-feed';
import type { ActivityRow } from '@/components/admin/activity-feed';
import { ChartTooltip, useChartAxes } from '@/components/mojo/chart';
import { EmptyState } from '@/components/mojo/empty-state';
import { Meter } from '@/components/mojo/meter';
import { FadeIn, Stagger, StaggerItem } from '@/components/mojo/motion';
import { PageBody, PageHeader } from '@/components/mojo/page-header';
import { SectionCard } from '@/components/mojo/section-card';
import { StatCard } from '@/components/mojo/stat-card';
import { toneClasses } from '@/components/mojo/tone';
import type { Tone } from '@/components/mojo/tone';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

type Props = {
    kpis: {
        stores: { total: number; active: number; suspended: number; connected: number };
        users: { total: number; active: number; disabled: number; admins: number };
        ai: { month_cost: number; last_month_cost: number; delta: number | null; month_calls: number; spark: number[] };
        evaluations: { month: number; total: number };
        designs: { month: number; total: number };
    };
    dailyCost: { date: string; cost: number; calls: number }[];
    topStores: { id: number; name: string; cost: number; budget: number | null }[];
    attention: { type: 'suspended' | 'connection_error' | 'budget' | 'owner_disabled'; store: { id: number; name: string }; detail: string | null; usage?: number }[];
    recentActivity: ActivityRow[];
};

const attentionMeta: Record<Props['attention'][number]['type'], { icon: typeof Store; tone: Tone }> = {
    suspended: { icon: PauseCircle, tone: 'danger' },
    connection_error: { icon: CircleAlert, tone: 'warning' },
    budget: { icon: WalletCards, tone: 'warning' },
    owner_disabled: { icon: UserX, tone: 'neutral' },
};

export default function AdminDashboard({ kpis, dailyCost, topStores, attention, recentActivity }: Props) {
    const { t, tr, usd, number, date } = useI18n();
    const axes = useChartAxes();
    const a = t.admin.overview;

    return (
        <PageBody>
            <PageHeader
                title={a.title}
                description={a.subtitle}
                icon={LayoutDashboard}
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <Link href="/admin/ai-costs">
                                <BadgeDollarSign className="size-4" />
                                {t.nav.aiCosts}
                            </Link>
                        </Button>
                        <Button asChild>
                            <Link href="/admin/stores/create">
                                <Plus className="size-4" />
                                {t.admin.stores.newStore}
                            </Link>
                        </Button>
                    </>
                }
            />

            <Stagger className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <StaggerItem>
                    <StatCard
                        label={a.stores}
                        value={kpis.stores.total}
                        icon={Store}
                        href="/admin/stores"
                        hint={tr(a.activeOf, { active: number(kpis.stores.active), other: number(kpis.stores.suspended) })}
                    />
                </StaggerItem>
                <StaggerItem>
                    <StatCard
                        label={a.users}
                        value={kpis.users.total}
                        icon={Users}
                        tone="gold"
                        href="/admin/users"
                        hint={tr(a.usersOf, { active: number(kpis.users.active), other: number(kpis.users.disabled) })}
                    />
                </StaggerItem>
                <StaggerItem>
                    <StatCard
                        label={a.aiCostMonth}
                        value={kpis.ai.month_cost}
                        format={(n) => usd(n)}
                        icon={BadgeDollarSign}
                        tone="success"
                        delta={kpis.ai.delta}
                        deltaLabel={a.vsLastMonth}
                        invertDelta
                        spark={kpis.ai.spark}
                        href="/admin/ai-costs"
                    />
                </StaggerItem>
                <StaggerItem>
                    <StatCard label={a.aiCalls} value={kpis.ai.month_calls} icon={Cpu} tone="info" hint={t.common.thisMonth} />
                </StaggerItem>
                <StaggerItem>
                    <StatCard
                        label={a.evaluationsMonth}
                        value={kpis.evaluations.month}
                        icon={ScanSearch}
                        tone="navy"
                        hint={tr(a.totalOf, { total: number(kpis.evaluations.total) })}
                    />
                </StaggerItem>
                <StaggerItem>
                    <StatCard
                        label={a.designsMonth}
                        value={kpis.designs.month}
                        icon={WandSparkles}
                        tone="gold"
                        hint={tr(a.totalOf, { total: number(kpis.designs.total) })}
                    />
                </StaggerItem>
            </Stagger>

            <div className="grid gap-6 lg:grid-cols-3">
                <FadeIn delay={0.1} className="lg:col-span-2">
                    <SectionCard title={a.dailyCost} description={a.dailyCostHint} className="h-full">
                        <div className="h-[260px]">
                            <ResponsiveContainer width="100%" height="100%">
                                <AreaChart data={dailyCost} margin={{ top: 8, right: 4, left: 4, bottom: 0 }}>
                                    <defs>
                                        <linearGradient id="adminCost" x1="0" x2="0" y1="0" y2="1">
                                            <stop offset="0%" stopColor="var(--chart-2)" stopOpacity={0.35} />
                                            <stop offset="100%" stopColor="var(--chart-2)" stopOpacity={0} />
                                        </linearGradient>
                                    </defs>
                                    <CartesianGrid {...axes.grid} />
                                    <XAxis
                                        dataKey="date"
                                        {...axes.xAxis}
                                        minTickGap={24}
                                        tickFormatter={(d: string) => date(d, { day: 'numeric', month: 'short' })}
                                    />
                                    <YAxis {...axes.yAxis} tickFormatter={(v: number) => `$${v.toFixed(v < 1 ? 2 : 0)}`} />
                                    <Tooltip
                                        cursor={{ stroke: 'var(--border)' }}
                                        content={
                                            <ChartTooltip
                                                formatter={(v) => usd(v)}
                                                labelFormatter={(l) => date(String(l), { weekday: 'short', day: 'numeric', month: 'short' })}
                                            />
                                        }
                                    />
                                    <Area
                                        type="monotone"
                                        dataKey="cost"
                                        name={t.admin.costs.totalCost}
                                        stroke="var(--chart-2)"
                                        strokeWidth={2.2}
                                        fill="url(#adminCost)"
                                        animationDuration={900}
                                    />
                                </AreaChart>
                            </ResponsiveContainer>
                        </div>
                    </SectionCard>
                </FadeIn>

                <FadeIn delay={0.15}>
                    <SectionCard title={a.topStores} description={a.topStoresHint} className="h-full">
                        {topStores.length === 0 ? (
                            <p className="text-muted-foreground text-sm">{t.common.noData}</p>
                        ) : (
                            <div className="space-y-4">
                                {topStores.map((s, i) => {
                                    const usage = s.budget ? (s.cost / s.budget) * 100 : null;

                                    return (
                                        <div key={s.id} className="space-y-1.5">
                                            <div className="flex items-baseline justify-between gap-2 text-sm">
                                                <Link href={`/admin/stores/${s.id}`} className="truncate font-medium hover:underline">
                                                    {s.name}
                                                </Link>
                                                <span className="num shrink-0 font-semibold">{usd(s.cost)}</span>
                                            </div>
                                            <Meter
                                                value={usage ?? 0}
                                                tone={usage === null ? 'neutral' : usage >= 100 ? 'danger' : usage >= 80 ? 'warning' : 'navy'}
                                                height={6}
                                                delay={0.1 * i}
                                            />
                                            <p className="text-muted-foreground text-[11px]">
                                                {s.budget ? `${number(usage, 0)}% · ${t.admin.detail.budget} ${usd(s.budget, 0)}` : a.noBudget}
                                            </p>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </SectionCard>
                </FadeIn>
            </div>

            <div className="grid gap-6 lg:grid-cols-2">
                <FadeIn delay={0.2}>
                    <SectionCard title={a.attention} icon={CircleAlert} className="h-full">
                        {attention.length === 0 ? (
                            <EmptyState title={a.attentionEmpty} className="py-8" />
                        ) : (
                            <ul className="space-y-2">
                                {attention.map((item, i) => {
                                    const meta = attentionMeta[item.type];

                                    return (
                                        <li key={i}>
                                            <Link
                                                href={`/admin/stores/${item.store.id}`}
                                                className="hover:bg-muted/60 group flex items-center gap-3 rounded-xl border border-transparent p-2.5 transition-colors"
                                            >
                                                <span className={cn('flex size-9 shrink-0 items-center justify-center rounded-xl', toneClasses[meta.tone].soft, toneClasses[meta.tone].text)}>
                                                    <meta.icon className="size-4" />
                                                </span>
                                                <span className="min-w-0 flex-1">
                                                    <span className="block truncate text-sm font-medium">{item.store.name}</span>
                                                    <span className="text-muted-foreground block truncate text-xs">
                                                        {tr(t.admin.attention[item.type], { usage: item.usage ?? 0 })}
                                                        {item.detail ? ` — ${item.detail}` : ''}
                                                    </span>
                                                </span>
                                                <ArrowUpRight className="text-muted-foreground size-4 opacity-0 transition-opacity group-hover:opacity-100 rtl:-scale-x-100" />
                                            </Link>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </SectionCard>
                </FadeIn>

                <FadeIn delay={0.25}>
                    <SectionCard
                        title={a.recentActivity}
                        className="h-full"
                        action={
                            <Button variant="ghost" size="sm" asChild>
                                <Link href="/admin/activity">{a.viewAll}</Link>
                            </Button>
                        }
                    >
                        {recentActivity.length === 0 ? (
                            <p className="text-muted-foreground text-sm">{t.common.noData}</p>
                        ) : (
                            <ActivityFeed rows={recentActivity} />
                        )}
                    </SectionCard>
                </FadeIn>
            </div>
        </PageBody>
    );
}

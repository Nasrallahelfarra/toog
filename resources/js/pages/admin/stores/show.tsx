import { Link, router, useForm } from '@inertiajs/react';
import {
    Activity,
    ArrowLeft,
    BadgeDollarSign,
    Cpu,
    LogIn,
    PauseCircle,
    PlayCircle,
    Plug,
    ScanSearch,
    Settings,
    ShieldCheck,
    Store,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { ActivityFeed } from '@/components/admin/activity-feed';
import type { ActivityRow } from '@/components/admin/activity-feed';
import { ConnectionBadge, Initials, RoleBadge, StoreStatusBadge, UserStatusBadge } from '@/components/admin/badges';
import { Field } from '@/components/admin/field';
import { SliderField } from '@/components/admin/slider-field';
import { ReasonDialog } from '@/components/admin/reason-dialog';
import { ConfirmModal, StoreStatusDialogs, enterStore } from '@/components/admin/store-actions';
import { ChartTooltip, semanticChart, useChartAxes } from '@/components/mojo/chart';
import { DecisionBadge } from '@/components/mojo/decision-badge';
import { EmptyState } from '@/components/mojo/empty-state';
import { Meter } from '@/components/mojo/meter';
import { FadeIn, Stagger, StaggerItem } from '@/components/mojo/motion';
import { PageBody, PageHeader } from '@/components/mojo/page-header';
import { Pagination } from '@/components/mojo/pagination';
import type { Paginated } from '@/components/mojo/pagination';
import { SectionCard } from '@/components/mojo/section-card';
import { StatCard } from '@/components/mojo/stat-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useI18n } from '@/hooks/use-i18n';
import type { AdminStoreRow } from '@/components/admin/types';

type StoreDetail = AdminStoreRow & {
    platform_store_id: string | null;
    suspended_reason: string | null;
    sync_frequency: 'daily' | 'weekly';
    logo_url: string | null;
    settings: { accept_threshold: number; reject_threshold: number; proven_ratio: number };
    has_brand_guide: boolean;
};

type Member = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    role: string;
    member_role: string;
    status: string;
    disabled_reason: string | null;
    last_login_at: string | null;
    is_owner: boolean;
};

type UsageRow = { operation?: string; provider?: string; model?: string; cost: number | string; calls: number | string; failures?: number | string; input_tokens?: number | string; output_tokens?: number | string };

type Props = {
    store: StoreDetail;
    overview: {
        performance: Record<string, number>;
        recommendations: Record<string, number>;
        decisions: Record<string, number>;
        designs: Record<string, number>;
        avg_score: number;
        revenue: { period: string; revenue: number; units: number; orders: number }[];
        sync_runs: { id: number; type: string; status: string; error: string | null; started_at: string | null; finished_at: string | null }[];
    };
    ai: {
        daily: { date: string; cost: number; calls: number }[];
        by_operation: UsageRow[];
        by_model: UsageRow[];
        recent: {
            id: number;
            user: { id: number; name: string } | null;
            provider: string;
            model: string;
            operation: string;
            input_tokens: number;
            output_tokens: number;
            images: number;
            cost: string;
            success: boolean;
            error: string | null;
            latency_ms: number;
            created_at: string;
        }[];
        total_30d: number;
        calls_30d: number;
    };
    members: Member[];
    activity: Paginated<ActivityRow>;
    tab: string;
};

const TABS = ['overview', 'ai', 'members', 'activity', 'settings'] as const;
type Tab = (typeof TABS)[number];

export default function AdminStoreShow({ store, overview, ai, members, activity, tab: initialTab }: Props) {
    const { t, usd, number } = useI18n();
    const d = t.admin.detail;
    const [tab, setTab] = useState<Tab>(TABS.includes(initialTab as Tab) ? (initialTab as Tab) : 'overview');
    const [dialog, setDialog] = useState<'suspend' | 'activate' | null>(null);

    const changeTab = (value: string) => {
        setTab(value as Tab);
        router.get(`/admin/stores/${store.id}`, { tab: value }, { preserveState: true, preserveScroll: true, replace: true });
    };

    const tabIcons = { overview: Store, ai: Cpu, members: Users, activity: Activity, settings: Settings };

    return (
        <PageBody>
            <PageHeader
                title={store.name}
                eyebrow={
                    <Link href="/admin/stores" className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1">
                        <ArrowLeft className="size-3 rtl:rotate-180" />
                        {t.admin.stores.title}
                    </Link>
                }
                description={
                    <span className="flex flex-wrap items-center gap-2">
                        <StoreStatusBadge status={store.status} />
                        <ConnectionBadge status={store.connection_status} />
                        {store.domain && (
                            <span dir="ltr" className="text-muted-foreground text-xs">
                                {store.domain}
                            </span>
                        )}
                    </span>
                }
                actions={
                    <>
                        {store.status === 'suspended' ? (
                            <Button variant="outline" onClick={() => setDialog('activate')}>
                                <PlayCircle className="size-4" />
                                {t.admin.stores.activate}
                            </Button>
                        ) : (
                            <Button variant="outline" className="text-danger hover:text-danger" onClick={() => setDialog('suspend')}>
                                <PauseCircle className="size-4" />
                                {t.admin.stores.suspend}
                            </Button>
                        )}
                        <Button variant="gold" onClick={() => enterStore(store.id)}>
                            <LogIn className="size-4 rtl:rotate-180" />
                            {t.admin.stores.openDashboard}
                        </Button>
                    </>
                }
            />
            <StoreStatusDialogs store={store} dialog={dialog} onClose={() => setDialog(null)} />

            {store.status === 'suspended' && store.suspended_reason && (
                <FadeIn className="bg-danger-soft text-danger flex items-center gap-2 rounded-xl px-4 py-3 text-sm">
                    <PauseCircle className="size-4 shrink-0" />
                    {store.suspended_reason}
                </FadeIn>
            )}

            <Stagger className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StaggerItem>
                    <StatCard label={t.admin.stores.products} value={store.products_count} icon={Store} />
                </StaggerItem>
                <StaggerItem>
                    <StatCard label={t.admin.stores.evaluations} value={store.evaluations_count} icon={ScanSearch} tone="gold" />
                </StaggerItem>
                <StaggerItem>
                    <StatCard label={d.avgScore} value={overview.avg_score || null} icon={ShieldCheck} tone="success" hint="/100" />
                </StaggerItem>
                <StaggerItem>
                    <StatCard
                        label={d.monthSpend}
                        value={store.month_cost}
                        format={(n) => usd(n)}
                        icon={BadgeDollarSign}
                        tone={store.budget_usage !== null && store.budget_usage >= 80 ? 'warning' : 'info'}
                        hint={store.ai_monthly_budget ? `${number(store.budget_usage)}% / ${usd(store.ai_monthly_budget, 0)}` : t.admin.overview.noBudget}
                    />
                </StaggerItem>
            </Stagger>

            <Tabs value={tab} onValueChange={changeTab}>
                <TabsList className="h-auto w-full flex-wrap justify-start sm:w-fit">
                    {TABS.map((key) => {
                        const Icon = tabIcons[key];

                        return (
                            <TabsTrigger key={key} value={key} className="flex-none">
                                <Icon />
                                {d.tabs[key]}
                            </TabsTrigger>
                        );
                    })}
                </TabsList>

                <TabsContent value="overview">
                    <OverviewTab store={store} overview={overview} />
                </TabsContent>
                <TabsContent value="ai">
                    <AiTab store={store} ai={ai} />
                </TabsContent>
                <TabsContent value="members">
                    <MembersTab members={members} />
                </TabsContent>
                <TabsContent value="activity">
                    <SectionCard title={d.tabs.activity}>
                        {activity.data.length === 0 ? (
                            <EmptyState icon={Activity} title={t.admin.activity.empty} className="py-8" />
                        ) : (
                            <div className="space-y-5">
                                <ActivityFeed rows={activity.data} showStore={false} />
                                <Pagination meta={activity} />
                            </div>
                        )}
                    </SectionCard>
                </TabsContent>
                <TabsContent value="settings">
                    <SettingsTab store={store} />
                </TabsContent>
            </Tabs>
        </PageBody>
    );
}

function OverviewTab({ store, overview }: { store: StoreDetail; overview: Props['overview'] }) {
    const { t, number, money, compact, date, dateTime, relative } = useI18n();
    const d = t.admin.detail;
    const axes = useChartAxes();

    const perf = ['winner', 'average', 'stagnant', 'unclassified'].map((key) => ({
        key,
        value: overview.performance[key] ?? 0,
    }));
    const perfTotal = perf.reduce((s, p) => s + p.value, 0);
    const recTotal = ['accept', 'modify', 'reject'].reduce((s, k) => s + (overview.recommendations[k] ?? 0), 0);

    return (
        <div className="grid gap-6 lg:grid-cols-3">
            <SectionCard title={d.revenue12} className="lg:col-span-2">
                {overview.revenue.length === 0 ? (
                    <p className="text-muted-foreground text-sm">{d.noData}</p>
                ) : (
                    <div className="h-[250px]">
                        <ResponsiveContainer width="100%" height="100%">
                            <BarChart data={overview.revenue} margin={{ top: 8, right: 4, left: 4, bottom: 0 }}>
                                <CartesianGrid {...axes.grid} />
                                <XAxis dataKey="period" {...axes.xAxis} tickFormatter={(p: string) => date(`${p}-01`, { month: 'short' })} />
                                <YAxis {...axes.yAxis} tickFormatter={(v: number) => compact(v)} />
                                <Tooltip
                                    cursor={{ fill: 'var(--muted)', opacity: 0.5 }}
                                    content={<ChartTooltip formatter={(v) => money(v)} labelFormatter={(l) => date(`${l}-01`, { month: 'long', year: 'numeric' })} />}
                                />
                                <Bar dataKey="revenue" name={d.revenue} fill="var(--chart-1)" radius={[6, 6, 0, 0]} animationDuration={900} />
                            </BarChart>
                        </ResponsiveContainer>
                    </div>
                )}
            </SectionCard>

            <SectionCard title={d.performance}>
                {perfTotal === 0 ? (
                    <p className="text-muted-foreground text-sm">{d.noData}</p>
                ) : (
                    <div className="space-y-4">
                        <div className="flex h-3 overflow-hidden rounded-full">
                            {perf
                                .filter((p) => p.value > 0)
                                .map((p) => (
                                    <div
                                        key={p.key}
                                        style={{
                                            width: `${(p.value / perfTotal) * 100}%`,
                                            backgroundColor: p.key === 'unclassified' ? 'var(--muted-foreground)' : semanticChart[p.key as 'winner'],
                                        }}
                                    />
                                ))}
                        </div>
                        {perf.map((p) => (
                            <div key={p.key} className="flex items-center justify-between text-sm">
                                <span className="flex items-center gap-2">
                                    <span
                                        className="size-2.5 rounded-full"
                                        style={{ backgroundColor: p.key === 'unclassified' ? 'var(--muted-foreground)' : semanticChart[p.key as 'winner'] }}
                                    />
                                    {p.key === 'unclassified' ? d.unclassified : t.common.performanceClass[p.key as 'winner']}
                                </span>
                                <span className="num font-medium">{number(p.value)}</span>
                            </div>
                        ))}
                    </div>
                )}
            </SectionCard>

            <SectionCard title={d.recommendations}>
                {recTotal === 0 ? (
                    <p className="text-muted-foreground text-sm">{d.noData}</p>
                ) : (
                    <div className="space-y-3.5">
                        {(['accept', 'modify', 'reject'] as const).map((key, i) => (
                            <Meter
                                key={key}
                                label={<DecisionBadge value={key} />}
                                value={((overview.recommendations[key] ?? 0) / recTotal) * 100}
                                valueLabel={number(overview.recommendations[key] ?? 0)}
                                tone={key === 'accept' ? 'success' : key === 'modify' ? 'warning' : 'danger'}
                                delay={i * 0.1}
                            />
                        ))}
                    </div>
                )}
                {Object.keys(overview.decisions).length > 0 && (
                    <div className="mt-5 space-y-2 border-t pt-4">
                        <p className="text-muted-foreground text-xs font-medium">{d.decisions}</p>
                        <div className="flex flex-wrap gap-2">
                            {Object.entries(overview.decisions).map(([key, value]) => (
                                <span key={key} className="flex items-center gap-1.5">
                                    <DecisionBadge value={key} />
                                    <span className="num text-sm font-medium">{number(value)}</span>
                                </span>
                            ))}
                        </div>
                    </div>
                )}
            </SectionCard>

            <SectionCard title={d.designs}>
                {Object.keys(overview.designs).length === 0 ? (
                    <p className="text-muted-foreground text-sm">{d.noData}</p>
                ) : (
                    <div className="grid grid-cols-2 gap-3">
                        {Object.entries(overview.designs).map(([key, value]) => (
                            <div key={key} className="bg-muted/50 rounded-xl p-3">
                                <div className="num text-xl font-semibold">{number(value)}</div>
                                <div className="text-muted-foreground text-xs">{d.designStatuses[key as keyof typeof d.designStatuses] ?? key}</div>
                            </div>
                        ))}
                    </div>
                )}
            </SectionCard>

            <SectionCard title={d.connectionInfo} icon={Plug}>
                <dl className="space-y-2.5 text-sm">
                    <Row label={t.common.platform} value={t.admin.platforms[store.platform as 'zid'] ?? store.platform} />
                    <Row label={d.platformId} value={store.platform_store_id ?? '—'} ltr />
                    <Row label={d.syncFrequency} value={store.sync_frequency === 'weekly' ? t.admin.settings.weekly : t.admin.settings.daily} />
                    <Row label={d.lastSync} value={store.last_synced_at ? relative(store.last_synced_at) : t.common.never} />
                    <Row label={d.brandGuide} value={store.has_brand_guide ? d.configured : d.notConfigured} />
                    <Row label={t.admin.stores.created} value={date(store.created_at)} />
                </dl>
                <div className="mt-4 border-t pt-4">
                    <p className="text-muted-foreground mb-2 text-xs font-medium">{d.syncRuns}</p>
                    {overview.sync_runs.length === 0 ? (
                        <p className="text-muted-foreground text-xs">{d.noSyncRuns}</p>
                    ) : (
                        <ul className="space-y-1.5 text-xs">
                            {overview.sync_runs.map((run) => (
                                <li key={run.id} className="flex items-center justify-between gap-2">
                                    <span className="truncate">{run.type}</span>
                                    <span className={run.status === 'failed' ? 'text-danger' : run.status === 'completed' ? 'text-success' : 'text-muted-foreground'}>
                                        {run.status} · {dateTime(run.started_at)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </SectionCard>
        </div>
    );
}

function Row({ label, value, ltr = false }: { label: string; value: string; ltr?: boolean }) {
    return (
        <div className="flex items-center justify-between gap-3">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="truncate font-medium" dir={ltr ? 'ltr' : undefined}>
                {value}
            </dd>
        </div>
    );
}

function AiTab({ store, ai }: { store: StoreDetail; ai: Props['ai'] }) {
    const { t, usd, number, date, dateTime, compact } = useI18n();
    const d = t.admin.detail;
    const c = t.admin.costs;
    const axes = useChartAxes();

    return (
        <div className="space-y-6">
            <div className="grid gap-4 sm:grid-cols-3">
                <StatCard label={d.aiTotal30} value={ai.total_30d} format={(n) => usd(n)} icon={BadgeDollarSign} tone="success" />
                <StatCard label={d.aiCalls30} value={ai.calls_30d} icon={Cpu} tone="info" />
                <div className="bg-card rounded-2xl border border-border/70 p-5">
                    <p className="text-muted-foreground mb-3 text-sm">{d.budget}</p>
                    {store.ai_monthly_budget ? (
                        <Meter
                            value={store.budget_usage ?? 0}
                            label={usd(store.month_cost)}
                            valueLabel={`${number(store.budget_usage)}%`}
                            hint={`/ ${usd(store.ai_monthly_budget, 0)}`}
                            tone={(store.budget_usage ?? 0) >= 100 ? 'danger' : (store.budget_usage ?? 0) >= 80 ? 'warning' : 'navy'}
                        />
                    ) : (
                        <p className="text-lg font-semibold">{t.admin.overview.noBudget}</p>
                    )}
                </div>
            </div>

            <SectionCard title={d.dailyCost}>
                <div className="h-[220px]">
                    <ResponsiveContainer width="100%" height="100%">
                        <BarChart data={ai.daily} margin={{ top: 8, right: 4, left: 4, bottom: 0 }}>
                            <CartesianGrid {...axes.grid} />
                            <XAxis dataKey="date" {...axes.xAxis} minTickGap={24} tickFormatter={(v: string) => date(v, { day: 'numeric', month: 'short' })} />
                            <YAxis {...axes.yAxis} tickFormatter={(v: number) => `$${v.toFixed(2)}`} />
                            <Tooltip cursor={{ fill: 'var(--muted)', opacity: 0.5 }} content={<ChartTooltip formatter={(v) => usd(v)} labelFormatter={(l) => date(String(l))} />} />
                            <Bar dataKey="cost" name={c.totalCost} fill="var(--chart-2)" radius={[4, 4, 0, 0]} animationDuration={800} />
                        </BarChart>
                    </ResponsiveContainer>
                </div>
            </SectionCard>

            <div className="grid gap-6 lg:grid-cols-2">
                <SectionCard title={d.byOperation} flush>
                    <UsageTable
                        rows={ai.by_operation}
                        first={(r) => t.admin.operations[r.operation as keyof typeof t.admin.operations] ?? r.operation}
                        extra={(r) => (Number(r.failures) > 0 ? <span className="text-danger">{number(r.failures)}</span> : '—')}
                        extraLabel={c.failures}
                        firstLabel={t.admin.activity.action}
                    />
                </SectionCard>
                <SectionCard title={d.byModel} flush>
                    <UsageTable
                        rows={ai.by_model}
                        first={(r) => (
                            <span dir="ltr" className="font-mono text-xs">
                                {r.model}
                            </span>
                        )}
                        extra={(r) => `${compact(r.input_tokens)} / ${compact(r.output_tokens)}`}
                        extraLabel={`${c.tokensIn} / ${c.tokensOut}`}
                        firstLabel={c.model}
                    />
                </SectionCard>
            </div>

            <SectionCard title={d.recentCalls} flush>
                {ai.recent.length === 0 ? (
                    <EmptyState icon={Cpu} title={d.noCalls} className="py-8" />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t.admin.activity.time}</TableHead>
                                <TableHead>{t.admin.activity.action}</TableHead>
                                <TableHead>{c.model}</TableHead>
                                <TableHead>{t.admin.activity.actor}</TableHead>
                                <TableHead className="text-end">{c.tokensIn}</TableHead>
                                <TableHead className="text-end">{c.tokensOut}</TableHead>
                                <TableHead className="text-end">{t.common.total}</TableHead>
                                <TableHead>{t.common.status}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {ai.recent.map((call) => (
                                <TableRow key={call.id}>
                                    <TableCell className="text-muted-foreground text-xs">{dateTime(call.created_at)}</TableCell>
                                    <TableCell>{t.admin.operations[call.operation as keyof typeof t.admin.operations] ?? call.operation}</TableCell>
                                    <TableCell dir="ltr" className="font-mono text-xs">
                                        {call.model}
                                    </TableCell>
                                    <TableCell className="text-xs">{call.user?.name ?? '—'}</TableCell>
                                    <TableCell className="num text-end text-xs">{number(call.input_tokens)}</TableCell>
                                    <TableCell className="num text-end text-xs">{number(call.output_tokens)}</TableCell>
                                    <TableCell className="num text-end font-medium">{usd(call.cost, 4)}</TableCell>
                                    <TableCell>
                                        {call.success ? (
                                            <span className="text-success text-xs">{t.common.success}</span>
                                        ) : (
                                            <span className="text-danger block max-w-56 truncate text-xs" title={call.error ?? ''}>
                                                {call.error ?? t.common.error}
                                            </span>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </SectionCard>
        </div>
    );
}

function UsageTable({
    rows,
    first,
    firstLabel,
    extra,
    extraLabel,
}: {
    rows: UsageRow[];
    first: (r: UsageRow) => React.ReactNode;
    firstLabel: string;
    extra: (r: UsageRow) => React.ReactNode;
    extraLabel: string;
}) {
    const { t, usd, number } = useI18n();

    if (rows.length === 0) {
        return <p className="text-muted-foreground px-5 pb-5 text-sm">{t.admin.detail.noCalls}</p>;
    }

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>{firstLabel}</TableHead>
                    <TableHead className="text-end">{t.admin.costs.calls}</TableHead>
                    <TableHead className="text-end">{extraLabel}</TableHead>
                    <TableHead className="text-end">{t.admin.costs.totalCost}</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {rows.map((r, i) => (
                    <TableRow key={i}>
                        <TableCell>{first(r)}</TableCell>
                        <TableCell className="num text-end">{number(r.calls)}</TableCell>
                        <TableCell className="num text-end text-xs">{extra(r)}</TableCell>
                        <TableCell className="num text-end font-medium">{usd(r.cost)}</TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}

function MembersTab({ members }: { members: Member[] }) {
    const { t, tr, relative } = useI18n();
    const u = t.admin.users;
    const [target, setTarget] = useState<{ member: Member; action: 'disable' | 'enable' } | null>(null);

    return (
        <SectionCard title={t.admin.detail.membersTitle}>
            {members.length === 0 ? (
                <EmptyState icon={Users} title={u.empty} className="py-8" />
            ) : (
                <ul className="divide-border/60 divide-y">
                    {members.map((m) => (
                        <li key={m.id} className="flex flex-col gap-3 py-3.5 first:pt-0 last:pb-0 sm:flex-row sm:items-center">
                            <div className="flex min-w-0 flex-1 items-center gap-3">
                                <Initials name={m.name} />
                                <div className="min-w-0">
                                    <p className="flex flex-wrap items-center gap-2 font-medium">
                                        {m.name}
                                        {m.is_owner && <span className="bg-gold-soft text-gold-foreground dark:text-gold rounded-md px-1.5 text-[11px]">{t.admin.detail.ownerBadge}</span>}
                                    </p>
                                    <p className="text-muted-foreground truncate text-xs" dir="ltr">
                                        {m.email}
                                        {m.phone ? ` · ${m.phone}` : ''}
                                    </p>
                                </div>
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                <RoleBadge role={m.role} />
                                <UserStatusBadge status={m.status} />
                                <span className="text-muted-foreground text-xs">
                                    {t.admin.detail.lastLogin}: {m.last_login_at ? relative(m.last_login_at) : t.common.never}
                                </span>
                                {m.status === 'disabled' ? (
                                    <Button size="sm" variant="outline" onClick={() => setTarget({ member: m, action: 'enable' })}>
                                        {u.enable}
                                    </Button>
                                ) : (
                                    <Button size="sm" variant="outline" className="text-danger hover:text-danger" onClick={() => setTarget({ member: m, action: 'disable' })}>
                                        {u.disable}
                                    </Button>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            <ReasonDialog
                open={target?.action === 'disable'}
                onOpenChange={(open) => !open && setTarget(null)}
                title={tr(u.disableTitle, { name: target?.member.name ?? '' })}
                description={u.disableDescription}
                label={u.disableReason}
                confirmLabel={u.disable}
                onConfirm={(reason, done) =>
                    router.post(`/admin/users/${target?.member.id}/disable`, { reason }, { preserveScroll: true, onFinish: done, onSuccess: () => setTarget(null) })
                }
            />
            <ConfirmModal
                open={target?.action === 'enable'}
                onOpenChange={(open) => !open && setTarget(null)}
                title={tr(u.enableTitle, { name: target?.member.name ?? '' })}
                description={u.enableDescription}
                confirmLabel={u.enable}
                onConfirm={(done) => router.post(`/admin/users/${target?.member.id}/enable`, {}, { preserveScroll: true, onFinish: done, onSuccess: () => setTarget(null) })}
            />
        </SectionCard>
    );
}

function SettingsTab({ store }: { store: StoreDetail }) {
    const { t, tr } = useI18n();
    const d = t.admin.detail;

    const form = useForm({
        name: store.name,
        domain: store.domain ?? '',
        category: store.category ?? '',
        sync_frequency: store.sync_frequency,
        ai_monthly_budget: store.ai_monthly_budget !== null ? String(store.ai_monthly_budget) : '',
        accept_threshold: store.settings.accept_threshold,
        reject_threshold: store.settings.reject_threshold,
        proven_ratio: store.settings.proven_ratio,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(`/admin/stores/${store.id}`, { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <p className="text-muted-foreground text-sm">{d.settingsHint}</p>
            <div className="grid gap-6 lg:grid-cols-2">
                <SectionCard title={d.basics} icon={Store}>
                    <div className="grid gap-5">
                        <Field label={t.admin.create.storeName} error={form.errors.name}>
                            <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                        </Field>
                        <Field label={t.admin.create.domain} error={form.errors.domain}>
                            <Input dir="ltr" value={form.data.domain} onChange={(e) => form.setData('domain', e.target.value)} />
                        </Field>
                        <Field label={t.admin.create.category} error={form.errors.category}>
                            <Input value={form.data.category} onChange={(e) => form.setData('category', e.target.value)} />
                        </Field>
                        <div className="grid gap-5 sm:grid-cols-2">
                            <Field label={d.syncFrequency} error={form.errors.sync_frequency}>
                                <Select value={form.data.sync_frequency} onValueChange={(v) => form.setData('sync_frequency', v as 'daily' | 'weekly')}>
                                    <SelectTrigger className="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="daily">{t.admin.settings.daily}</SelectItem>
                                        <SelectItem value="weekly">{t.admin.settings.weekly}</SelectItem>
                                    </SelectContent>
                                </Select>
                            </Field>
                            <Field label={t.admin.create.budget} error={form.errors.ai_monthly_budget}>
                                <Input
                                    type="number"
                                    min={0}
                                    step="0.01"
                                    dir="ltr"
                                    value={form.data.ai_monthly_budget}
                                    onChange={(e) => form.setData('ai_monthly_budget', e.target.value)}
                                    placeholder={t.admin.overview.noBudget}
                                />
                            </Field>
                        </div>
                    </div>
                </SectionCard>

                <SectionCard title={d.decision} icon={ScanSearch}>
                    <div className="space-y-7">
                        <SliderField
                            label={d.acceptThreshold}
                            value={form.data.accept_threshold}
                            min={50}
                            max={100}
                            onChange={(v) => form.setData('accept_threshold', v)}
                            error={form.errors.accept_threshold}
                            tone="text-success"
                        />
                        <SliderField
                            label={d.rejectThreshold}
                            value={form.data.reject_threshold}
                            min={0}
                            max={90}
                            onChange={(v) => form.setData('reject_threshold', v)}
                            error={form.errors.reject_threshold}
                            tone="text-danger"
                        />
                        <SliderField
                            label={d.provenRatio}
                            value={form.data.proven_ratio}
                            min={50}
                            max={95}
                            onChange={(v) => form.setData('proven_ratio', v)}
                            error={form.errors.proven_ratio}
                            suffix="%"
                            hint={tr(d.provenRatioHint, { proven: form.data.proven_ratio, novel: 100 - form.data.proven_ratio })}
                        />
                    </div>
                </SectionCard>
            </div>

            <div className="flex justify-end">
                <Button type="submit" disabled={form.processing || !form.isDirty}>
                    {form.processing && <Spinner />}
                    {t.common.saveChanges}
                </Button>
            </div>
        </form>
    );
}

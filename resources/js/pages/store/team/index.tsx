import { Link, router } from '@inertiajs/react';
import {
    ArrowDown,
    FileDown,
    KeyRound,
    MoreHorizontal,
    Package,
    PackageCheck,
    PackageX,
    Pencil,
    Plus,
    Search,
    ShoppingBag,
    Table2,
    Trash2,
    Trophy,
    UserCheck,
    UserX,
    UsersRound,
    Wallet,
    X,
} from 'lucide-react';
import { MotionConfig } from 'motion/react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Initials, UserStatusBadge } from '@/components/admin/badges';
import { FilterSelect } from '@/components/admin/filter-select';
import { ConfirmModal } from '@/components/admin/store-actions';
import { EmptyState } from '@/components/mojo/empty-state';
import { Meter } from '@/components/mojo/meter';
import { FadeIn, Stagger, StaggerItem } from '@/components/mojo/motion';
import { PageBody, PageHeader } from '@/components/mojo/page-header';
import { SectionCard } from '@/components/mojo/section-card';
import { StatCard } from '@/components/mojo/stat-card';
import { MemberFormDialog, MemberPasswordDialog, RemoveMemberDialog } from '@/components/store/team/member-dialogs';
import { MonthlyMatrix, PeriodPicker } from '@/components/store/team/shared';
import type { MatrixRow, MemberFigures, Period, Range, TeamMember } from '@/components/store/team/shared';
import { MemberFocus, TeamLeaderboard } from '@/components/store/team/team-report';
import type { BoardMetric } from '@/components/store/team/team-report';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useI18n } from '@/hooks/use-i18n';
import { useRevenueVisible } from '@/hooks/use-revenue-visible';
import { STORE_SECTIONS } from '@/lib/store-sections';
import { cn } from '@/lib/utils';

type Props = {
    months: Range;
    month: string | null;
    availableMonths: string[];
    sections: Record<string, string[]>;
    report: {
        months: string[];
        length: number;
        members: TeamMember[];
        unassigned: MemberFigures;
        totals: { products: number; assigned: number; units: number; orders: number; revenue: number | null };
    };
};

type SortKey = 'units' | 'products' | 'avg_per_product' | 'avg_per_month' | 'orders' | 'revenue';
type DialogState = { kind: 'create' } | { kind: 'edit' | 'password' | 'disable' | 'enable' | 'remove'; member: TeamMember } | null;

export default function Team({ months, month, availableMonths, sections, report }: Props) {
    const { t, tr, number, money, percent } = useI18n();
    const m = t.team;
    const showRevenue = useRevenueVisible();
    const [dialog, setDialog] = useState<DialogState>(null);
    const [sort, setSort] = useState<SortKey>('units');
    const [metric, setMetric] = useState<'units' | 'revenue'>('units');
    const [boardMetric, setBoardMetric] = useState<BoardMetric>('units');
    // The member the report is about: picked on the ranking or the table,
    // previewed while the pointer rests on a ranking row.
    const [picked, setPicked] = useState<number | null>(null);
    const [preview, setPreview] = useState<number | null>(null);
    // Filters narrow who is compared; the team's own totals stay whole.
    const [search, setSearch] = useState('');
    const [level, setLevel] = useState<'above' | 'below' | 'none' | null>(null);
    const [status, setStatus] = useState<'active' | 'disabled' | null>(null);
    const close = () => setDialog(null);
    const target = dialog && 'member' in dialog ? dialog.member : null;
    const totalPages = Object.values(sections).flat().length;

    const setPeriod = (next: Period) =>
        router.get(
            '/store/team',
            { months: next.months, ...(next.month ? { month: next.month } : {}) },
            { preserveState: true, preserveScroll: true, replace: true, only: ['report', 'months', 'month'] },
        );

    const teamUnits = report.members.reduce((sum, member) => sum + member.units, 0);
    const teamAverage = teamUnits / Math.max(1, report.members.length);
    const visible = useMemo(() => {
        const needle = search.trim().toLowerCase();

        return report.members.filter(
            (member) =>
                (!needle || member.name.toLowerCase().includes(needle) || member.email.toLowerCase().includes(needle)) &&
                (!status || member.status === status) &&
                (!level ||
                    (level === 'none' && member.units === 0) ||
                    (level === 'above' && member.units > 0 && member.units >= teamAverage) ||
                    (level === 'below' && member.units > 0 && member.units < teamAverage)),
        );
    }, [report.members, search, status, level, teamAverage]);
    const filtering = visible.length !== report.members.length;
    const clearFilters = () => {
        setSearch('');
        setLevel(null);
        setStatus(null);
    };

    const members = useMemo(
        () => [...visible].sort((a, b) => Number(b[sort] ?? 0) - Number(a[sort] ?? 0) || b.units - a.units || a.name.localeCompare(b.name)),
        [visible, sort],
    );
    const leader = report.members.find((member) => member.units > 0)?.id ?? null;
    const focusId = visible.some((member) => member.id === picked) ? picked : (visible[0]?.id ?? null);
    const focused = visible.find((member) => member.id === (preview ?? focusId)) ?? visible.find((member) => member.id === focusId) ?? null;
    const disabled = report.members.filter((member) => member.status === 'disabled').length;
    const exportUrl = `/store/team/export?${new URLSearchParams({
        months: String(months),
        ...(month ? { month } : {}),
        sort,
        ...(filtering ? { members: visible.map((member) => member.id).join(',') } : {}),
    }).toString()}`;
    const teamRevenue = report.members.reduce((sum, member) => sum + (member.revenue ?? 0), 0);
    const coverage = report.totals.products > 0 ? (report.totals.assigned / report.totals.products) * 100 : 0;

    const post = (url: string, done: () => void) =>
        router.post(url, {}, { preserveScroll: true, onSuccess: close, onError: (errors) => toast.error(Object.values(errors)[0]), onFinish: done });

    const matrix: MatrixRow[] = [
        ...members.map((member) => ({ key: String(member.id), label: member.name, monthly: member.monthly })),
        { key: 'unassigned', label: m.compare.unassignedRow, monthly: report.unassigned.monthly, tone: 'muted' as const },
        {
            key: 'total',
            label: m.compare.totalRow,
            tone: 'total' as const,
            monthly: report.unassigned.monthly.map((point, i) => ({
                period: point.period,
                units: point.units + report.members.reduce((sum, member) => sum + member.monthly[i].units, 0),
                revenue: (point.revenue ?? 0) + report.members.reduce((sum, member) => sum + (member.monthly[i].revenue ?? 0), 0),
            })),
        },
    ];

    const header = (key: SortKey, label: string) => (
        <TableHead className="text-end">
            <button
                type="button"
                onClick={() => setSort(key)}
                aria-pressed={sort === key}
                className={cn('hover:text-foreground inline-flex cursor-pointer items-center gap-1', sort === key && 'text-foreground font-semibold')}
            >
                {label}
                <ArrowDown className={cn('size-3', sort === key ? 'opacity-100' : 'opacity-0')} />
            </button>
        </TableHead>
    );

    return (
        <MotionConfig reducedMotion="user">
        <PageBody>
            <PageHeader
                title={m.title}
                description={m.subtitle}
                icon={UsersRound}
                actions={
                    <>
                        <PeriodPicker period={{ months, month }} available={availableMonths} onChange={setPeriod} />
                        <Button onClick={() => setDialog({ kind: 'create' })}>
                            <Plus className="size-4" />
                            {m.addMember}
                        </Button>
                    </>
                }
            />

            {report.members.length === 0 ? (
                <SectionCard>
                    <EmptyState
                        icon={UsersRound}
                        title={m.empty.title}
                        description={m.empty.description}
                        action={
                            <Button onClick={() => setDialog({ kind: 'create' })}>
                                <Plus className="size-4" />
                                {m.addMember}
                            </Button>
                        }
                    />
                </SectionCard>
            ) : (
                <>
                    <Stagger className={cn('grid gap-4 sm:grid-cols-2', showRevenue ? 'xl:grid-cols-5' : 'xl:grid-cols-4')}>
                        <StaggerItem>
                            <StatCard
                                label={m.kpi.members}
                                value={report.members.length}
                                format={(n) => number(n)}
                                icon={UsersRound}
                                tone="navy"
                                hint={disabled > 0 ? tr(m.kpi.membersHint, { count: number(disabled) }) : undefined}
                            />
                        </StaggerItem>
                        <StaggerItem>
                            <StatCard
                                label={m.kpi.covered}
                                value={report.totals.assigned}
                                format={(n) => number(n)}
                                icon={PackageCheck}
                                tone="success"
                                hint={tr(m.kpi.coveredHint, { total: number(report.totals.products) })}
                            >
                                <Meter value={coverage} tone="success" height={5} className="mt-3" />
                            </StatCard>
                        </StaggerItem>
                        <StaggerItem>
                            <StatCard
                                label={m.kpi.unassigned}
                                value={report.unassigned.products}
                                format={(n) => number(n)}
                                icon={PackageX}
                                tone={report.unassigned.products > 0 ? 'warning' : 'neutral'}
                                hint={tr(m.kpi.unassignedHint, { units: number(report.unassigned.units) })}
                                href="/store/catalog?member=none"
                            />
                        </StaggerItem>
                        <StaggerItem>
                            <StatCard
                                label={m.kpi.units}
                                value={teamUnits}
                                format={(n) => number(n)}
                                icon={ShoppingBag}
                                tone="info"
                                hint={tr(m.kpi.unitsHint, { share: percent(report.totals.units > 0 ? (teamUnits / report.totals.units) * 100 : 0) })}
                            />
                        </StaggerItem>
                        {showRevenue && (
                            <StaggerItem>
                                <StatCard label={m.kpi.revenue} value={teamRevenue} format={(n) => money(n)} icon={Wallet} tone="gold" />
                            </StaggerItem>
                        )}
                    </Stagger>

                    <FadeIn delay={0.03} className="flex flex-col gap-2 lg:flex-row lg:items-center">
                        <div className="relative lg:w-80">
                            <Search className="text-muted-foreground pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2" />
                            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={m.filters.search} className="h-9 ps-9" />
                        </div>
                        <FilterSelect
                            value={level}
                            onChange={(v) => setLevel(v as typeof level)}
                            allLabel={m.filters.level.all}
                            options={(['above', 'below', 'none'] as const).map((key) => ({ value: key, label: m.filters.level[key] }))}
                        />
                        <FilterSelect
                            value={status}
                            onChange={(v) => setStatus(v as typeof status)}
                            allLabel={m.filters.status.all}
                            options={(['active', 'disabled'] as const).map((key) => ({ value: key, label: m.filters.status[key] }))}
                        />
                        {filtering && (
                            <div className="text-muted-foreground flex items-center gap-1 text-xs">
                                <span className="num">{tr(m.filters.showing, { count: number(visible.length), total: number(report.members.length) })}</span>
                                <Button variant="ghost" size="sm" className="h-8" onClick={clearFilters}>
                                    <X className="size-3.5" />
                                    {m.filters.clear}
                                </Button>
                            </div>
                        )}
                        {/* The export carries the period and whoever the filters left. */}
                        {visible.length > 0 && (
                            <Button asChild variant="outline" size="sm" className="h-9 lg:ms-auto">
                                <a href={exportUrl} target="_blank" rel="noopener">
                                    <FileDown className="size-4" />
                                    {t.common.exportPdf}
                                </a>
                            </Button>
                        )}
                    </FadeIn>

                    {visible.length === 0 ? (
                        <SectionCard>
                            <EmptyState
                                icon={Search}
                                title={m.filters.empty}
                                description={m.filters.emptyHint}
                                action={
                                    <Button variant="outline" onClick={clearFilters}>
                                        {m.filters.clear}
                                    </Button>
                                }
                            />
                        </SectionCard>
                    ) : (
                    <>
                    <div className="grid gap-6 xl:grid-cols-5">
                        <FadeIn delay={0.05} className="xl:col-span-2">
                            <TeamLeaderboard
                                members={visible}
                                metric={showRevenue || boardMetric !== 'revenue' ? boardMetric : 'units'}
                                onMetric={setBoardMetric}
                                focus={focusId}
                                onFocus={setPicked}
                                onPreview={setPreview}
                            />
                        </FadeIn>
                        {focused && (
                            <FadeIn delay={0.1} className="xl:col-span-3">
                                <MemberFocus
                                    member={focused}
                                    members={report.members}
                                    months={report.months}
                                    totalPages={totalPages}
                                    accessLine={(granted, total) => <AccessLine granted={granted} total={total} />}
                                    actions={<MemberMenu member={focused} onPick={(kind) => setDialog({ kind, member: focused })} />}
                                />
                            </FadeIn>
                        )}
                    </div>

                    <FadeIn delay={0.05}>
                        <SectionCard title={m.compare.title} description={m.compare.hint} icon={Table2} flush bodyClassName="pb-1">
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="w-10 text-center">{m.compare.rank}</TableHead>
                                            <TableHead>{m.compare.member}</TableHead>
                                            {header('products', m.compare.products)}
                                            {header('units', m.compare.units)}
                                            {header('avg_per_product', m.compare.avgPerProduct)}
                                            {header('avg_per_month', m.compare.avgPerMonth)}
                                            {header('orders', m.compare.orders)}
                                            {showRevenue && header('revenue', m.compare.revenue)}
                                            <TableHead className="min-w-40">{m.compare.share}</TableHead>
                                            <TableHead className="min-w-44">{m.compare.best}</TableHead>
                                            <TableHead className="w-12" />
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {members.map((member, index) => (
                                            <TableRow
                                                key={member.id}
                                                onClick={() => setPicked(member.id)}
                                                data-state={member.id === focusId ? 'selected' : undefined}
                                                className={cn('cursor-pointer', member.status === 'disabled' && 'opacity-70')}
                                            >
                                                <TableCell className="text-center">
                                                    {member.id === leader ? (
                                                        <Tooltip>
                                                            <TooltipTrigger asChild>
                                                                <span className="bg-gold-soft text-gold-foreground dark:text-gold inline-flex size-7 items-center justify-center rounded-full">
                                                                    <Trophy className="size-3.5" />
                                                                </span>
                                                            </TooltipTrigger>
                                                            <TooltipContent>{m.compare.top}</TooltipContent>
                                                        </Tooltip>
                                                    ) : (
                                                        <span className="num text-muted-foreground">{index + 1}</span>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <Link href={`/store/team/${member.id}`} prefetch className="group flex items-center gap-3">
                                                        <Initials name={member.name} />
                                                        <span className="min-w-0">
                                                            <span className="flex items-center gap-2 font-medium">
                                                                <span className="max-w-44 truncate group-hover:underline" dir="auto">
                                                                    {member.name}
                                                                </span>
                                                                {member.status === 'disabled' && <UserStatusBadge status={member.status} />}
                                                            </span>
                                                            <AccessLine granted={member.permissions} total={totalPages} />
                                                        </span>
                                                    </Link>
                                                </TableCell>
                                                <TableCell className="text-end">
                                                    <span className="num font-semibold">{number(member.products)}</span>
                                                    <span className="text-muted-foreground block text-xs">{tr(m.compare.selling, { count: number(member.selling) })}</span>
                                                </TableCell>
                                                <TableCell className="num text-end text-base font-semibold">{number(member.units)}</TableCell>
                                                <TableCell className="num text-end">{number(member.avg_per_product, 1)}</TableCell>
                                                <TableCell className="num text-end">{number(member.avg_per_month, 1)}</TableCell>
                                                <TableCell className="num text-end">{number(member.orders)}</TableCell>
                                                {showRevenue && <TableCell className="num text-end">{money(member.revenue)}</TableCell>}
                                                <TableCell>
                                                    <Meter value={member.share} tone={member.id === leader ? 'gold' : 'navy'} height={6} />
                                                    <span className="text-muted-foreground mt-1 flex flex-wrap gap-x-2 text-xs">
                                                        <span className="num">{percent(member.share, 1)}</span>
                                                        {member.winners > 0 && <span className="text-success">{tr(m.compare.winners, { count: number(member.winners) })}</span>}
                                                        {member.stagnant > 0 && <span className="text-danger">{tr(m.compare.stagnant, { count: number(member.stagnant) })}</span>}
                                                    </span>
                                                </TableCell>
                                                <TableCell>
                                                    {member.best ? (
                                                        <span className="block max-w-52">
                                                            <span className="block truncate text-sm" dir="auto">
                                                                {member.best.name}
                                                            </span>
                                                            <span className="num text-muted-foreground text-xs">{tr(m.compare.bestUnits, { units: number(member.best.units) })}</span>
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted-foreground text-xs">{m.compare.noBest}</span>
                                                    )}
                                                </TableCell>
                                                <TableCell onClick={(event) => event.stopPropagation()}>
                                                    <MemberMenu member={member} onPick={(kind) => setDialog({ kind, member })} />
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                        <TableRow className="text-muted-foreground bg-muted/30 hover:bg-muted/30">
                                            <TableCell />
                                            <TableCell>
                                                <span className="flex items-center gap-3">
                                                    <span className="bg-muted flex size-9 items-center justify-center rounded-xl">
                                                        <Package className="size-4" />
                                                    </span>
                                                    {m.compare.unassignedRow}
                                                </span>
                                            </TableCell>
                                            <TableCell className="num text-end">{number(report.unassigned.products)}</TableCell>
                                            <TableCell className="num text-end">{number(report.unassigned.units)}</TableCell>
                                            <TableCell className="num text-end">{number(report.unassigned.avg_per_product, 1)}</TableCell>
                                            <TableCell className="num text-end">{number(report.unassigned.avg_per_month, 1)}</TableCell>
                                            <TableCell className="num text-end">{number(report.unassigned.orders)}</TableCell>
                                            {showRevenue && <TableCell className="num text-end">{money(report.unassigned.revenue)}</TableCell>}
                                            <TableCell>
                                                <Meter value={report.unassigned.share} tone="neutral" height={6} />
                                                <span className="num mt-1 block text-xs">{percent(report.unassigned.share, 1)}</span>
                                            </TableCell>
                                            <TableCell />
                                            <TableCell />
                                        </TableRow>
                                    </TableBody>
                                </Table>
                            </div>
                            <div className="text-muted-foreground space-y-1 border-t px-5 py-3 text-xs leading-relaxed">
                                <p>{m.compare.attribution}</p>
                                <p>{m.compare.ordersNote}</p>
                            </div>
                        </SectionCard>
                    </FadeIn>

                    <FadeIn delay={0.1}>
                        <SectionCard
                            title={m.monthly.title}
                            description={metric === 'revenue' ? m.monthly.hintRevenue : m.monthly.hint}
                            icon={ShoppingBag}
                            action={
                                showRevenue ? (
                                    <Tabs value={metric} onValueChange={(v) => setMetric(v as 'units' | 'revenue')}>
                                        <TabsList>
                                            <TabsTrigger value="units" className="px-3">
                                                {t.catalog.metrics.units}
                                            </TabsTrigger>
                                            <TabsTrigger value="revenue" className="px-3">
                                                {t.catalog.metrics.revenue}
                                            </TabsTrigger>
                                        </TabsList>
                                    </Tabs>
                                ) : undefined
                            }
                        >
                            <MonthlyMatrix rows={matrix} metric={showRevenue ? metric : 'units'} firstColumn={m.compare.member} selected={month} />
                        </SectionCard>
                    </FadeIn>
                    </>
                    )}
                </>
            )}

            <MemberFormDialog
                open={dialog?.kind === 'create' || dialog?.kind === 'edit'}
                onOpenChange={(open) => !open && close()}
                member={dialog?.kind === 'edit' ? dialog.member : null}
                sections={sections}
            />
            <MemberPasswordDialog open={dialog?.kind === 'password'} onOpenChange={(open) => !open && close()} member={target} />
            <ConfirmModal
                open={dialog?.kind === 'disable'}
                onOpenChange={(open) => !open && close()}
                title={tr(m.disable.title, { name: target?.name ?? '' })}
                description={m.disable.description}
                confirmLabel={m.actions.disable}
                destructive
                onConfirm={(done) => post(`/store/team/${target?.id}/disable`, done)}
            />
            <ConfirmModal
                open={dialog?.kind === 'enable'}
                onOpenChange={(open) => !open && close()}
                title={tr(m.enable.title, { name: target?.name ?? '' })}
                description={m.enable.description}
                confirmLabel={m.actions.enable}
                onConfirm={(done) => post(`/store/team/${target?.id}/enable`, done)}
            />
            <RemoveMemberDialog
                open={dialog?.kind === 'remove'}
                onOpenChange={(open) => !open && close()}
                member={dialog?.kind === 'remove' ? dialog.member : null}
                others={report.members.filter((member) => member.id !== target?.id)}
            />
        </PageBody>
        </MotionConfig>
    );
}

/** "6 of 15 pages", with the page names on hover. */
function AccessLine({ granted, total }: { granted: string[]; total: number }) {
    const { t, tr } = useI18n();
    const a = t.team.access;

    if (granted.length === 0) {
        return <span className="text-muted-foreground block text-xs">{a.none}</span>;
    }

    if (granted.length >= total) {
        return <span className="text-muted-foreground block text-xs">{a.all}</span>;
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span className="text-muted-foreground block w-fit text-xs underline decoration-dotted underline-offset-4">
                    {tr(a.some, { count: granted.length, total })}
                </span>
            </TooltipTrigger>
            <TooltipContent className="max-w-64">
                {granted.map((key) => (STORE_SECTIONS[key] ? t.nav[STORE_SECTIONS[key].label] : key)).join(' · ')}
            </TooltipContent>
        </Tooltip>
    );
}

function MemberMenu({ member, onPick }: { member: TeamMember; onPick: (kind: 'edit' | 'password' | 'disable' | 'enable' | 'remove') => void }) {
    const { t } = useI18n();
    const a = t.team.actions;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="size-8" aria-label={t.common.actions}>
                    <MoreHorizontal className="size-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-60">
                <DropdownMenuItem asChild>
                    <Link href={`/store/team/${member.id}`}>
                        <PackageCheck className="size-4" />
                        {a.products}
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem onSelect={() => onPick('edit')}>
                    <Pencil className="size-4" />
                    {a.edit}
                </DropdownMenuItem>
                <DropdownMenuItem onSelect={() => onPick('password')}>
                    <KeyRound className="size-4" />
                    {a.password}
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                {member.status === 'disabled' ? (
                    <DropdownMenuItem onSelect={() => onPick('enable')} className="text-success focus:text-success">
                        <UserCheck className="size-4" />
                        {a.enable}
                    </DropdownMenuItem>
                ) : (
                    <DropdownMenuItem onSelect={() => onPick('disable')} className="text-warning focus:text-warning">
                        <UserX className="size-4" />
                        {a.disable}
                    </DropdownMenuItem>
                )}
                <DropdownMenuItem onSelect={() => onPick('remove')} className="text-danger focus:text-danger">
                    <Trash2 className="size-4" />
                    {a.remove}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

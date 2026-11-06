import { Link, router, useForm, usePoll } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeftRight,
    CheckCircle2,
    ClipboardCopy,
    Download,
    FileJson,
    History,
    Info,
    ListChecks,
    Loader2,
    PlugZap,
    Scale,
    SearchX,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { FadeIn } from '@/components/mojo/motion';
import { SectionCard } from '@/components/mojo/section-card';
import { toneClasses } from '@/components/mojo/tone';
import type { Tone } from '@/components/mojo/tone';
import { SettingsShell } from '@/components/store/settings-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useClipboard } from '@/hooks/use-clipboard';
import { useI18n } from '@/hooks/use-i18n';
import type { Dictionary } from '@/i18n';
import { cn } from '@/lib/utils';

type Strings = Dictionary['revenueAudit'];
type FindingCode = keyof Strings['findings'];
type Status = 'queued' | 'running' | 'completed' | 'failed';

type Group = Record<string, { orders: number; order_total: number; lines: number }>;

type Report = {
    month: string;
    expected: number | null;
    generated_at: string;
    duration_s: number;
    ours: {
        sales: { revenue: number; rows: number; order_lines: number };
        stored_orders: { orders: number; revenue: number };
    };
    zid: {
        read: { complete: boolean; orders: number; pages: number; stopped: { reason: string; message?: string } | null };
        month_orders: number;
        counted_orders: number;
        totals: Record<'order_total_all' | 'order_total_counted' | 'order_total_not_returned' | 'lines_counted' | 'lines_kept' | 'our_method', number>;
        groups: { status: Group };
        unmatched: {
            samples: {
                order: string;
                id: string;
                parent_id: string | null;
                sku: string | null;
                name: string;
                units: number;
                revenue: number;
                would_match_by: 'parent' | 'sku' | null;
            }[];
        };
        reconciliation: {
            stored_for_month: number;
            zid_counted_for_month: number;
            missing_from_ours: { count: number };
            extra_in_ours: { count: number };
            changed_since_import: { lines_revenue: number };
        };
    };
    candidates: { rows: { key: string; value: number; diff_from_expected: number | null }[]; closest_to_expected: string | null };
    findings: { code: FindingCode; amount: number | null; count: number | null }[];
};

type Run = { id: number; status: Status; error: string | null; month: string | null; expected: number | null; created_at: string | null; finished_at: string | null };
type Audit = Run & { progress: { pages: number; orders: number }; report: Report | null };

type Props = { connected: boolean; months: string[]; audit: Audit | null; history: Run[] };

const AUDIT_URL = '/store/settings/integration/revenue-audit';

const isActive = (status: Status | undefined) => status === 'queued' || status === 'running';

const FINDING_TONES: Record<FindingCode, Tone> = {
    incomplete_read: 'danger',
    stale_import: 'danger',
    missing_orders: 'danger',
    extra_orders: 'warning',
    non_zid_rows: 'danger',
    unmatched_lines: 'danger',
    variant_lines: 'warning',
    order_level_amounts: 'info',
    returns_excluded: 'info',
    cancelled_excluded: 'neutral',
    timezone_shift: 'warning',
    currency: 'warning',
    orders_are_lines: 'neutral',
};

export default function RevenueAudit({ connected, months, audit, history }: Props) {
    const { t } = useI18n();
    const s = t.revenueAudit;
    const active = isActive(audit?.status);

    return (
        <SettingsShell
            actions={
                <Button variant="outline" asChild>
                    <Link href="/store/settings/integration" prefetch>
                        <PlugZap />
                        {s.back}
                    </Link>
                </Button>
            }
        >
            {active && <Poller />}

            <FadeIn>
                <AuditForm connected={connected} months={months} active={active} />
            </FadeIn>

            {audit && active && <ProgressCard audit={audit} />}
            {audit?.status === 'failed' && <FailedCard audit={audit} />}
            {audit?.report && <ResultView report={audit.report} />}

            {history.length > 0 && (
                <FadeIn delay={0.1}>
                    <HistoryCard history={history} current={audit?.id ?? null} />
                </FadeIn>
            )}
        </SettingsShell>
    );
}

function Poller() {
    usePoll(3000, { only: ['audit', 'history'] });

    return null;
}

function AuditForm({ connected, months, active }: { connected: boolean; months: string[]; active: boolean }) {
    const { t, date } = useI18n();
    const s = t.revenueAudit;
    const form = useForm<{ month: string; expected: string }>({ month: months[0] ?? '', expected: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({ month: data.month, expected: data.expected.trim() === '' ? null : data.expected }));
        form.post(AUDIT_URL, { preserveScroll: true });
    };

    return (
        <SectionCard title={s.title} description={s.description} icon={Scale}>
            {!connected && (
                <div className="bg-warning-soft text-warning mb-4 flex gap-2 rounded-xl p-3 text-xs leading-relaxed">
                    <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                    {s.notConnected}
                </div>
            )}
            <form onSubmit={submit} className="grid gap-4 md:grid-cols-[minmax(0,12rem)_minmax(0,1fr)_auto] md:items-end">
                <div className="space-y-1.5">
                    <Label htmlFor="audit-month">{s.month}</Label>
                    <Select value={form.data.month} onValueChange={(v) => form.setData('month', v)}>
                        <SelectTrigger id="audit-month" className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {months.map((m) => (
                                <SelectItem key={m} value={m}>
                                    {date(`${m}-01T00:00:00`, { month: 'long', year: 'numeric' })}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={form.errors.month} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="audit-expected">{s.expected}</Label>
                    <Input
                        id="audit-expected"
                        type="number"
                        inputMode="decimal"
                        min={0}
                        step="0.01"
                        dir="ltr"
                        className="num"
                        value={form.data.expected}
                        onChange={(e) => form.setData('expected', e.target.value)}
                        placeholder="0.00"
                    />
                    <InputError message={form.errors.expected} />
                </div>
                <Button type="submit" disabled={!connected || active || form.processing}>
                    {form.processing || active ? <Loader2 className="animate-spin" /> : <ListChecks />}
                    {s.run}
                </Button>
            </form>
            <p className="text-muted-foreground mt-3 text-xs leading-relaxed">
                {s.expectedHint} {s.formHint}
            </p>
        </SectionCard>
    );
}

function ProgressCard({ audit }: { audit: Audit }) {
    const { t, tr, number } = useI18n();
    const s = t.revenueAudit;

    return (
        <div className="bg-info-soft text-info flex items-center gap-3 rounded-2xl p-4 text-sm" role="status">
            <Loader2 className="size-5 shrink-0 animate-spin" />
            <div className="min-w-0">
                <p className="font-medium">{audit.status === 'queued' ? s.queued : s.running}</p>
                {audit.status === 'running' && (
                    <p className="num text-xs opacity-80">{tr(s.progress, { pages: number(audit.progress.pages), orders: number(audit.progress.orders) })}</p>
                )}
            </div>
        </div>
    );
}

function FailedCard({ audit }: { audit: Audit }) {
    const { t } = useI18n();
    const s = t.revenueAudit;
    const known = audit.error && audit.error in s.errors ? s.errors[audit.error as keyof Strings['errors']] : null;

    return (
        <div className="bg-danger-soft text-danger flex gap-3 rounded-2xl p-4 text-sm" role="alert">
            <XCircle className="mt-0.5 size-5 shrink-0" />
            <div className="min-w-0 space-y-1">
                <p className="font-medium">{s.failed}</p>
                {audit.error && <p className="text-xs break-words opacity-90">{known ?? audit.error}</p>}
            </div>
        </div>
    );
}

function ResultView({ report }: { report: Report }) {
    const { t, tr, money, number, date, dateTime } = useI18n();
    const s = t.revenueAudit;
    const totals = report.zid.totals;
    const ours = report.ours.sales.revenue;
    const label = useCandidateLabel();

    const tiles: { label: string; hint: string; value: number; tone: Tone; foot?: string }[] = [
        { label: s.ours, hint: s.oursHint, value: ours, tone: 'navy', foot: tr(s.gapFromZid, { amount: money(ours - totals.order_total_counted, 2) }) },
        { label: s.zidOrders, hint: s.zidOrdersHint, value: totals.order_total_counted, tone: 'gold', foot: tr(s.ordersCount, { n: number(report.zid.counted_orders) }) },
        { label: s.zidLines, hint: s.zidLinesHint, value: totals.our_method, tone: Math.abs(totals.our_method - ours) < 1 ? 'success' : 'danger' },
    ];
    if (report.expected !== null) {
        tiles.push({ label: s.yours, hint: s.yoursHint, value: report.expected, tone: 'neutral' });
    }

    return (
        <>
            <FadeIn delay={0.04}>
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 className="text-lg font-semibold">{tr(s.resultTitle, { month: date(`${report.month}-01T00:00:00`, { month: 'long', year: 'numeric' }) })}</h2>
                    <p className="text-muted-foreground text-xs">{tr(s.generatedAt, { date: dateTime(report.generated_at), seconds: number(report.duration_s, 1) })}</p>
                </div>
            </FadeIn>

            {!report.zid.read.complete && (
                <div className="bg-warning-soft text-warning flex gap-2 rounded-xl p-3 text-xs leading-relaxed">
                    <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                    <span>
                        {s.incomplete}
                        {report.zid.read.stopped?.message && <span className="mt-1 block break-words opacity-80" dir="ltr">{report.zid.read.stopped.message}</span>}
                    </span>
                </div>
            )}

            <FadeIn delay={0.06}>
                <div className={cn('grid gap-4 sm:grid-cols-2', tiles.length > 3 ? 'xl:grid-cols-4' : 'xl:grid-cols-3')}>
                    {tiles.map((tile) => (
                        <div key={tile.label} className="bg-card rounded-2xl border border-border/70 p-5 shadow-[0_1px_2px_rgba(16,24,40,0.04)]">
                            <div className="flex items-center gap-2">
                                <span className={cn('size-2 rounded-full', toneClasses[tile.tone].bg)} />
                                <p className="text-sm font-medium">{tile.label}</p>
                            </div>
                            <p className="num mt-3 text-2xl font-semibold tracking-tight">{money(tile.value, 2)}</p>
                            <p className="text-muted-foreground mt-1 text-xs leading-relaxed">{tile.hint}</p>
                            {tile.foot && <p className="num text-muted-foreground mt-2 border-t border-border/60 pt-2 text-xs">{tile.foot}</p>}
                        </div>
                    ))}
                </div>
            </FadeIn>

            {report.candidates.closest_to_expected && (
                <div className="bg-primary/8 text-primary dark:bg-primary/15 flex gap-2 rounded-xl p-3 text-sm">
                    <CheckCircle2 className="mt-0.5 size-4 shrink-0" />
                    {tr(s.closest, { name: label(report.candidates.closest_to_expected) })}
                </div>
            )}

            <div className="grid gap-6 xl:grid-cols-5">
                <FadeIn delay={0.08} className="xl:col-span-3">
                    <FindingsCard findings={report.findings} />
                </FadeIn>
                <FadeIn delay={0.1} className="xl:col-span-2">
                    <ReconcileCard report={report} />
                </FadeIn>
            </div>

            <div className="grid gap-6 xl:grid-cols-2">
                <FadeIn delay={0.1}>
                    <CandidatesCard report={report} />
                </FadeIn>
                <FadeIn delay={0.12}>
                    <StatusesCard groups={report.zid.groups.status} />
                </FadeIn>
            </div>

            {report.zid.unmatched.samples.length > 0 && (
                <FadeIn delay={0.12}>
                    <UnmatchedCard samples={report.zid.unmatched.samples} />
                </FadeIn>
            )}

            <FadeIn delay={0.14}>
                <ReportCard report={report} />
            </FadeIn>
        </>
    );
}

function useCandidateLabel() {
    const { t, tr } = useI18n();
    const s = t.revenueAudit;

    return (key: string) => {
        if (key in s.candidates) {
            return s.candidates[key as keyof Strings['candidates']];
        }
        const match = /^zid_list_(utc|riyadh)_(.+)$/.exec(key);

        return match ? tr(s.listField, { window: s.windows[match[1] as keyof Strings['windows']], field: match[2] }) : key;
    };
}

function FindingsCard({ findings }: { findings: Report['findings'] }) {
    const { t, tr, money, number } = useI18n();
    const s = t.revenueAudit;

    return (
        <SectionCard title={s.findingsTitle} description={s.findingsHint} icon={Info} className="h-full">
            {findings.length === 0 ? (
                <div className="bg-success-soft text-success flex gap-2 rounded-xl p-3 text-sm">
                    <CheckCircle2 className="mt-0.5 size-4 shrink-0" />
                    {s.noFindings}
                </div>
            ) : (
                <ul className="space-y-2.5">
                    {findings.map((finding, i) => {
                        const text = s.findings[finding.code];
                        const tone = toneClasses[FINDING_TONES[finding.code] ?? 'neutral'];
                        if (!text) {
                            return null;
                        }

                        return (
                            <li key={`${finding.code}-${i}`} className="flex gap-3 rounded-xl border border-border/60 p-3">
                                <span className={cn('mt-1.5 size-2 shrink-0 rounded-full', tone.bg)} />
                                <div className="min-w-0 flex-1 space-y-0.5">
                                    <div className="flex flex-wrap items-baseline justify-between gap-x-3">
                                        <p className="text-sm font-medium">{text.title}</p>
                                        {finding.amount !== null && <span className={cn('num text-sm font-semibold', tone.text)}>{money(finding.amount, 2)}</span>}
                                    </div>
                                    <p className="text-muted-foreground text-xs leading-relaxed">
                                        {tr(text.body, {
                                            amount: finding.amount !== null ? money(finding.amount, 2) : '—',
                                            count: finding.count !== null ? number(finding.count) : '—',
                                        })}
                                    </p>
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}
        </SectionCard>
    );
}

function ReconcileCard({ report }: { report: Report }) {
    const { t, number, money } = useI18n();
    const s = t.revenueAudit;
    const r = report.zid.reconciliation;
    const items: { label: string; value: string; tone?: Tone }[] = [
        { label: s.storedForMonth, value: number(r.stored_for_month) },
        { label: s.zidForMonth, value: number(r.zid_counted_for_month) },
        { label: s.missing, value: number(r.missing_from_ours.count), tone: r.missing_from_ours.count > 0 ? 'danger' : 'success' },
        { label: s.extra, value: number(r.extra_in_ours.count), tone: r.extra_in_ours.count > 0 ? 'warning' : 'success' },
        { label: s.changed, value: number(r.changed_since_import.lines_revenue), tone: r.changed_since_import.lines_revenue > 0 ? 'warning' : 'success' },
        { label: s.candidates.ours_stored_orders, value: money(report.ours.stored_orders.revenue, 2) },
    ];

    return (
        <SectionCard title={s.reconcileTitle} icon={ArrowLeftRight} className="h-full">
            <dl className="grid grid-cols-2 gap-3">
                {items.map((item) => (
                    <div key={item.label} className="bg-muted/50 rounded-xl p-3">
                        <dt className="text-muted-foreground text-xs">{item.label}</dt>
                        <dd className={cn('num mt-0.5 font-semibold', item.tone && toneClasses[item.tone].text)}>{item.value}</dd>
                    </div>
                ))}
            </dl>
        </SectionCard>
    );
}

function CandidatesCard({ report }: { report: Report }) {
    const { t, money } = useI18n();
    const s = t.revenueAudit;
    const label = useCandidateLabel();
    const hasExpected = report.expected !== null;

    return (
        <SectionCard title={s.candidatesTitle} description={s.candidatesHint} icon={Scale} flush>
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead className="ps-5">{s.method}</TableHead>
                        <TableHead className="text-end">{s.value}</TableHead>
                        {hasExpected && <TableHead className="pe-5 text-end">{s.diff}</TableHead>}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {report.candidates.rows.map((row) => {
                        const closest = row.key === report.candidates.closest_to_expected;

                        return (
                            <TableRow key={row.key} className={cn(closest && 'bg-primary/5')}>
                                <TableCell className="ps-5 text-xs whitespace-normal">{label(row.key)}</TableCell>
                                <TableCell className="num text-end text-xs font-medium">{money(row.value, 2)}</TableCell>
                                {hasExpected && (
                                    <TableCell className={cn('num pe-5 text-end text-xs', closest ? 'text-primary font-semibold' : 'text-muted-foreground')}>
                                        {money(row.diff_from_expected, 2)}
                                    </TableCell>
                                )}
                            </TableRow>
                        );
                    })}
                </TableBody>
            </Table>
        </SectionCard>
    );
}

function StatusesCard({ groups }: { groups: Group }) {
    const { t, number, money } = useI18n();
    const s = t.revenueAudit;
    const rows = Object.entries(groups).sort((a, b) => b[1].orders - a[1].orders);

    return (
        <SectionCard title={s.statusesTitle} icon={ListChecks} flush>
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead className="ps-5">{s.status}</TableHead>
                        <TableHead className="text-end">{s.orders}</TableHead>
                        <TableHead className="text-end">{s.orderTotal}</TableHead>
                        <TableHead className="pe-5 text-end">{s.linesTotal}</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {rows.map(([status, row]) => (
                        <TableRow key={status}>
                            <TableCell className="ps-5 font-mono text-xs" dir="ltr">
                                {status}
                            </TableCell>
                            <TableCell className="num text-end text-xs">{number(row.orders)}</TableCell>
                            <TableCell className="num text-end text-xs">{money(row.order_total, 2)}</TableCell>
                            <TableCell className="num pe-5 text-end text-xs">{money(row.lines, 2)}</TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </SectionCard>
    );
}

function UnmatchedCard({ samples }: { samples: Report['zid']['unmatched']['samples'] }) {
    const { t, money, number } = useI18n();
    const s = t.revenueAudit;

    return (
        <SectionCard title={s.unmatchedTitle} icon={SearchX} flush>
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead className="ps-5">{s.product}</TableHead>
                        <TableHead>{s.lineId}</TableHead>
                        <TableHead>{s.parent}</TableHead>
                        <TableHead className="text-end">{s.value}</TableHead>
                        <TableHead className="pe-5">{s.method}</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {samples.map((line, i) => (
                        <TableRow key={`${line.order}-${line.id}-${i}`}>
                            <TableCell className="ps-5 text-xs whitespace-normal">
                                {line.name || '—'}
                                {line.sku && <span className="text-muted-foreground ms-1.5 font-mono">({line.sku})</span>}
                            </TableCell>
                            <TableCell className="max-w-40 truncate font-mono text-xs" dir="ltr" title={line.id}>
                                {line.id}
                            </TableCell>
                            <TableCell className="max-w-40 truncate font-mono text-xs" dir="ltr" title={line.parent_id ?? ''}>
                                {line.parent_id ?? '—'}
                            </TableCell>
                            <TableCell className="num text-end text-xs">
                                {money(line.revenue, 2)} <span className="text-muted-foreground">× {number(line.units)}</span>
                            </TableCell>
                            <TableCell className="pe-5 text-xs">{s.matchBy[line.would_match_by ?? 'none']}</TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </SectionCard>
    );
}

function ReportCard({ report }: { report: Report }) {
    const { t } = useI18n();
    const s = t.revenueAudit;
    const [copied, copy] = useClipboard();
    const [open, setOpen] = useState(false);
    const json = JSON.stringify(report, null, 2);

    const download = () => {
        const url = window.URL.createObjectURL(new Blob([json], { type: 'application/json' }));
        const a = document.createElement('a');
        a.href = url;
        a.download = `revenue-audit-${report.month}.json`;
        a.click();
        window.URL.revokeObjectURL(url);
    };

    return (
        <SectionCard
            title={s.reportTitle}
            description={s.reportHint}
            icon={FileJson}
            action={
                <div className="flex flex-wrap gap-2">
                    <Button size="sm" onClick={() => void copy(json)}>
                        {copied === json ? <CheckCircle2 /> : <ClipboardCopy />}
                        {copied === json ? s.copied : s.copy}
                    </Button>
                    <Button size="sm" variant="outline" onClick={download}>
                        <Download />
                        {s.download}
                    </Button>
                </div>
            }
        >
            <button type="button" onClick={() => setOpen((v) => !v)} className="text-primary cursor-pointer text-xs font-medium hover:underline">
                {open ? s.hide : s.show}
            </button>
            {open && (
                <pre dir="ltr" className="bg-muted/60 mt-3 max-h-[28rem] overflow-auto rounded-xl p-4 text-start font-mono text-[11px] leading-relaxed">
                    {json}
                </pre>
            )}
        </SectionCard>
    );
}

function HistoryCard({ history, current }: { history: Run[]; current: number | null }) {
    const { t, date, dateTime, money } = useI18n();
    const s = t.revenueAudit;
    const tones: Record<Status, Tone> = { queued: 'neutral', running: 'info', completed: 'success', failed: 'danger' };

    return (
        <SectionCard title={s.historyTitle} icon={History} flush>
            <Table>
                <TableBody>
                    {history.map((run) => {
                        const tone = toneClasses[tones[run.status] ?? 'neutral'];

                        return (
                            <TableRow key={run.id}>
                                <TableCell className="ps-5 text-sm font-medium">
                                    {run.month ? date(`${run.month}-01T00:00:00`, { month: 'long', year: 'numeric' }) : '—'}
                                </TableCell>
                                <TableCell>
                                    <span className={cn('inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium', tone.soft, tone.text)}>{s.statusLabels[run.status]}</span>
                                </TableCell>
                                <TableCell className="num text-muted-foreground text-xs">{run.expected !== null ? money(run.expected, 2) : '—'}</TableCell>
                                <TableCell className="text-muted-foreground text-xs">{dateTime(run.created_at)}</TableCell>
                                <TableCell className="pe-5 text-end">
                                    {run.id === current ? (
                                        <span className="text-muted-foreground text-xs">{s.viewing}</span>
                                    ) : (
                                        <Button size="sm" variant="ghost" onClick={() => router.get(AUDIT_URL, { run: run.id }, { preserveScroll: true })}>
                                            {s.view}
                                        </Button>
                                    )}
                                </TableCell>
                            </TableRow>
                        );
                    })}
                </TableBody>
            </Table>
        </SectionCard>
    );
}

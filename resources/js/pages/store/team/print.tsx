import { Head } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Printer } from 'lucide-react';
import { useEffect } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import type { MemberFigures, MonthPoint, TeamMember } from '@/components/store/team/shared';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/hooks/use-i18n';
import { useRevenueVisible } from '@/hooks/use-revenue-visible';

type SortKey = 'units' | 'products' | 'avg_per_product' | 'avg_per_month' | 'orders' | 'revenue';

type Props = {
    report: {
        months: string[];
        length: number;
        members: TeamMember[];
        unassigned: MemberFigures;
        totals: { products: number; assigned: number; units: number; orders: number; revenue: number | null };
    };
    /** Everyone on the team, before the page's filters. */
    teamSize: number;
    month: string | null;
    sort: SortKey;
    store: { name: string; domain: string | null };
    generatedAt: string;
    generatedBy: string;
};

const NAVY = '#1B3A5C';

/**
 * A4 landscape print view of the team comparison. The browser's "Save as
 * PDF" keeps Arabic letter shaping and right-to-left layout intact. Fixed
 * light colours: paper does not follow the app's theme.
 */
export default function TeamPrint({ report, teamSize, month, sort, store, generatedAt, generatedBy }: Props) {
    const { t, tr, locale, dir, isRtl, number, money, percent, date, dateTime } = useI18n();
    const m = t.team;
    const showRevenue = useRevenueVisible();
    const Back = isRtl ? ArrowRight : ArrowLeft;
    const monthName = (key: string) => date(`${key}-01`, { month: 'long', year: 'numeric' });
    const members = [...report.members].sort((a, b) => Number(b[sort] ?? 0) - Number(a[sort] ?? 0) || b.units - a.units || a.name.localeCompare(b.name));
    const teamUnits = report.members.reduce((sum, member) => sum + member.units, 0);
    const teamRevenue = report.members.reduce((sum, member) => sum + (member.revenue ?? 0), 0);
    // A single month is one month of totals shown with the year before it.
    const period = month
        ? tr(m.print.periodMonth, { month: monthName(month) })
        : tr(m.print.periodRange, { from: monthName(report.months[0]), to: monthName(report.months[report.months.length - 1]) });

    useEffect(() => {
        document.documentElement.lang = locale;
        document.documentElement.dir = dir;
        const timer = window.setTimeout(() => window.print(), 700);

        return () => window.clearTimeout(timer);
    }, [locale, dir]);

    const summary: { label: string; value: string }[] = [
        { label: m.kpi.members, value: number(report.members.length) },
        { label: m.kpi.covered, value: `${number(report.totals.assigned)} / ${number(report.totals.products)}` },
        { label: m.kpi.unassigned, value: number(report.unassigned.products) },
        { label: m.kpi.units, value: number(teamUnits) },
        ...(showRevenue ? [{ label: m.kpi.revenue, value: money(teamRevenue) }] : []),
    ];

    return (
        <div dir={dir} className="min-h-svh bg-[#f4f2ec] py-8 print:bg-white print:py-0">
            <Head title={`${m.print.title} · ${store.name}`} />
            <style>{`
                @page { size: A4 landscape; margin: 10mm; }
                @media print {
                    html, body { background: #fff !important; }
                    .report-sheet { box-shadow: none !important; border: 0 !important; width: auto !important; padding: 0 !important; }
                    thead { display: table-header-group; }
                    tr, .avoid-break { break-inside: avoid; }
                    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                }
            `}</style>

            <div className="mx-auto mb-4 flex max-w-[297mm] items-center justify-between px-4 print:hidden">
                <Button variant="ghost" onClick={() => (window.history.length > 1 ? window.history.back() : window.close())}>
                    <Back className="size-4" />
                    {t.reports.print.back}
                </Button>
                <Button onClick={() => window.print()}>
                    <Printer className="size-4" />
                    {t.reports.print.printNow}
                </Button>
            </div>

            <article className="report-sheet mx-auto w-[297mm] max-w-full rounded-lg border bg-white p-[12mm] text-[#1a2433] shadow-xl">
                <header className="avoid-break mb-5 flex items-start justify-between gap-6 border-b-2 pb-4" style={{ borderColor: NAVY }}>
                    <div className="space-y-1">
                        <h1 className="text-2xl font-bold" style={{ color: NAVY }}>
                            {m.print.title}
                        </h1>
                        <p className="text-sm font-medium">{store.name}</p>
                        <p className="text-xs text-[#6b7280]">{period}</p>
                        <p className="text-xs text-[#6b7280]">{tr(t.reports.print.generated, { date: dateTime(generatedAt), name: generatedBy })}</p>
                        {report.members.length < teamSize && (
                            <p className="text-xs font-medium text-[#8a6d1f]">{tr(m.print.filtered, { count: number(report.members.length), total: number(teamSize) })}</p>
                        )}
                    </div>
                    <div className="flex items-center gap-2">
                        <span className="flex size-11 items-center justify-center rounded-xl bg-[#14213A]">
                            <AppLogoIcon className="size-7 text-[#EADBB4]" />
                        </span>
                        <span className="text-lg font-semibold" style={{ color: NAVY }}>
                            طوق
                        </span>
                    </div>
                </header>

                <section className="avoid-break mb-6">
                    <h2 className="mb-2 text-sm font-semibold" style={{ color: NAVY }}>
                        {m.print.summary}
                    </h2>
                    <div className="grid gap-3" style={{ gridTemplateColumns: `repeat(${summary.length}, minmax(0, 1fr))` }}>
                        {summary.map((item) => (
                            <div key={item.label} className="rounded-lg border border-[#E3D3A6] bg-[#F8F4E8] px-3 py-2.5">
                                <div className="text-[11px] text-[#6b7280]">{item.label}</div>
                                <div className="num mt-0.5 text-lg font-bold">{item.value}</div>
                            </div>
                        ))}
                    </div>
                </section>

                <section className="mb-6">
                    <h2 className="mb-2 text-sm font-semibold" style={{ color: NAVY }}>
                        {m.compare.title}
                    </h2>
                    <table className="w-full border-collapse text-[10.5px] leading-snug">
                        <thead>
                            <tr className="text-white" style={{ backgroundColor: NAVY }}>
                                {[
                                    m.compare.rank,
                                    m.compare.member,
                                    m.compare.products,
                                    m.print.selling,
                                    m.compare.units,
                                    m.compare.avgPerProduct,
                                    m.compare.avgPerMonth,
                                    m.compare.orders,
                                    ...(showRevenue ? [m.compare.revenue] : []),
                                    m.compare.share,
                                    m.print.winners,
                                    m.print.stagnant,
                                    m.compare.best,
                                    m.print.status,
                                ].map((label) => (
                                    <th key={label} className="px-2 py-1.5 text-start font-semibold">
                                        {label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {members.map((member, i) => (
                                <tr key={member.id} className={i % 2 ? 'bg-[#F8F4E8]' : ''}>
                                    <Cell num>{number(i + 1)}</Cell>
                                    <Cell>
                                        <span className="font-semibold">{member.name}</span>
                                    </Cell>
                                    <Cell num>{number(member.products)}</Cell>
                                    <Cell num>{number(member.selling)}</Cell>
                                    <Cell num>
                                        <span className="font-bold">{number(member.units)}</span>
                                    </Cell>
                                    <Cell num>{number(member.avg_per_product, 1)}</Cell>
                                    <Cell num>{number(member.avg_per_month, 1)}</Cell>
                                    <Cell num>{number(member.orders)}</Cell>
                                    {showRevenue && <Cell num>{money(member.revenue)}</Cell>}
                                    <Cell num>
                                        <ShareBar value={member.share} label={percent(member.share, 1)} />
                                    </Cell>
                                    <Cell num>{number(member.winners)}</Cell>
                                    <Cell num>{number(member.stagnant)}</Cell>
                                    <Cell>{member.best ? `${member.best.name} (${number(member.best.units)})` : '—'}</Cell>
                                    <Cell>{t.admin.statuses[member.status]}</Cell>
                                </tr>
                            ))}
                            <tr className="text-[#6b7280]">
                                <Cell />
                                <Cell>{m.compare.unassignedRow}</Cell>
                                <Cell num>{number(report.unassigned.products)}</Cell>
                                <Cell num>{number(report.unassigned.selling)}</Cell>
                                <Cell num>{number(report.unassigned.units)}</Cell>
                                <Cell num>{number(report.unassigned.avg_per_product, 1)}</Cell>
                                <Cell num>{number(report.unassigned.avg_per_month, 1)}</Cell>
                                <Cell num>{number(report.unassigned.orders)}</Cell>
                                {showRevenue && <Cell num>{money(report.unassigned.revenue)}</Cell>}
                                <Cell num>
                                    <ShareBar value={report.unassigned.share} label={percent(report.unassigned.share, 1)} muted />
                                </Cell>
                                <Cell num>{number(report.unassigned.winners)}</Cell>
                                <Cell num>{number(report.unassigned.stagnant)}</Cell>
                                <Cell />
                                <Cell />
                            </tr>
                        </tbody>
                    </table>
                </section>

                <MonthlyTable
                    title={m.print.monthlyTitle}
                    firstColumn={m.compare.member}
                    rows={[
                        ...members.map((member) => ({ key: String(member.id), label: member.name, monthly: member.monthly })),
                        { key: 'unassigned', label: m.compare.unassignedRow, monthly: report.unassigned.monthly, muted: true },
                    ]}
                    value={(point) => point.units}
                    format={(n) => number(n)}
                />

                {showRevenue && (
                    <MonthlyTable
                        title={m.print.monthlyRevenueTitle}
                        firstColumn={m.compare.member}
                        rows={[
                            ...members.map((member) => ({ key: String(member.id), label: member.name, monthly: member.monthly })),
                            { key: 'unassigned', label: m.compare.unassignedRow, monthly: report.unassigned.monthly, muted: true },
                        ]}
                        value={(point) => point.revenue ?? 0}
                        format={(n) => number(n)}
                    />
                )}

                <section className="avoid-break space-y-1 text-[10px] leading-relaxed text-[#6b7280]">
                    <p>{m.compare.attribution}</p>
                    <p>{m.compare.ordersNote}</p>
                </section>

                <footer className="mt-6 border-t pt-3 text-center text-[10px] text-[#9ca3af]">
                    {t.reports.print.confidential} · طوق · {store.domain ?? ''}
                </footer>
            </article>
        </div>
    );
}

function Cell({ children, num = false }: { children?: React.ReactNode; num?: boolean }) {
    return <td className={`border-b border-[#ece6d5] px-2 py-1.5 align-middle ${num ? 'num whitespace-nowrap' : ''}`}>{children}</td>;
}

/** The share as a short bar with its figure beside it, so it reads on paper. */
function ShareBar({ value, label, muted = false }: { value: number; label: string; muted?: boolean }) {
    return (
        <span className="flex items-center gap-1.5">
            <span className="inline-block h-1.5 w-14 overflow-hidden rounded-full bg-[#e7e2d3]">
                <span className="block h-full rounded-full" style={{ width: `${Math.max(0, Math.min(100, value))}%`, backgroundColor: muted ? '#9ca3af' : NAVY }} />
            </span>
            {label}
        </span>
    );
}

/** People down, months across; each row's best month is set in bold. */
function MonthlyTable({
    title,
    firstColumn,
    rows,
    value,
    format,
}: {
    title: string;
    firstColumn: string;
    rows: { key: string; label: string; monthly: MonthPoint[]; muted?: boolean }[];
    value: (point: MonthPoint) => number;
    format: (n: number) => string;
}) {
    const { t, date } = useI18n();
    const months = rows[0]?.monthly.map((point) => point.period) ?? [];
    const columnTotals = months.map((_, i) => rows.reduce((sum, row) => sum + value(row.monthly[i]), 0));

    return (
        <section className="mb-6">
            <h2 className="mb-2 text-sm font-semibold" style={{ color: NAVY }}>
                {title}
            </h2>
            <table className="w-full border-collapse text-[10.5px] leading-snug">
                <thead>
                    <tr className="text-white" style={{ backgroundColor: NAVY }}>
                        <th className="px-2 py-1.5 text-start font-semibold">{firstColumn}</th>
                        {months.map((period) => (
                            <th key={period} className="px-1.5 py-1.5 text-center font-semibold whitespace-nowrap">
                                {date(period, { month: 'short', year: '2-digit' })}
                            </th>
                        ))}
                        <th className="px-2 py-1.5 text-end font-semibold">{t.team.monthly.total}</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, i) => {
                        const values = row.monthly.map(value);
                        const best = Math.max(...values);

                        return (
                            <tr key={row.key} className={`${i % 2 ? 'bg-[#F8F4E8]' : ''} ${row.muted ? 'text-[#6b7280]' : ''}`}>
                                <td className="border-b border-[#ece6d5] px-2 py-1.5 font-semibold">{row.label}</td>
                                {values.map((n, index) => (
                                    <td key={months[index]} className={`num border-b border-[#ece6d5] px-1.5 py-1.5 text-center ${n > 0 && n === best ? 'font-bold' : ''}`}>
                                        {n > 0 ? format(n) : '—'}
                                    </td>
                                ))}
                                <td className="num border-b border-[#ece6d5] px-2 py-1.5 text-end font-bold">{format(values.reduce((sum, n) => sum + n, 0))}</td>
                            </tr>
                        );
                    })}
                    <tr className="bg-[#efe9d8] font-bold">
                        <td className="px-2 py-1.5">{t.team.monthly.total}</td>
                        {columnTotals.map((n, index) => (
                            <td key={months[index]} className="num px-1.5 py-1.5 text-center">
                                {format(n)}
                            </td>
                        ))}
                        <td className="num px-2 py-1.5 text-end">{format(columnTotals.reduce((sum, n) => sum + n, 0))}</td>
                    </tr>
                </tbody>
            </table>
        </section>
    );
}

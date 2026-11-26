import { Link } from '@inertiajs/react';
import { CalendarDays } from 'lucide-react';
import type { ReactNode } from 'react';
import { ScrollX } from '@/components/mojo/scroll-x';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

export type Range = 3 | 6 | 12;
export type Period = { months: Range; month: string | null };
/** One month of sales; on the team report it also counts the products created in Zid that month. */
export type MonthPoint = { period: string; units: number; revenue: number | null; uploads?: number };

/** Where the products held today stand: moving (winning or average), slowing, stagnant, not classified yet. */
export type StatusMix = { moving: number; slowing: number; stagnant: number; unrated: number };

/** Of the new products of the last 12 months old enough to judge, how many sold in their first two months. */
export type SuccessRate = { judged: number; hit: number; rate: number | null };

export type RatingPartKey = 'sales' | 'success' | 'uploads' | 'status';
export type RatingGrade = 'excellent' | 'very_good' | 'average' | 'watch';
export type RatingReason = 'few_products' | 'new_member' | 'disabled' | 'small_team';

/**
 * A member's rating out of 100 against the team's typical member (50).
 * A part with no data yet is left out and the others share its weight.
 */
export type MemberRating = {
    score: number | null;
    grade: RatingGrade | null;
    reason: RatingReason | null;
    parts: { key: RatingPartKey; weight: number; score: number; value: number; team: number }[];
};

/** The months the rating looked at and how much each part weighs. */
export type RatingMeta = { months: number; until: string; weights: Record<RatingPartKey, number> };

/** What the team report says about one staff member in the chosen period. */
export type MemberFigures = {
    products: number;
    selling: number;
    winners: number;
    at_risk: number;
    stagnant: number;
    units: number;
    orders: number;
    /** Products created in Zid in the period, by whoever holds them now. */
    uploads: number;
    mix: StatusMix;
    success: SuccessRate;
    /** Null while revenue is hidden from the store. */
    revenue: number | null;
    avg_per_product: number;
    avg_per_month: number;
    share: number;
    revenue_share: number | null;
    monthly: MonthPoint[];
};

export type TeamMember = MemberFigures & {
    id: number;
    name: string;
    email: string;
    status: 'active' | 'disabled';
    /** Signs in with an authenticator app code as well as the password. */
    two_factor: boolean;
    last_login_at: string | null;
    joined_at: string | null;
    permissions: string[];
    best: { id: number; name: string; image_url: string | null; units: number } | null;
    rating: MemberRating;
};

const ALL = '__all__';

/**
 * The catalog's period control: the last 3, 6 or 12 months, or one month.
 */
export function PeriodPicker({ period, available, onChange }: { period: Period; available: string[]; onChange: (next: Period) => void }) {
    const { t, date } = useI18n();
    const c = t.catalog;

    return (
        <div className="flex flex-wrap items-center gap-2">
            <Tabs value={period.month ? '' : String(period.months)} onValueChange={(v) => onChange({ months: Number(v) as Range, month: null })}>
                <TabsList>
                    {([3, 6, 12] as Range[]).map((range) => (
                        <TabsTrigger key={range} value={String(range)} className="px-3">
                            {c.ranges[range]}
                        </TabsTrigger>
                    ))}
                </TabsList>
            </Tabs>
            {available.length > 0 && (
                <Select value={period.month ?? ALL} onValueChange={(v) => onChange({ months: period.months, month: v === ALL ? null : v })}>
                    <SelectTrigger className={cn('w-44', period.month && 'border-primary text-primary')} aria-label={c.pickMonth}>
                        <CalendarDays className="size-4" />
                        <SelectValue placeholder={c.pickMonth} />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ALL}>{c.pickMonth}</SelectItem>
                        {available.map((key) => (
                            <SelectItem key={key} value={key}>
                                {date(`${key}-01`, { month: 'long', year: 'numeric' })}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            )}
        </div>
    );
}

export type MatrixRow = { key: string; label: ReactNode; monthly: MonthPoint[]; tone?: 'muted' | 'total' };

/**
 * Months across, people (or products) down: each cell is that month's
 * sales, tinted by how it compares with the best cell in the table, so the
 * strong months and the strong rows stand out without reading every number.
 */
export function MonthlyMatrix({
    rows,
    metric = 'units',
    firstColumn,
    selected,
    cellHref,
}: {
    rows: MatrixRow[];
    metric?: 'units' | 'revenue' | 'uploads';
    firstColumn: string;
    selected?: string | null;
    /** Where a non-empty cell leads, e.g. that member's products of that month. */
    cellHref?: (rowKey: string, period: string) => string | undefined;
}) {
    const { t, number, money, date } = useI18n();
    const months = rows[0]?.monthly.map((m) => m.period) ?? [];
    const value = (point: MonthPoint | undefined) => Number(point?.[metric] ?? 0);
    const format = (n: number) => (metric === 'revenue' ? money(n) : number(n));
    // New products take the gold tone so they never read as sales.
    const ink = metric === 'uploads' ? 'var(--chart-2)' : 'var(--primary)';
    // Totals rows would drown the scale, so only the compared rows set it.
    const peak = Math.max(1, ...rows.filter((row) => row.tone !== 'total').flatMap((row) => row.monthly.map(value)));
    const currentMonth = new Date().toISOString().slice(0, 7);
    const partial = months.some((period) => period.slice(0, 7) === currentMonth);

    return (
        <div className="space-y-2">
            {/* On a phone it opens on the latest months and the total; earlier months are a swipe away. */}
            <ScrollX fade="end" startAtEnd className="rounded-xl border">
                <table className="w-full min-w-max border-collapse text-sm">
                    <thead>
                        <tr className="bg-muted/50 text-muted-foreground text-xs">
                            <th scope="col" className="bg-muted/50 sticky start-0 z-10 px-3 py-2.5 text-start font-medium">
                                {firstColumn}
                            </th>
                            {months.map((period) => (
                                <th
                                    key={period}
                                    scope="col"
                                    className={cn('px-2 py-2.5 text-center font-medium whitespace-nowrap', selected && period.slice(0, 7) === selected && 'text-primary')}
                                >
                                    {date(period, { month: 'short', year: '2-digit' })}
                                    {period.slice(0, 7) === currentMonth && '*'}
                                </th>
                            ))}
                            <th scope="col" className="px-3 py-2.5 text-end font-medium">
                                {t.team.monthly.total}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => {
                            const total = row.monthly.reduce((sum, point) => sum + value(point), 0);

                            return (
                                <tr key={row.key} className={cn('border-t', row.tone === 'total' && 'bg-muted/40 font-semibold', row.tone === 'muted' && 'text-muted-foreground')}>
                                    <th
                                        scope="row"
                                        className={cn('bg-card sticky start-0 z-10 max-w-28 truncate px-3 py-2 text-start font-medium sm:max-w-52', row.tone === 'total' && 'bg-muted font-semibold')}
                                    >
                                        {row.label}
                                    </th>
                                    {row.monthly.map((point) => {
                                        const n = value(point);
                                        const tint = row.tone === 'total' || n <= 0 ? 0 : 6 + Math.round((n / peak) * 34);

                                        return (
                                            <td
                                                key={point.period}
                                                className={cn('num px-2 py-2 text-center whitespace-nowrap', n <= 0 && 'text-muted-foreground/50')}
                                                style={tint ? { backgroundColor: `color-mix(in oklch, ${ink} ${tint}%, transparent)` } : undefined}
                                            >
                                                {n > 0 && cellHref?.(row.key, point.period) ? (
                                                    <Link href={cellHref(row.key, point.period)!} className="hover:text-primary underline decoration-dotted underline-offset-4">
                                                        {format(n)}
                                                    </Link>
                                                ) : n > 0 ? (
                                                    format(n)
                                                ) : (
                                                    '—'
                                                )}
                                            </td>
                                        );
                                    })}
                                    <td className="num px-3 py-2 text-end font-semibold whitespace-nowrap">{format(total)}</td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </ScrollX>
            {partial && <p className="text-muted-foreground text-xs">* {t.team.monthly.partial}</p>}
        </div>
    );
}

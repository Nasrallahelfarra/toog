import { router } from '@inertiajs/react';
import { BarChart3, FileBarChart2, FileDown, FileSpreadsheet, History, Radar, Scale, ScanSearch } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import type { Report } from '@/components/market/report-types';
import { useReportFormat } from '@/components/market/use-report-format';
import { EmptyState } from '@/components/mojo/empty-state';
import { HoverLift, Stagger, StaggerItem } from '@/components/mojo/motion';
import { PageBody, PageHeader } from '@/components/mojo/page-header';
import { SectionCard } from '@/components/mojo/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

type Props = {
    period: { from: string; to: string; preset: string };
    reports: Report[];
    recentExports: { id: number; report: Report['type'] | null; format: 'xlsx' | 'pdf' | null; user: string | null; created_at: string | null }[];
};

const icons: Record<Report['type'], LucideIcon> = {
    performance: BarChart3,
    market: Radar,
    evaluations: ScanSearch,
    balance: Scale,
};

const accents: Record<Report['type'], string> = {
    performance: 'bg-primary/8 text-primary dark:bg-primary/15',
    market: 'bg-info-soft text-info',
    evaluations: 'bg-success-soft text-success',
    balance: 'bg-gold-soft text-gold-foreground dark:text-gold',
};

const presets = ['30', '90', '180', '365'] as const;

export default function Reports({ period, reports, recentExports }: Props) {
    const { t, tr, relative, pick } = useI18n();
    const format = useReportFormat();
    const [from, setFrom] = useState(period.from);
    const [to, setTo] = useState(period.to);

    const query = period.preset === 'custom' ? `from=${period.from}&to=${period.to}` : `preset=${period.preset}`;
    const exportUrl = (type: string, fmt: 'xlsx' | 'pdf') => `/store/reports/${type}/export?format=${fmt}&${query}`;

    const go = (params: Record<string, string>) => router.get('/store/reports', params, { preserveScroll: true, preserveState: true, replace: true });

    return (
        <PageBody>
            <PageHeader title={t.reports.title} description={t.reports.description} icon={FileBarChart2} />

            <div className="bg-card flex flex-col gap-3 rounded-2xl border border-border/70 p-4 shadow-[0_1px_2px_rgba(16,24,40,0.04)] lg:flex-row lg:items-center">
                <span className="text-muted-foreground text-sm font-medium">{t.reports.period}</span>
                <div className="bg-muted inline-flex w-fit flex-wrap rounded-xl p-1">
                    {presets.map((p) => (
                        <button
                            key={p}
                            type="button"
                            onClick={() => go({ preset: p })}
                            className={cn(
                                'cursor-pointer rounded-lg px-3 py-1.5 text-sm transition-colors',
                                period.preset === p ? 'bg-card text-foreground font-medium shadow-sm' : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {t.reports.presets[p]}
                        </button>
                    ))}
                </div>
                <form
                    className="flex flex-wrap items-center gap-2 lg:ms-auto"
                    onSubmit={(e) => {
                        e.preventDefault();
                        go({ preset: 'custom', from, to });
                    }}
                >
                    <Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="h-9 w-40" aria-label={t.common.from} />
                    <span className="text-muted-foreground">—</span>
                    <Input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="h-9 w-40" aria-label={t.common.to} />
                    <Button type="submit" variant={period.preset === 'custom' ? 'default' : 'outline'} size="sm" className="h-9">
                        {t.reports.apply}
                    </Button>
                </form>
            </div>

            <Stagger key={query} className="grid gap-5 lg:grid-cols-2">
                {reports.map((report) => {
                    const Icon = icons[report.type];

                    return (
                        <StaggerItem key={report.type}>
                            <HoverLift className="h-full">
                                <div className="bg-card flex h-full flex-col gap-5 rounded-2xl border border-border/70 p-5 shadow-[0_1px_2px_rgba(16,24,40,0.04)] transition-shadow hover:shadow-md">
                                    <div className="flex items-start gap-3">
                                        <span className={cn('flex size-11 shrink-0 items-center justify-center rounded-xl', accents[report.type])}>
                                            <Icon className="size-5" />
                                        </span>
                                        <div className="space-y-0.5">
                                            <h2 className="font-semibold">{t.reports.types[report.type]}</h2>
                                            <p className="text-muted-foreground text-xs leading-relaxed">{t.reports.descriptions[report.type]}</p>
                                        </div>
                                    </div>

                                    <dl className="grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-3">
                                        {report.summary.map((metric) => (
                                            <div key={metric.label_en} className="space-y-0.5">
                                                <dt className="text-muted-foreground truncate text-xs">{pick({ ar: metric.label_ar, en: metric.label_en })}</dt>
                                                <dd className="num text-lg font-semibold">{format(metric.value, metric.format)}</dd>
                                            </div>
                                        ))}
                                    </dl>

                                    <div className="mt-auto flex gap-2 border-t border-border/60 pt-4">
                                        <Button asChild variant="soft" className="flex-1">
                                            <a href={exportUrl(report.type, 'xlsx')}>
                                                <FileSpreadsheet className="size-4" />
                                                {t.reports.excel}
                                            </a>
                                        </Button>
                                        <Button asChild variant="outline" className="flex-1">
                                            <a href={exportUrl(report.type, 'pdf')} target="_blank" rel="noreferrer">
                                                <FileDown className="size-4" />
                                                {t.reports.pdf}
                                            </a>
                                        </Button>
                                    </div>
                                </div>
                            </HoverLift>
                        </StaggerItem>
                    );
                })}
            </Stagger>

            <SectionCard title={t.reports.recent} icon={History} flush>
                {recentExports.length === 0 ? (
                    <EmptyState icon={History} title={t.reports.noRecent} className="py-8" />
                ) : (
                    <ul className="divide-y divide-border/60">
                        {recentExports.map((item) => (
                            <li key={item.id} className="flex items-center gap-3 px-5 py-3 text-sm">
                                <span className="bg-muted flex size-8 items-center justify-center rounded-lg">
                                    {item.format === 'pdf' ? <FileDown className="size-4" /> : <FileSpreadsheet className="text-success size-4" />}
                                </span>
                                <span className="flex-1">
                                    {item.report ? t.reports.types[item.report] : '—'}
                                    <span className="text-muted-foreground"> · {item.format?.toUpperCase()}</span>
                                </span>
                                <span className="text-muted-foreground text-xs">
                                    {item.user && tr(t.reports.by, { name: item.user })} · {relative(item.created_at)}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </SectionCard>
        </PageBody>
    );
}

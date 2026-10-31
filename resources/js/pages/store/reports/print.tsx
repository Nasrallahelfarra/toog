import { Head } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Printer } from 'lucide-react';
import { useEffect } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import type { Report } from '@/components/market/report-types';
import { useReportFormat } from '@/components/market/use-report-format';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/hooks/use-i18n';

type Props = {
    report: Report;
    store: { name: string; domain: string | null };
    period: { from: string; to: string };
    generatedAt: string;
    generatedBy: string;
};

/**
 * A4 print view of a report. The browser's "Save as PDF" keeps Arabic
 * letter shaping and right-to-left layout intact.
 */
export default function ReportPrint({ report, store, period, generatedAt, generatedBy }: Props) {
    const { t, tr, locale, dir, isRtl, pick, date, dateTime } = useI18n();
    const format = useReportFormat();
    const Back = isRtl ? ArrowRight : ArrowLeft;
    const title = pick({ ar: report.title_ar, en: report.title_en });

    useEffect(() => {
        document.documentElement.lang = locale;
        document.documentElement.dir = dir;
        const timer = window.setTimeout(() => window.print(), 700);

        return () => window.clearTimeout(timer);
    }, [locale, dir]);

    return (
        <div dir={dir} className="min-h-svh bg-[#f4f2ec] py-8 print:bg-white print:py-0">
            <Head title={`${title} · ${store.name}`} />
            <style>{`
                @page { size: A4; margin: 12mm; }
                @media print {
                    html, body { background: #fff !important; }
                    .report-sheet { box-shadow: none !important; border: 0 !important; width: auto !important; padding: 0 !important; }
                    thead { display: table-header-group; }
                    tr, .avoid-break { break-inside: avoid; }
                }
            `}</style>

            <div className="mx-auto mb-4 flex max-w-[210mm] items-center justify-between px-4 print:hidden">
                <Button variant="ghost" onClick={() => window.history.length > 1 ? window.history.back() : window.close()}>
                    <Back className="size-4" />
                    {t.reports.print.back}
                </Button>
                <Button onClick={() => window.print()}>
                    <Printer className="size-4" />
                    {t.reports.print.printNow}
                </Button>
            </div>

            <article className="report-sheet mx-auto w-[210mm] max-w-full rounded-lg border bg-white p-[14mm] text-[#1a2433] shadow-xl">
                <header className="avoid-break mb-6 flex items-start justify-between gap-6 border-b-2 border-[#1B3A5C] pb-5">
                    <div className="space-y-1">
                        <h1 className="text-2xl font-bold text-[#1B3A5C]">{title}</h1>
                        <p className="text-sm font-medium">{store.name}</p>
                        <p className="text-xs text-[#6b7280]">{tr(t.reports.print.period, { from: date(period.from), to: date(period.to) })}</p>
                        <p className="text-xs text-[#6b7280]">{tr(t.reports.print.generated, { date: dateTime(generatedAt), name: generatedBy })}</p>
                    </div>
                    <div className="flex items-center gap-2">
                        <span className="flex size-11 items-center justify-center rounded-xl bg-[#14213A]">
                            <AppLogoIcon className="size-7 text-[#EADBB4]" />
                        </span>
                        <span className="text-lg font-semibold text-[#1B3A5C]">Mojo AI</span>
                    </div>
                </header>

                <section className="avoid-break mb-7">
                    <h2 className="mb-3 text-sm font-semibold text-[#1B3A5C]">{t.reports.print.summary}</h2>
                    <div className="grid grid-cols-3 gap-3">
                        {report.summary.map((metric) => (
                            <div key={metric.label_en} className="rounded-lg border border-[#E3D3A6] bg-[#F8F4E8] px-3 py-2.5">
                                <div className="text-[11px] text-[#6b7280]">{pick({ ar: metric.label_ar, en: metric.label_en })}</div>
                                <div className="num mt-0.5 text-lg font-bold">{format(metric.value, metric.format)}</div>
                            </div>
                        ))}
                    </div>
                </section>

                {report.sections.map((section) => (
                    <section key={section.key} className="mb-7">
                        <div className="mb-2 flex items-baseline justify-between">
                            <h2 className="text-sm font-semibold text-[#1B3A5C]">{pick({ ar: section.title_ar, en: section.title_en })}</h2>
                            <span className="text-[11px] text-[#6b7280]">{tr(t.reports.print.rows, { count: section.rows.length })}</span>
                        </div>
                        {section.rows.length === 0 ? (
                            <p className="rounded-lg border border-dashed py-4 text-center text-xs text-[#6b7280]">{t.reports.print.empty}</p>
                        ) : (
                            <table className="w-full border-collapse text-[10.5px] leading-snug">
                                <thead>
                                    <tr className="bg-[#1B3A5C] text-white">
                                        {section.columns.map((col) => (
                                            <th key={col.key} className="px-2 py-1.5 text-start font-semibold">
                                                {locale === 'ar' ? col.ar : col.en}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {section.rows.map((row, i) => (
                                        <tr key={i} className={i % 2 ? 'bg-[#F8F4E8]' : ''}>
                                            {section.columns.map((col) => (
                                                <td
                                                    key={col.key}
                                                    className={`border-b border-[#ece6d5] px-2 py-1.5 align-top ${col.format !== 'text' ? 'num whitespace-nowrap' : ''}`}
                                                >
                                                    {format(row[col.key], col.format)}
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </section>
                ))}

                <footer className="mt-8 border-t pt-3 text-center text-[10px] text-[#9ca3af]">
                    {t.reports.print.confidential} · Mojo AI · {store.domain ?? ''}
                </footer>
            </article>
        </div>
    );
}

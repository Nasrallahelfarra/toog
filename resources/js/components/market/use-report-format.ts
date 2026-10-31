import { useI18n } from '@/hooks/use-i18n';
import type { ReportFormat } from './report-types';

/**
 * Formats a report cell or metric according to its column format.
 */
export function useReportFormat() {
    const { number, money, percent, date } = useI18n();

    return (value: string | number | null | undefined, format: ReportFormat): string => {
        if (value === null || value === undefined || value === '') {
            return '—';
        }

        switch (format) {
            case 'money':
                return money(value);
            case 'percent':
                return percent(value, Number(value) % 1 === 0 ? 0 : 1);
            case 'number':
                return number(value);
            case 'date':
                return date(String(value));
            default:
                return String(value);
        }
    };
}

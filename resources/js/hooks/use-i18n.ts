import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import { dictionaries } from '@/i18n';
import type { Dictionary, Locale } from '@/i18n';

export type Bilingual = { ar?: string | null; en?: string | null };

/**
 * Replace {placeholders} in a translated string.
 */
export function tr(text: string, vars: Record<string, string | number> = {}): string {
    return text.replace(/\{(\w+)\}/g, (_, key: string) =>
        vars[key] !== undefined ? String(vars[key]) : `{${key}}`,
    );
}

/**
 * Translations and locale-aware formatting. Numbers use Latin digits in
 * both languages (standard in Saudi dashboards); dates follow the locale.
 */
export function useI18n() {
    const { locale: raw } = usePage<{ locale: Locale }>().props;
    const locale: Locale = raw === 'en' ? 'en' : 'ar';

    return useMemo(() => {
        const t: Dictionary = dictionaries[locale];
        const intl = locale === 'ar' ? 'ar-SA-u-nu-latn-ca-gregory' : 'en-US';

        // Wrap in Unicode left-to-right isolates (LRI…PDI) so signs and
        // symbols stay put inside Arabic text: "-5%" never becomes "%5-".
        const ltr = (text: string) => `⁦${text}⁩`;

        const number = (value: number | string | null | undefined, digits = 0) => {
            if (value === null || value === undefined || value === '') {
                return '—';
            }

            const text = new Intl.NumberFormat(intl, {
                maximumFractionDigits: digits,
                minimumFractionDigits: digits,
            }).format(Number(value));

            return Number(value) < 0 ? ltr(text) : text;
        };

        const compact = (value: number | string | null | undefined) =>
            value === null || value === undefined
                ? '—'
                : new Intl.NumberFormat(intl, {
                      notation: 'compact',
                      maximumFractionDigits: 1,
                  }).format(Number(value));

        const money = (value: number | string | null | undefined, digits = 0) =>
            value === null || value === undefined
                ? '—'
                : `${number(value, digits)} ${t.common.sar}`;

        const usd = (value: number | string | null | undefined, digits = 2) =>
            value === null || value === undefined
                ? '—'
                : ltr(
                      `$${new Intl.NumberFormat('en-US', {
                          minimumFractionDigits: digits,
                          // Cents for totals; sub-dollar amounts keep 4 places.
                          maximumFractionDigits: Math.abs(Number(value)) >= 1 ? Math.max(digits, 2) : Math.max(digits, 4),
                      }).format(Number(value))}`,
                  );

        const percent = (value: number | string | null | undefined, digits = 0) =>
            value === null || value === undefined ? '—' : ltr(`${number(value, digits)}%`);

        const date = (
            value: string | Date | null | undefined,
            options: Intl.DateTimeFormatOptions = { day: 'numeric', month: 'short', year: 'numeric' },
        ) => (value ? new Intl.DateTimeFormat(intl, options).format(new Date(value)) : '—');

        const dateTime = (value: string | Date | null | undefined) =>
            date(value, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });

        const relative = (value: string | Date | null | undefined) => {
            if (!value) {
                return '—';
            }

            const seconds = (new Date(value).getTime() - Date.now()) / 1000;
            const rtf = new Intl.RelativeTimeFormat(intl, { numeric: 'auto' });
            const units: [Intl.RelativeTimeFormatUnit, number][] = [
                ['year', 31536000],
                ['month', 2592000],
                ['week', 604800],
                ['day', 86400],
                ['hour', 3600],
                ['minute', 60],
            ];

            for (const [unit, size] of units) {
                if (Math.abs(seconds) >= size) {
                    return rtf.format(Math.round(seconds / size), unit);
                }
            }

            return t.common.justNow;
        };

        /** Pick the right side of a server-provided {ar, en} pair. */
        const pick = (value: Bilingual | null | undefined, fallback = '') =>
            (locale === 'ar' ? value?.ar || value?.en : value?.en || value?.ar) || fallback;

        /** Pick between two fields such as name_ar / name_en. */
        const field = <T extends Record<string, unknown>>(obj: T | null | undefined, base: string) =>
            obj ? String(obj[`${base}_${locale}`] ?? obj[`${base}_${locale === 'ar' ? 'en' : 'ar'}`] ?? '') : '';

        return {
            t,
            locale,
            dir: locale === 'ar' ? ('rtl' as const) : ('ltr' as const),
            isRtl: locale === 'ar',
            tr,
            ltr,
            number,
            compact,
            money,
            usd,
            percent,
            date,
            dateTime,
            relative,
            pick,
            field,
        };
    }, [locale]);
}

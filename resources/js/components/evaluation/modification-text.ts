import { useI18n } from '@/hooks/use-i18n';
import { useVocabulary } from '@/hooks/use-vocabulary';
import type { VocabularyGroup } from '@/hooks/use-vocabulary';
import type { Modification } from './types';

const groupFor: Record<string, VocabularyGroup> = {
    pattern: 'patterns',
    material: 'materials',
    style: 'styles',
    season: 'seasons',
};

/**
 * Turns an engine modification (type + params) into a localized title and
 * detail line, so the same change reads naturally in Arabic and English.
 */
export function useModificationText() {
    const { t, tr, locale, number } = useI18n();
    const { label } = useVocabulary();
    const m = t.evaluation.mods;
    const named = (p: Modification['params']) => String((locale === 'ar' ? p.name_ar : p.name_en) ?? p.value ?? '');

    return (mod: Modification): { title: string; detail: string } => {
        const p = mod.params ?? {};

        if (mod.type === 'price') {
            return {
                title: tr(m.price.title, { from: number(p.from), to: number(p.to) }),
                detail: tr(m.price.detail, { low: number(p.low), high: number(p.high) }),
            };
        }

        if (mod.type === 'trend_color' || mod.type === 'trend_pattern') {
            const text = m[mod.type];

            return { title: tr(text.title, { name: named(p) }), detail: tr(text.detail, { momentum: p.momentum ?? '' }) };
        }

        if (mod.type === 'gap_pattern') {
            return { title: tr(m.gap_pattern.title, { name: named(p) }), detail: tr(m.gap_pattern.detail, { saturation: p.saturation ?? '' }) };
        }

        if (mod.type === 'season') {
            return { title: tr(m.season.title, { name: named(p) }), detail: tr(m.season.detail, { date: String(p.date ?? '') }) };
        }

        if (mod.type === 'saudi_color') {
            return { title: tr(m.saudi_color.title, { value: label('colors', String(p.value)) }), detail: m.saudi_color.detail };
        }

        const [kind, fieldName] = mod.type.startsWith('avoid_failure_')
            ? ['avoid_failure' as const, mod.type.replace('avoid_failure_', '')]
            : mod.type.startsWith('winner_')
              ? ['winner' as const, mod.type.replace('winner_', '')]
              : [null, null];

        if (kind && fieldName) {
            const group = groupFor[fieldName] ?? 'patterns';
            const fieldLabel = m.fields[fieldName as keyof typeof m.fields] ?? fieldName;

            return {
                title: tr(m[kind].title, {
                    field: fieldLabel,
                    value: label(group, String(p.value ?? '')),
                    from: p.from ? label(group, String(p.from)) : '—',
                }),
                detail: m[kind].detail,
            };
        }

        return { title: m.generic.title, detail: m.generic.detail };
    };
}

/**
 * Human summary of an attribute change set, e.g. for alternatives.
 */
export function useChangeSummary() {
    const { t, number, locale } = useI18n();
    const { label } = useVocabulary();
    const fields = t.evaluation.mods.fields;
    const sep = locale === 'ar' ? '، ' : ', ';

    return (change: Record<string, unknown>) =>
        Object.entries(change)
            .map(([key, value]) => {
                if (key === 'colors' && Array.isArray(value)) {
                    return `${fields.colors}: ${value.map((c) => label('colors', String(c))).join(sep)}`;
                }

                if (key === 'price') {
                    return `${fields.price}: ${number(Number(value))}`;
                }

                const group = groupFor[key];

                return group ? `${fields[key as keyof typeof fields] ?? key}: ${label(group, String(value))}` : null;
            })
            .filter(Boolean)
            .join(' · ');
}

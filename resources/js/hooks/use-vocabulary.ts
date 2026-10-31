import { usePage } from '@inertiajs/react';
import { useI18n } from '@/hooks/use-i18n';

type Entry = { ar: string; en: string; hex?: string };
export type VocabularyGroup = 'colors' | 'patterns' | 'materials' | 'styles' | 'seasons';
export type Vocabulary = Record<VocabularyGroup, Record<string, Entry>>;

/**
 * The product attribute vocabulary shared from the server
 * (App\Support\Vocabulary): keys, Arabic/English labels and colour hexes.
 */
export function useVocabulary() {
    const { vocabulary } = usePage<{ vocabulary: Vocabulary }>().props;
    const { locale } = useI18n();

    const label = (group: VocabularyGroup, key: string | null | undefined) =>
        key ? (vocabulary?.[group]?.[key]?.[locale] ?? key) : '—';

    const options = (group: VocabularyGroup) =>
        Object.entries(vocabulary?.[group] ?? {}).map(([value, entry]) => ({
            value,
            label: entry[locale],
            hex: entry.hex,
        }));

    const hex = (key: string) => vocabulary?.colors?.[key]?.hex ?? key;

    return { vocabulary, label, options, hex };
}

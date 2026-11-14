import { useI18n } from '@/hooks/use-i18n';

/** A count's wording: Arabic has distinct one / two / 3–10 / 11+ forms. */
export type CountForms = {
    one: string;
    two: string;
    few: string;
    many: string;
};

/** "منافس واحد" / "منافسَين" / "5 منافسين" / "12 منافسًا". */
export function useCount() {
    const { tr, number } = useI18n();

    return (n: number, forms: CountForms) =>
        tr(
            n === 1
                ? forms.one
                : n === 2
                  ? forms.two
                  : n >= 3 && n <= 10
                    ? forms.few
                    : forms.many,
            { n: number(n) },
        );
}

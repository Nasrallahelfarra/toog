import { Field } from '@/components/admin/field';
import { Slider } from '@/components/ui/slider';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

/**
 * Labelled slider showing its current value; follows the page direction.
 */
export function SliderField({
    label,
    value,
    min,
    max,
    onChange,
    error,
    hint,
    suffix = '',
    tone = 'text-primary',
}: {
    label: string;
    value: number;
    min: number;
    max: number;
    onChange: (value: number) => void;
    error?: string;
    hint?: string;
    suffix?: string;
    tone?: string;
}) {
    const { dir } = useI18n();

    return (
        <Field
            label={
                <span className="flex w-full items-center justify-between">
                    {label}
                    <span className={cn('num text-base font-semibold', tone)}>
                        {value}
                        {suffix}
                    </span>
                </span>
            }
            error={error}
            hint={hint}
        >
            <Slider dir={dir} value={[value]} min={min} max={max} step={1} onValueChange={([v]) => onChange(v)} />
        </Field>
    );
}

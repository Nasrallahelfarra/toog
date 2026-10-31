import { Check } from 'lucide-react';
import { motion } from 'motion/react';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useI18n } from '@/hooks/use-i18n';
import { useVocabulary } from '@/hooks/use-vocabulary';
import type { VocabularyGroup } from '@/hooks/use-vocabulary';
import { cn } from '@/lib/utils';

const NONE = '__none';

/**
 * Single-choice picker over one vocabulary group (pattern, material…).
 */
export function AttributeSelect({
    group,
    value,
    onChange,
    id,
    className,
    invalid,
}: {
    group: Exclude<VocabularyGroup, 'colors'>;
    value: string | null | undefined;
    onChange: (value: string | null) => void;
    id?: string;
    className?: string;
    invalid?: boolean;
}) {
    const { t } = useI18n();
    const { options } = useVocabulary();

    return (
        <Select value={value || NONE} onValueChange={(v) => onChange(v === NONE ? null : v)}>
            <SelectTrigger id={id} className={cn('h-10 w-full rounded-lg', className)} aria-invalid={invalid || undefined}>
                <SelectValue placeholder={t.evaluation.form.select} />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={NONE} className="text-muted-foreground">
                    {t.evaluation.form.none}
                </SelectItem>
                {options(group).map((o) => (
                    <SelectItem key={o.value} value={o.value}>
                        {o.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

/**
 * Multi-colour swatch picker (max `max` colours) from the vocabulary.
 */
export function ColorPicker({
    value,
    onChange,
    max = 4,
    size = 'md',
}: {
    value: string[];
    onChange: (colors: string[]) => void;
    max?: number;
    size?: 'sm' | 'md';
}) {
    const { options } = useVocabulary();

    const toggle = (key: string) => {
        if (value.includes(key)) {
            onChange(value.filter((c) => c !== key));
        } else if (value.length < max) {
            onChange([...value, key]);
        }
    };

    return (
        <div className={cn('flex flex-wrap', size === 'md' ? 'gap-2' : 'gap-1.5')}>
            {options('colors').map((o) => {
                const selected = value.includes(o.value);
                const disabled = !selected && value.length >= max;

                return (
                    <button
                        key={o.value}
                        type="button"
                        title={o.label}
                        aria-pressed={selected}
                        disabled={disabled}
                        onClick={() => toggle(o.value)}
                        className={cn(
                            'group relative flex cursor-pointer items-center gap-1.5 rounded-full border transition-all disabled:cursor-not-allowed disabled:opacity-35',
                            size === 'md' ? 'py-1 ps-1 pe-2.5 text-xs' : 'p-0.5',
                            selected ? 'border-primary bg-primary/5 ring-primary/15 ring-2' : 'hover:border-primary/40 border-transparent',
                        )}
                    >
                        <span
                            className={cn('relative flex items-center justify-center rounded-full ring-1 ring-black/10 ring-inset', size === 'md' ? 'size-6' : 'size-5')}
                            style={{ backgroundColor: o.hex }}
                        >
                            {selected && (
                                <motion.span initial={{ scale: 0 }} animate={{ scale: 1 }} className="rounded-full bg-black/35 p-0.5">
                                    <Check className="size-3 text-white" />
                                </motion.span>
                            )}
                        </span>
                        {size === 'md' && <span className={cn(selected ? 'text-foreground font-medium' : 'text-muted-foreground')}>{o.label}</span>}
                    </button>
                );
            })}
        </div>
    );
}

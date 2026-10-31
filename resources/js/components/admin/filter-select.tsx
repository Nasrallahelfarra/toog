import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn } from '@/lib/utils';

const ALL = '__all__';

/**
 * Select with an "all" option that maps to an empty filter value.
 */
export function FilterSelect({
    value,
    onChange,
    allLabel,
    options,
    className,
}: {
    value: string | null | undefined;
    onChange: (value: string | null) => void;
    allLabel: string;
    options: { value: string; label: string }[];
    className?: string;
}) {
    return (
        <Select value={value || ALL} onValueChange={(v) => onChange(v === ALL ? null : v)}>
            <SelectTrigger className={cn('h-9 w-full min-w-40 sm:w-auto', className)}>
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={ALL}>{allLabel}</SelectItem>
                {options.map((o) => (
                    <SelectItem key={o.value} value={o.value}>
                        {o.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

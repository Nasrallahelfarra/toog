import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn } from '@/lib/utils';

const ALL = '__all';

/**
 * A compact select for list filters; an empty value means "all".
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
            <SelectTrigger className={cn('h-9 min-w-36 rounded-lg bg-card text-sm', className)}>
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

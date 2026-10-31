import { useVocabulary } from '@/hooks/use-vocabulary';
import { cn } from '@/lib/utils';

/**
 * Colour swatches for vocabulary keys (or raw hex values), with labels.
 */
export function ColorChips({
    colors,
    showLabels = true,
    size = 'sm',
    className,
}: {
    colors: string[] | null | undefined;
    showLabels?: boolean;
    size?: 'sm' | 'md';
    className?: string;
}) {
    const { label, hex } = useVocabulary();

    if (!colors?.length) {
        return <span className="text-muted-foreground text-xs">—</span>;
    }

    return (
        <div className={cn('flex flex-wrap items-center gap-1.5', className)}>
            {[...new Set(colors)].map((c) => {
                const isHex = c.startsWith('#');

                return (
                    <span
                        key={c}
                        className={cn('inline-flex items-center gap-1.5', showLabels && 'bg-muted/70 rounded-full py-0.5 ps-0.5 pe-2 text-xs')}
                        title={isHex ? c : label('colors', c)}
                    >
                        <span
                            className={cn('rounded-full ring-1 ring-black/10 ring-inset', size === 'md' ? 'size-6' : 'size-4')}
                            style={{ backgroundColor: isHex ? c : hex(c) }}
                        />
                        {showLabels && !isHex && <span>{label('colors', c)}</span>}
                    </span>
                );
            })}
        </div>
    );
}

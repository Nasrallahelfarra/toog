import { ImagePlus, RefreshCw, Trash2 } from 'lucide-react';
import { AnimatePresence, motion } from 'motion/react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

/**
 * Drag-and-drop image field with preview. Holds a File in parent state;
 * `existingUrl` shows an inherited image (e.g. from the previous version).
 */
export function ImageDrop({
    file,
    onChange,
    existingUrl,
    compact = false,
    className,
}: {
    file: File | null;
    onChange: (file: File | null) => void;
    existingUrl?: string | null;
    compact?: boolean;
    className?: string;
}) {
    const { t } = useI18n();
    const input = useRef<HTMLInputElement>(null);
    const [dragging, setDragging] = useState(false);
    const [preview, setPreview] = useState<string | null>(null);

    useEffect(() => {
        if (!file) {
            setPreview(null);

            return;
        }

        const url = URL.createObjectURL(file);
        setPreview(url);

        return () => URL.revokeObjectURL(url);
    }, [file]);

    const shown = preview ?? existingUrl ?? null;

    const pick = (files: FileList | null) => {
        const f = files?.[0];

        if (f && f.type.startsWith('image/')) {
            onChange(f);
        }
    };

    return (
        <div className={className}>
            <input ref={input} type="file" accept="image/*" className="hidden" onChange={(e) => pick(e.target.files)} />
            <div
                role="button"
                tabIndex={0}
                onClick={() => !shown && input.current?.click()}
                onKeyDown={(e) => e.key === 'Enter' && input.current?.click()}
                onDragOver={(e) => {
                    e.preventDefault();
                    setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={(e) => {
                    e.preventDefault();
                    setDragging(false);
                    pick(e.dataTransfer.files);
                }}
                className={cn(
                    'relative flex items-center justify-center overflow-hidden rounded-2xl border-2 border-dashed transition-colors',
                    compact ? 'aspect-square' : 'aspect-[4/3]',
                    dragging ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/40',
                    !shown && 'cursor-pointer',
                )}
            >
                <AnimatePresence mode="wait">
                    {shown ? (
                        <motion.img
                            key={shown}
                            src={shown}
                            alt=""
                            initial={{ opacity: 0, scale: 1.03 }}
                            animate={{ opacity: 1, scale: 1 }}
                            exit={{ opacity: 0 }}
                            className="absolute inset-0 size-full object-cover"
                        />
                    ) : (
                        <motion.div key="empty" initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="flex flex-col items-center gap-2 p-4 text-center">
                            <span className="bg-primary/8 text-primary flex size-12 items-center justify-center rounded-2xl">
                                <ImagePlus className="size-6" />
                            </span>
                            {!compact && <span className="text-muted-foreground text-sm">{t.evaluation.form.dropHere}</span>}
                        </motion.div>
                    )}
                </AnimatePresence>

                {shown && (
                    <div className="absolute inset-x-2 bottom-2 flex justify-end gap-1.5">
                        <Button
                            type="button"
                            size="sm"
                            variant="secondary"
                            className="h-7 bg-white/90 px-2 text-xs text-black shadow hover:bg-white"
                            onClick={(e) => {
                                e.stopPropagation();
                                input.current?.click();
                            }}
                        >
                            <RefreshCw className="size-3.5" />
                            {!compact && t.evaluation.form.replace}
                        </Button>
                        {file && (
                            <Button
                                type="button"
                                size="sm"
                                variant="secondary"
                                className="h-7 bg-white/90 px-2 text-xs text-black shadow hover:bg-white"
                                onClick={(e) => {
                                    e.stopPropagation();
                                    onChange(null);
                                }}
                            >
                                <Trash2 className="size-3.5" />
                            </Button>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}

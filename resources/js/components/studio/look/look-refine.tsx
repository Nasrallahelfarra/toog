import { useForm, usePage } from '@inertiajs/react';
import { WandSparkles } from 'lucide-react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { aspectClass } from '@/components/studio/design-card';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';
import type { LookDesign } from './types';

const MAX = 1000;

/**
 * A look result edited in plain words. It posts to the same refine endpoint
 * as the full editor; for a look the server keeps the fabric attached.
 */
export function LookRefine({ design, open, onOpenChange, onMore }: { design: LookDesign; open: boolean; onOpenChange: (open: boolean) => void; onMore: () => void }) {
    const { t } = useI18n();
    const r = t.studio.look.refine;
    const since = usePage<{ since?: number | null }>().props.since ?? null;
    const form = useForm({ instruction: '', since });
    const ready = form.data.instruction.trim().length >= 2;

    const close = (value: boolean) => {
        if (form.processing) return;
        if (!value) {
            form.reset();
            form.clearErrors();
        }
        onOpenChange(value);
    };

    const append = (text: string) => form.setData('instruction', form.data.instruction.trim() ? `${form.data.instruction.trim()}، ${text}` : text);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!ready) return;
        form.transform((data) => ({ ...data, instruction: data.instruction.trim(), presets: [], color: null, aspect_ratio: design.aspect_ratio, variants_count: 1 }));
        form.post(`/store/designs/${design.id}/refine`, { preserveScroll: true, onSuccess: () => close(false) });
    };

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent className="max-h-[92dvh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader className="text-start sm:text-start">
                    <DialogTitle className="flex items-center gap-2">
                        <WandSparkles className="text-primary size-5" />
                        {r.title}
                    </DialogTitle>
                    <DialogDescription className="leading-6">{r.subtitle}</DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="grid gap-5 sm:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)]">
                    <div className={cn('bg-muted mx-auto max-h-[40dvh] w-full max-w-60 overflow-hidden rounded-2xl border sm:max-h-none sm:max-w-none', aspectClass[design.aspect_ratio] ?? 'aspect-[3/4]')}>
                        <img src={design.image_url} alt={design.title} className="size-full object-contain" />
                    </div>
                    <div className="flex flex-col gap-3">
                        <Textarea
                            autoFocus
                            dir="auto"
                            value={form.data.instruction}
                            onChange={(event) => form.setData('instruction', event.target.value)}
                            placeholder={r.placeholder}
                            maxLength={MAX}
                            rows={4}
                            className="text-[15px] leading-7"
                            aria-label={r.title}
                        />
                        <div className="flex justify-between gap-2">
                            <InputError message={form.errors.instruction} />
                            <span className="text-muted-foreground num ms-auto text-[11px]" dir="ltr">
                                {form.data.instruction.length}/{MAX}
                            </span>
                        </div>
                        <div className="flex flex-wrap gap-1.5">
                            {r.chips.map((chip) => (
                                <button key={chip} type="button" onClick={() => append(chip)} className="hover:bg-muted min-h-9 cursor-pointer rounded-full border px-3 text-xs transition-colors pointer-coarse:min-h-11">
                                    {chip}
                                </button>
                            ))}
                        </div>
                        <button
                            type="button"
                            onClick={() => {
                                close(false);
                                onMore();
                            }}
                            className="text-primary min-h-11 cursor-pointer self-start text-xs font-medium hover:underline"
                        >
                            {r.more}
                        </button>
                        <div className="mt-auto flex flex-wrap justify-end gap-2 border-t pt-4">
                            <Button type="button" variant="outline" className="h-11" disabled={form.processing} onClick={() => close(false)}>
                                {t.common.cancel}
                            </Button>
                            <Button type="submit" className="h-11" disabled={!ready || form.processing}>
                                {form.processing ? <Spinner /> : <WandSparkles className="size-4" />}
                                {r.apply}
                            </Button>
                        </div>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

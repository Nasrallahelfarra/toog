import { useForm } from '@inertiajs/react';
import { Loader2, RotateCcw } from 'lucide-react';
import { useId, useState } from 'react';
import { focusImprovementResults, ReviewImage } from './review-image';
import type { Improvement } from './types';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/hooks/use-i18n';

export function RegenerateDesignDialog({
    run,
    disabled,
}: {
    run: Improvement;
    disabled: boolean;
}) {
    const { t, dir } = useI18n();
    const c = t.productReview.custom;
    const [open, setOpen] = useState(false);
    const inputId = useId();
    const form = useForm({ custom_instruction: '', request_key: '' });
    const submit = () => {
        if (
            disabled ||
            form.processing ||
            form.data.custom_instruction.trim().length < 3
        )
            return;
        const key = form.data.request_key || crypto.randomUUID();
        form.setData('request_key', key);
        form.transform((data) => ({
            ...data,
            request_key: key,
            custom_instruction: data.custom_instruction.trim(),
        }));
        form.post(`/store/improvements/${run.id}/regenerate`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setOpen(false);
                focusImprovementResults();
            },
        });
    };
    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!form.processing) setOpen(next);
            }}
        >
            <DialogTrigger asChild>
                <Button
                    variant="outline"
                    disabled={disabled}
                    className="h-auto min-h-10 whitespace-normal"
                >
                    <RotateCcw className="size-4 shrink-0" />
                    {c.button}
                </Button>
            </DialogTrigger>
            <DialogContent
                dir={dir}
                className="max-h-[90dvh] overflow-y-auto sm:max-w-lg"
                onCloseAutoFocus={(event) => {
                    if (form.wasSuccessful) event.preventDefault();
                }}
            >
                <DialogHeader>
                    <DialogTitle className="pe-6 leading-7">
                        {c.title}
                    </DialogTitle>
                    <DialogDescription className="leading-6">
                        {c.description}
                    </DialogDescription>
                </DialogHeader>
                <div className="mx-auto w-40 max-w-full">
                    <ReviewImage src={run.after_url} label={c.source} />
                </div>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        submit();
                    }}
                    className="min-w-0 space-y-4"
                >
                    <div className="space-y-2">
                        <Label htmlFor={inputId}>{c.label}</Label>
                        <Textarea
                            id={inputId}
                            autoFocus
                            rows={4}
                            required
                            minLength={3}
                            maxLength={2000}
                            disabled={form.processing}
                            value={form.data.custom_instruction}
                            onChange={(event) =>
                                form.setData(
                                    'custom_instruction',
                                    event.target.value,
                                )
                            }
                            placeholder={c.placeholder}
                            aria-invalid={!!form.errors.custom_instruction}
                            aria-describedby={`${inputId}-hint ${inputId}-errors`}
                            className="min-h-28 resize-y leading-7"
                        />
                        <div className="flex items-start justify-between gap-3 text-xs leading-6 text-muted-foreground">
                            <p id={`${inputId}-hint`}>{c.hint}</p>
                            <span dir="ltr" className="shrink-0 tabular-nums">
                                {form.data.custom_instruction.length}/2000
                            </span>
                        </div>
                    </div>
                    <div
                        id={`${inputId}-errors`}
                        role={form.hasErrors ? 'alert' : undefined}
                    >
                        {Object.entries(form.errors).map(([key, message]) => (
                            <InputError key={key} message={message} />
                        ))}
                    </div>
                    {form.processing && (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            {t.productReview.imageLoading.submitting}
                        </p>
                    )}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            disabled={form.processing}
                            onClick={() => setOpen(false)}
                        >
                            {c.cancel}
                        </Button>
                        <Button
                            type="submit"
                            disabled={
                                disabled ||
                                form.processing ||
                                form.data.custom_instruction.trim().length < 3
                            }
                        >
                            {form.processing ? (
                                <Loader2 className="size-4 motion-safe:animate-spin" />
                            ) : (
                                <RotateCcw className="size-4" />
                            )}
                            {c.submit}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

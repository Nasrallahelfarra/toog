import { useForm, usePage } from '@inertiajs/react';
import { Loader2, Trash2 } from 'lucide-react';
import { useState } from 'react';
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
import { useI18n } from '@/hooks/use-i18n';

export function DeleteEvaluationButton({
    evaluation,
    compact = false,
}: {
    evaluation: { id: number; name: string };
    compact?: boolean;
}) {
    const { t, tr, isRtl } = useI18n();
    const { url } = usePage();
    const [open, setOpen] = useState(false);
    const [failed, setFailed] = useState(false);
    const form = useForm({ return_to: url });
    const c = t.evaluation.deletion;

    const remove = () => {
        setFailed(false);
        form.transform(() => ({ return_to: url }));
        form.delete(`/store/evaluations/${evaluation.id}`, {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
            onError: () => setFailed(true),
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(value) => {
                if (!form.processing) setOpen(value);
            }}
        >
            <DialogTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size={compact ? 'icon' : 'sm'}
                    className="shrink-0 text-danger hover:bg-danger/10 hover:text-danger"
                    aria-label={tr(c.named, { name: evaluation.name })}
                    title={tr(c.named, { name: evaluation.name })}
                    disabled={form.processing}
                >
                    <Trash2 className="size-4" />
                    {!compact && c.button}
                </Button>
            </DialogTrigger>
            <DialogContent dir={isRtl ? 'rtl' : 'ltr'} className="sm:max-w-md">
                <DialogHeader className="text-start sm:text-start">
                    <DialogTitle>{c.button}</DialogTitle>
                    <DialogDescription className="leading-7 break-words">
                        {tr(c.confirm, { name: evaluation.name })}
                    </DialogDescription>
                </DialogHeader>
                {failed && (
                    <p role="alert" className="text-sm text-danger">
                        {c.failed}
                    </p>
                )}
                <DialogFooter className="gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        disabled={form.processing}
                        onClick={() => setOpen(false)}
                    >
                        {t.common.cancel}
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        disabled={form.processing}
                        onClick={remove}
                    >
                        {form.processing ? (
                            <Loader2 className="size-4 motion-safe:animate-spin" />
                        ) : (
                            <Trash2 className="size-4" />
                        )}
                        {form.processing ? c.deleting : c.button}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

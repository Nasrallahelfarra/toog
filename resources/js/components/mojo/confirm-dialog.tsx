import type { ReactNode } from 'react';
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

/**
 * Confirmation wrapper: renders `trigger`, asks before calling onConfirm.
 */
export function ConfirmDialog({
    trigger,
    title,
    description,
    confirmLabel,
    destructive = false,
    onConfirm,
    children,
}: {
    trigger: ReactNode;
    title?: string;
    description?: ReactNode;
    confirmLabel?: string;
    destructive?: boolean;
    onConfirm: () => void | Promise<void>;
    children?: ReactNode;
}) {
    const { t } = useI18n();
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);

    const confirm = async () => {
        setBusy(true);

        try {
            await onConfirm();
            setOpen(false);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{title ?? t.common.confirmTitle}</DialogTitle>
                    {description && <DialogDescription>{description}</DialogDescription>}
                </DialogHeader>
                {children}
                <DialogFooter className="gap-2">
                    <Button variant="outline" onClick={() => setOpen(false)}>
                        {t.common.cancel}
                    </Button>
                    <Button variant={destructive ? 'destructive' : 'default'} onClick={confirm} disabled={busy}>
                        {confirmLabel ?? t.common.confirm}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

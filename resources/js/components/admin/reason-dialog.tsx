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
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/hooks/use-i18n';

/**
 * Controlled dialog asking for a short reason before a sensitive action
 * (suspending a store, disabling a user).
 */
export function ReasonDialog({
    open,
    onOpenChange,
    title,
    description,
    label,
    placeholder,
    required = false,
    confirmLabel,
    destructive = true,
    onConfirm,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description?: ReactNode;
    label: string;
    placeholder?: string;
    required?: boolean;
    confirmLabel: string;
    destructive?: boolean;
    onConfirm: (reason: string, done: () => void) => void;
}) {
    const { t } = useI18n();
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);

    const submit = () => {
        setBusy(true);
        onConfirm(reason.trim(), () => {
            setBusy(false);
            setReason('');
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    {description && <DialogDescription>{description}</DialogDescription>}
                </DialogHeader>
                <div className="grid gap-2">
                    <Label htmlFor="reason">{label}</Label>
                    <Textarea id="reason" value={reason} placeholder={placeholder} onChange={(e) => setReason(e.target.value)} rows={3} />
                </div>
                <DialogFooter className="gap-2">
                    <Button variant="outline" onClick={() => onOpenChange(false)}>
                        {t.common.cancel}
                    </Button>
                    <Button variant={destructive ? 'destructive' : 'default'} disabled={busy || (required && !reason.trim())} onClick={submit}>
                        {confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

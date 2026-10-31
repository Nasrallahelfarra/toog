import { router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { ReasonDialog } from '@/components/admin/reason-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useI18n } from '@/hooks/use-i18n';

/**
 * Open a store's dashboard as the admin (full owner permissions, logged).
 */
export function enterStore(id: number) {
    router.post(`/stores/${id}/enter`);
}

/**
 * Controlled yes/no dialog (safe to open from a dropdown menu item).
 */
export function ConfirmModal({
    open,
    onOpenChange,
    title,
    description,
    confirmLabel,
    destructive = false,
    onConfirm,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description?: ReactNode;
    confirmLabel: string;
    destructive?: boolean;
    onConfirm: (done: () => void) => void;
}) {
    const { t } = useI18n();
    const [busy, setBusy] = useState(false);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    {description && <DialogDescription>{description}</DialogDescription>}
                </DialogHeader>
                <DialogFooter className="gap-2">
                    <Button variant="outline" onClick={() => onOpenChange(false)}>
                        {t.common.cancel}
                    </Button>
                    <Button
                        variant={destructive ? 'destructive' : 'default'}
                        disabled={busy}
                        onClick={() => {
                            setBusy(true);
                            onConfirm(() => setBusy(false));
                        }}
                    >
                        {confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Suspend (with a reason) and activate dialogs for one store.
 */
export function StoreStatusDialogs({
    store,
    dialog,
    onClose,
}: {
    store: { id: number; name: string };
    dialog: 'suspend' | 'activate' | null;
    onClose: () => void;
}) {
    const { t, tr } = useI18n();

    return (
        <>
            <ReasonDialog
                open={dialog === 'suspend'}
                onOpenChange={(open) => !open && onClose()}
                title={tr(t.admin.stores.suspendTitle, { name: store.name })}
                description={t.admin.stores.suspendDescription}
                label={t.admin.stores.suspendReason}
                placeholder={t.admin.stores.suspendReasonPlaceholder}
                required
                confirmLabel={t.admin.stores.suspend}
                onConfirm={(reason, done) =>
                    router.post(`/admin/stores/${store.id}/suspend`, { reason }, { preserveScroll: true, onFinish: done, onSuccess: onClose })
                }
            />
            <ConfirmModal
                open={dialog === 'activate'}
                onOpenChange={(open) => !open && onClose()}
                title={tr(t.admin.stores.activateTitle, { name: store.name })}
                description={t.admin.stores.activateDescription}
                confirmLabel={t.admin.stores.activate}
                onConfirm={(done) =>
                    router.post(`/admin/stores/${store.id}/activate`, {}, { preserveScroll: true, onFinish: done, onSuccess: onClose })
                }
            />
        </>
    );
}

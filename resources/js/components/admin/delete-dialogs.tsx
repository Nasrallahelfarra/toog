import { router } from '@inertiajs/react';
import { AlertTriangle, Store, Trash2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { Field } from '@/components/admin/field';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useI18n } from '@/hooks/use-i18n';

/**
 * Permanent deletion confirmed by typing a word (the store name or the
 * user's email), so a slip of the mouse cannot wipe a store.
 */
function TypedDeleteDialog({
    open,
    onOpenChange,
    title,
    description,
    consequences,
    expected,
    onConfirm,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: ReactNode;
    consequences: ReactNode;
    /** What the admin must type; null skips the typing step. */
    expected: string | null;
    onConfirm: (
        typed: string,
        done: (error?: string) => void,
    ) => void;
}) {
    const { t, tr } = useI18n();
    const a = t.admin.deletion;
    const [typed, setTyped] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const matches = expected === null || typed.trim() === expected.trim();

    const change = (value: boolean) => {
        if (busy) return;
        if (!value) {
            setTyped('');
            setError(null);
        }
        onOpenChange(value);
    };

    return (
        <Dialog open={open} onOpenChange={change}>
            <DialogContent className="sm:max-w-lg">
                <form
                    className="space-y-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        if (!matches || busy) return;
                        setBusy(true);
                        setError(null);
                        onConfirm(typed, (message) => {
                            setBusy(false);
                            if (message) setError(message);
                        });
                    }}
                >
                    <DialogHeader className="text-start sm:text-start">
                        <span className="bg-danger-soft text-danger mb-1 flex size-11 items-center justify-center rounded-xl">
                            <Trash2 className="size-5" />
                        </span>
                        <DialogTitle className="leading-7">{title}</DialogTitle>
                        <DialogDescription className="leading-6">
                            {description}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="bg-danger-soft/60 text-danger space-y-2 rounded-xl p-4 text-sm leading-6">
                        <p className="flex items-center gap-2 font-semibold">
                            <AlertTriangle className="size-4 shrink-0" />
                            {a.irreversible}
                        </p>
                        {consequences}
                    </div>

                    {expected !== null && (
                        <Field
                            label={tr(a.typeToConfirm, { value: expected })}
                            error={error ?? undefined}
                        >
                            <Input
                                value={typed}
                                onChange={(e) => setTyped(e.target.value)}
                                autoComplete="off"
                                dir="auto"
                                aria-invalid={!!error}
                            />
                        </Field>
                    )}
                    {expected === null && error && (
                        <p role="alert" className="text-danger text-sm">
                            {error}
                        </p>
                    )}

                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            disabled={busy}
                            onClick={() => change(false)}
                        >
                            {t.common.cancel}
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={!matches || busy}
                        >
                            {busy ? <Spinner /> : <Trash2 className="size-4" />}
                            {a.confirm}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

const firstError = (errors: Record<string, string>) =>
    Object.values(errors)[0];

/** Delete a store and everything it holds. */
export function DeleteStoreDialog({
    store,
    open,
    onOpenChange,
}: {
    store: {
        id: number;
        name: string;
        products_count: number;
        evaluations_count: number;
    } | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t, tr, number } = useI18n();
    const a = t.admin.deletion;

    return (
        <TypedDeleteDialog
            open={open && store !== null}
            onOpenChange={onOpenChange}
            title={tr(a.storeTitle, { name: store?.name ?? '' })}
            description={a.storeDescription}
            expected={store?.name ?? ''}
            consequences={
                <ul className="list-inside list-disc space-y-1">
                    <li>
                        {tr(a.storeProducts, {
                            count: number(store?.products_count ?? 0),
                        })}
                    </li>
                    <li>
                        {tr(a.storeEvaluations, {
                            count: number(store?.evaluations_count ?? 0),
                        })}
                    </li>
                    <li>{a.storeFiles}</li>
                </ul>
            }
            onConfirm={(typed, done) =>
                router.delete(`/admin/stores/${store?.id}`, {
                    data: { confirm: typed },
                    onSuccess: () => {
                        done();
                        onOpenChange(false);
                    },
                    onError: (errors) => done(firstError(errors)),
                })
            }
        />
    );
}

/**
 * Delete an account. An owner's stores go with it, so they are listed and
 * the admin types the email to confirm.
 */
export function DeleteUserDialog({
    user,
    open,
    onOpenChange,
}: {
    user: {
        id: number;
        name: string;
        email: string;
        stores: { id: number; name: string; owned?: boolean }[];
    } | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t, tr } = useI18n();
    const a = t.admin.deletion;
    const owned = user?.stores.filter((s) => s.owned) ?? [];

    return (
        <TypedDeleteDialog
            open={open && user !== null}
            onOpenChange={onOpenChange}
            title={tr(a.userTitle, { name: user?.name ?? '' })}
            description={a.userDescription}
            expected={owned.length > 0 ? (user?.email ?? '') : null}
            consequences={
                owned.length > 0 ? (
                    <>
                        <p>{tr(a.userStores, { count: owned.length })}</p>
                        <ul className="space-y-1">
                            {owned.map((s) => (
                                <li
                                    key={s.id}
                                    className="flex items-center gap-2 font-medium"
                                >
                                    <Store className="size-4 shrink-0" />
                                    {s.name}
                                </li>
                            ))}
                        </ul>
                    </>
                ) : (
                    <p>{a.userOnly}</p>
                )
            }
            onConfirm={(_, done) =>
                router.delete(`/admin/users/${user?.id}`, {
                    data: { delete_stores: owned.length > 0 },
                    preserveScroll: true,
                    onSuccess: () => {
                        done();
                        onOpenChange(false);
                    },
                    onError: (errors) => done(firstError(errors)),
                })
            }
        />
    );
}

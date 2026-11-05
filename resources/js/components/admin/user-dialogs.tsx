import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Field } from '@/components/admin/field';
import PasswordInput from '@/components/password-input';
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
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useI18n } from '@/hooks/use-i18n';

export type AdminUserRow = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    role: 'admin' | 'store_owner';
    status: 'active' | 'disabled';
    disabled_reason: string | null;
    disabled_at: string | null;
    last_login_at: string | null;
    created_at: string | null;
    stores: { id: number; name: string; status: string; owned: boolean }[];
};

const NO_STORE = '__none__';

/**
 * Create a user, or edit one when `user` is given. The form lives inside
 * the dialog content, so it starts fresh every time the dialog opens.
 */
export function UserFormDialog({
    open,
    onOpenChange,
    user,
    stores,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    user?: AdminUserRow | null;
    stores: { id: number; name: string }[];
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <UserForm user={user ?? null} stores={stores} onDone={() => onOpenChange(false)} />
            </DialogContent>
        </Dialog>
    );
}

function UserForm({ user, stores, onDone }: { user: AdminUserRow | null; stores: { id: number; name: string }[]; onDone: () => void }) {
    const { t, tr } = useI18n();
    const u = t.admin.users;
    const editing = user !== null;

    const form = useForm({
        name: user?.name ?? '',
        email: user?.email ?? '',
        phone: user?.phone ?? '',
        role: (user?.role ?? 'store_owner') as 'admin' | 'store_owner',
        password: '',
        store_id: NO_STORE,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onDone };

        if (user) {
            form.transform(({ name, email, phone, role }) => ({ name, email, phone, role }));
            form.put(`/admin/users/${user.id}`, options);
        } else {
            form.transform((data) => ({ ...data, store_id: data.store_id === NO_STORE ? null : data.store_id }));
            form.post('/admin/users', options);
        }
    };

    return (
        <form onSubmit={submit} className="space-y-5">
            <DialogHeader>
                <DialogTitle>{user ? tr(u.editTitle, { name: user.name }) : u.createTitle}</DialogTitle>
            </DialogHeader>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label={t.common.name} error={form.errors.name} className="sm:col-span-2">
                    <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                </Field>
                <Field label={t.common.email} error={form.errors.email}>
                    <Input type="email" dir="ltr" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} required />
                </Field>
                <Field label={t.common.phone} error={form.errors.phone}>
                    <Input dir="ltr" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} />
                </Field>
                <Field label={u.role} error={form.errors.role}>
                    <Select value={form.data.role} onValueChange={(v) => form.setData('role', v as 'admin' | 'store_owner')}>
                        <SelectTrigger className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="store_owner">{t.admin.roles.store_owner}</SelectItem>
                            <SelectItem value="admin">{t.admin.roles.admin}</SelectItem>
                        </SelectContent>
                    </Select>
                </Field>
                {!editing && (
                    <Field label={u.password} error={form.errors.password}>
                        <PasswordInput value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} required autoComplete="new-password" />
                    </Field>
                )}
                {!editing && form.data.role === 'store_owner' && (
                    <Field label={u.attachStore} error={form.errors.store_id} className="sm:col-span-2">
                        <Select value={form.data.store_id} onValueChange={(v) => form.setData('store_id', v)}>
                            <SelectTrigger className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={NO_STORE}>{u.noStore}</SelectItem>
                                {stores.map((s) => (
                                    <SelectItem key={s.id} value={String(s.id)}>
                                        {s.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                )}
            </div>
            <DialogFooter className="gap-2">
                <Button type="button" variant="outline" onClick={onDone}>
                    {t.common.cancel}
                </Button>
                <Button type="submit" disabled={form.processing}>
                    {form.processing && <Spinner />}
                    {editing ? t.common.saveChanges : t.common.create}
                </Button>
            </DialogFooter>
        </form>
    );
}

/**
 * Admin sets a new password for a user.
 */
export function PasswordDialog({ open, onOpenChange, user }: { open: boolean; onOpenChange: (open: boolean) => void; user: Pick<AdminUserRow, 'id' | 'name'> | null }) {
    const { t, tr } = useI18n();
    const u = t.admin.users;
    const form = useForm({ password: '', password_confirmation: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(`/admin/users/${user?.id}/password`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onOpenChange(false);
            },
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(value) => {
                if (!value) {
                    form.reset();
                    form.clearErrors();
                }
                onOpenChange(value);
            }}
        >
            <DialogContent className="sm:max-w-md">
                <form onSubmit={submit} className="space-y-5">
                    <DialogHeader>
                        <DialogTitle>{tr(u.resetTitle, { name: user?.name ?? '' })}</DialogTitle>
                        <DialogDescription>{u.resetDescription}</DialogDescription>
                    </DialogHeader>
                    <Field label={u.newPassword} error={form.errors.password}>
                        <PasswordInput value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} required autoComplete="new-password" />
                    </Field>
                    <Field label={u.confirmPassword}>
                        <PasswordInput
                            value={form.data.password_confirmation}
                            onChange={(e) => form.setData('password_confirmation', e.target.value)}
                            required
                            autoComplete="new-password"
                        />
                    </Field>
                    <DialogFooter className="gap-2">
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            {t.common.cancel}
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {t.common.save}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

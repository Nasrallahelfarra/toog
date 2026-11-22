import { router, useForm } from '@inertiajs/react';
import { Minus, Plus, ShieldAlert, ShieldCheck, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { Field } from '@/components/admin/field';
import PasswordInput from '@/components/password-input';
import type { TeamMember } from '@/components/store/team/shared';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useI18n } from '@/hooks/use-i18n';
import { STORE_SECTION_GROUPS, STORE_SECTIONS } from '@/lib/store-sections';
import { cn } from '@/lib/utils';

type SectionGroups = Record<string, string[]>;

// The dialog's title and its buttons stay in view; only the middle scrolls,
// so "save" is never below the fold on a long list of pages.
const SHELL = 'flex max-h-[90dvh] flex-col gap-0 overflow-hidden p-0';
const FORM = 'flex min-h-0 flex-1 flex-col';
const BODY = 'min-h-0 flex-1 space-y-5 overflow-y-auto px-6 pb-5';
const FOOT = 'bg-background space-y-3 border-t px-6 py-4';

/**
 * Add a staff member, or edit one's name and email when `member` is given.
 * A new member's pages are picked here; an existing member's are changed in
 * MemberAccessDialog. The form lives inside the dialog content, so it
 * starts fresh every time the dialog opens.
 */
export function MemberFormDialog({
    open,
    onOpenChange,
    member,
    sections,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    member: TeamMember | null;
    sections: SectionGroups;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className={cn(SHELL, member ? 'sm:max-w-md' : 'sm:max-w-2xl')}>
                <MemberForm member={member} sections={sections} onDone={() => onOpenChange(false)} />
            </DialogContent>
        </Dialog>
    );
}

const pagesError = (errors: Record<string, string>) => Object.entries(errors).find(([key]) => key.startsWith('permissions'))?.[1];

function MemberForm({ member, sections, onDone }: { member: TeamMember | null; sections: SectionGroups; onDone: () => void }) {
    const { t, tr } = useI18n();
    const f = t.team.form;

    const form = useForm({
        name: member?.name ?? '',
        email: member?.email ?? '',
        password: '',
        // A new member starts with their own products only; the owner adds pages.
        permissions: [] as string[],
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onDone };

        if (member) {
            form.transform(({ name, email }) => ({ name, email }));
            form.put(`/store/team/${member.id}`, options);
        } else {
            form.post('/store/team', options);
        }
    };

    return (
        <form onSubmit={submit} className={FORM}>
            <DialogHeader className="px-6 pt-6 pb-4 text-start sm:text-start">
                <DialogTitle>{member ? tr(f.editTitle, { name: member.name }) : f.createTitle}</DialogTitle>
                {member && <DialogDescription>{f.editDescription}</DialogDescription>}
            </DialogHeader>

            <div className={BODY}>
            <div className={cn('grid gap-4', !member && 'sm:grid-cols-2')}>
                <Field label={t.common.name} error={form.errors.name ?? (form.errors as Record<string, string>).member} className={member ? undefined : 'sm:col-span-2'}>
                    <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required dir="auto" />
                </Field>
                <Field label={t.common.email} error={form.errors.email}>
                    <Input type="email" dir="ltr" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} required autoComplete="off" />
                </Field>
                {!member && (
                    <Field label={f.password} error={form.errors.password} hint={f.passwordHint}>
                        <PasswordInput value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} required autoComplete="new-password" />
                    </Field>
                )}
            </div>

            {!member && (
                <PagePicker
                    sections={sections}
                    value={form.data.permissions}
                    onChange={(next) => form.setData('permissions', next)}
                    error={pagesError(form.errors)}
                />
            )}
            </div>

            <DialogFooter className={cn(FOOT, 'gap-2 space-y-0')}>
                <Button type="button" variant="outline" onClick={onDone}>
                    {t.common.cancel}
                </Button>
                <Button type="submit" disabled={form.processing}>
                    {form.processing && <Spinner />}
                    {member ? t.common.saveChanges : t.team.addMember}
                </Button>
            </DialogFooter>
        </form>
    );
}

type AccessMember = Pick<TeamMember, 'id' | 'name' | 'permissions'>;

/**
 * The pages one member of the team may open, changed at any time: tick,
 * untick, save. What is about to change is listed before saving, and it
 * applies on the member's next click.
 */
export function MemberAccessDialog({
    open,
    onOpenChange,
    member,
    sections,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    member: AccessMember | null;
    sections: SectionGroups;
}) {
    return (
        <Dialog open={open && member !== null} onOpenChange={onOpenChange}>
            <DialogContent className={cn(SHELL, 'sm:max-w-2xl')}>
                {member && <MemberAccessForm member={member} sections={sections} onDone={() => onOpenChange(false)} />}
            </DialogContent>
        </Dialog>
    );
}

function MemberAccessForm({ member, sections, onDone }: { member: AccessMember; sections: SectionGroups; onDone: () => void }) {
    const { t, tr } = useI18n();
    const a = t.team.access;
    const form = useForm({ permissions: member.permissions });

    const before = new Set(member.permissions);
    const after = new Set(form.data.permissions);
    const added = form.data.permissions.filter((key) => !before.has(key));
    const removed = member.permissions.filter((key) => !after.has(key));
    const dirty = added.length > 0 || removed.length > 0;
    const pageName = (key: string) => (STORE_SECTIONS[key] ? t.nav[STORE_SECTIONS[key].label] : key);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(`/store/team/${member.id}/permissions`, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className={FORM}>
            <DialogHeader className="flex-row items-center gap-3 px-6 pt-6 pb-4 text-start sm:text-start">
                <span className="bg-primary/10 text-primary flex size-11 shrink-0 items-center justify-center rounded-xl">
                    <ShieldCheck className="size-5" />
                </span>
                <div className="min-w-0 space-y-1 pe-6">
                    <DialogTitle className="leading-7">{tr(a.title, { name: member.name })}</DialogTitle>
                    <DialogDescription className="leading-6">{a.description}</DialogDescription>
                </div>
            </DialogHeader>

            <div className={BODY}>
                <PagePicker
                    sections={sections}
                    value={form.data.permissions}
                    onChange={(next) => form.setData('permissions', next)}
                    error={pagesError(form.errors) ?? (form.errors as Record<string, string>).member}
                />
            </div>

            <div className={FOOT}>
            {dirty && (
                <div className="flex flex-wrap items-center gap-1.5 text-xs" aria-live="polite">
                    <span className="text-muted-foreground me-1">{a.pending}</span>
                    {added.map((key) => (
                        <span key={key} className="bg-success-soft text-success inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium">
                            <Plus className="size-3" />
                            {pageName(key)}
                        </span>
                    ))}
                    {removed.map((key) => (
                        <span key={key} className="bg-danger-soft text-danger inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium">
                            <Minus className="size-3" />
                            {pageName(key)}
                        </span>
                    ))}
                </div>
            )}

            <DialogFooter className="gap-2">
                <Button type="button" variant="outline" onClick={onDone}>
                    {t.common.cancel}
                </Button>
                <Button type="submit" disabled={form.processing || !dirty}>
                    {form.processing ? <Spinner /> : <ShieldCheck className="size-4" />}
                    {a.save}
                </Button>
            </DialogFooter>
            </div>
        </form>
    );
}

/**
 * The dashboard pages as ticks, grouped the way the sidebar groups them.
 */
function PagePicker({ sections, value, onChange, error }: { sections: SectionGroups; value: string[]; onChange: (next: string[]) => void; error?: string }) {
    const { t, tr } = useI18n();
    const f = t.team.form;
    const all = Object.values(sections).flat();
    const granted = new Set(value);

    const setGranted = (keys: string[], on: boolean) => {
        const next = new Set(granted);
        keys.forEach((key) => (on ? next.add(key) : next.delete(key)));
        // Keep the server's order so the saved list reads like the sidebar.
        onChange(all.filter((key) => next.has(key)));
    };

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-end justify-between gap-2">
                <div className="space-y-0.5">
                    <h3 className="text-sm font-semibold">
                        {f.pages} <span className="num text-muted-foreground font-normal">· {tr(f.selected, { count: granted.size, total: all.length })}</span>
                    </h3>
                    <p className="text-muted-foreground max-w-xl text-xs leading-relaxed">{f.pagesHint}</p>
                </div>
                <div className="flex gap-1">
                    <Button type="button" variant="ghost" size="sm" className="h-8" onClick={() => setGranted(all, true)}>
                        {f.selectAll}
                    </Button>
                    <Button type="button" variant="ghost" size="sm" className="h-8" onClick={() => setGranted(all, false)}>
                        {f.clearAll}
                    </Button>
                </div>
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
                {STORE_SECTION_GROUPS.filter((group) => sections[group.key]?.length).map((group) => {
                    const keys = sections[group.key];
                    const count = keys.filter((key) => granted.has(key)).length;
                    const state = count === 0 ? false : count === keys.length ? true : 'indeterminate';

                    return (
                        <fieldset key={group.key} className="rounded-xl border p-3">
                            <legend className="sr-only">{t.nav[group.label]}</legend>
                            <label className="flex cursor-pointer items-center gap-2.5 border-b pb-2.5 text-sm font-semibold">
                                <Checkbox checked={state} onCheckedChange={(checked) => setGranted(keys, checked === true)} />
                                <span className="flex-1">{t.nav[group.label]}</span>
                                <span className="num text-muted-foreground text-xs font-normal">
                                    {count}/{keys.length}
                                </span>
                            </label>
                            <div className="mt-2 space-y-0.5">
                                {keys.map((key) => {
                                    const meta = STORE_SECTIONS[key];
                                    const Icon = meta?.icon;
                                    const note = f.sensitive[key as keyof typeof f.sensitive];

                                    return (
                                        <label key={key} className="hover:bg-muted/60 flex cursor-pointer items-start gap-2.5 rounded-lg px-1.5 py-1.5 text-sm">
                                            <Checkbox className="mt-0.5" checked={granted.has(key)} onCheckedChange={(checked) => setGranted([key], checked === true)} />
                                            {Icon && <Icon className={cn('mt-0.5 size-4 shrink-0', granted.has(key) ? 'text-primary' : 'text-muted-foreground')} />}
                                            <span className="min-w-0 flex-1">
                                                <span className="block">{meta ? t.nav[meta.label] : key}</span>
                                                {note && <span className="text-muted-foreground block text-xs leading-relaxed">{note}</span>}
                                            </span>
                                        </label>
                                    );
                                })}
                            </div>
                        </fieldset>
                    );
                })}
            </div>

            {error && (
                <p role="alert" className="text-danger text-sm">
                    {error}
                </p>
            )}
            <p className="text-muted-foreground flex items-start gap-2 text-xs leading-relaxed">
                <ShieldAlert className="mt-0.5 size-3.5 shrink-0" />
                {f.ownerOnly}
            </p>
        </div>
    );
}

/**
 * The owner sets a new password for a staff member.
 */
export function MemberPasswordDialog({ open, onOpenChange, member }: { open: boolean; onOpenChange: (open: boolean) => void; member: Pick<TeamMember, 'id' | 'name'> | null }) {
    const { t, tr } = useI18n();
    const u = t.admin.users;
    const form = useForm({ password: '', password_confirmation: '' });

    const change = (value: boolean) => {
        if (!value) {
            form.reset();
            form.clearErrors();
        }
        onOpenChange(value);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(`/store/team/${member?.id}/password`, { preserveScroll: true, onSuccess: () => change(false) });
    };

    return (
        <Dialog open={open} onOpenChange={change}>
            <DialogContent className="sm:max-w-md">
                <form onSubmit={submit} className="space-y-5">
                    <DialogHeader>
                        <DialogTitle>{tr(u.resetTitle, { name: member?.name ?? '' })}</DialogTitle>
                        <DialogDescription>{u.resetDescription}</DialogDescription>
                    </DialogHeader>
                    <Field label={u.newPassword} error={form.errors.password ?? (form.errors as Record<string, string>).member}>
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
                        <Button type="button" variant="outline" onClick={() => change(false)}>
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

const RELEASE = '__release__';

/**
 * Remove a member from the team. When they hold products the owner decides
 * where those go: to another member, or back to nobody.
 */
export function RemoveMemberDialog({
    open,
    onOpenChange,
    member,
    others,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    member: TeamMember | null;
    others: Pick<TeamMember, 'id' | 'name'>[];
}) {
    const { t, tr, number } = useI18n();
    const r = t.team.remove;
    const [target, setTarget] = useState(RELEASE);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const change = (value: boolean) => {
        if (busy) return;
        if (!value) {
            setTarget(RELEASE);
            setError(null);
        }
        onOpenChange(value);
    };

    const confirm = () => {
        if (!member) return;
        setBusy(true);
        setError(null);
        router.delete(`/store/team/${member.id}`, {
            data: { move_to: target === RELEASE ? null : Number(target) },
            preserveScroll: true,
            onSuccess: () => {
                setTarget(RELEASE);
                onOpenChange(false);
            },
            onError: (errors) => setError(Object.values(errors)[0] ?? null),
            onFinish: () => setBusy(false),
        });
    };

    return (
        <Dialog open={open && member !== null} onOpenChange={change}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader className="text-start sm:text-start">
                    <span className="bg-danger-soft text-danger mb-1 flex size-11 items-center justify-center rounded-xl">
                        <Trash2 className="size-5" />
                    </span>
                    <DialogTitle className="leading-7">{tr(r.title, { name: member?.name ?? '' })}</DialogTitle>
                    <DialogDescription className="leading-6">{r.description}</DialogDescription>
                </DialogHeader>

                {member && member.products > 0 && (
                    <Field label={tr(r.products, { count: number(member.products) })}>
                        <Select value={target} onValueChange={setTarget}>
                            <SelectTrigger className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={RELEASE}>{r.release}</SelectItem>
                                {others.map((other) => (
                                    <SelectItem key={other.id} value={String(other.id)}>
                                        {tr(r.moveTo, { name: other.name })}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                )}
                {error && (
                    <p role="alert" className="text-danger text-sm">
                        {error}
                    </p>
                )}

                <DialogFooter className="gap-2">
                    <Button variant="outline" disabled={busy} onClick={() => change(false)}>
                        {t.common.cancel}
                    </Button>
                    <Button variant="destructive" disabled={busy} onClick={confirm}>
                        {busy ? <Spinner /> : <Trash2 className="size-4" />}
                        {r.confirm}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

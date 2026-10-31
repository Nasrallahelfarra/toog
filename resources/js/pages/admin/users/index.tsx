import { Link, router } from '@inertiajs/react';
import { KeyRound, MoreHorizontal, Pencil, Plus, Search, Trash2, UserCheck, UserX, Users } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Initials, RoleBadge, UserStatusBadge } from '@/components/admin/badges';
import { FilterSelect } from '@/components/admin/filter-select';
import { ReasonDialog } from '@/components/admin/reason-dialog';
import { ConfirmModal } from '@/components/admin/store-actions';
import { useDebouncedSearch, useFilters } from '@/components/admin/use-filters';
import { PasswordDialog, UserFormDialog } from '@/components/admin/user-dialogs';
import type { AdminUserRow } from '@/components/admin/user-dialogs';
import { EmptyState } from '@/components/mojo/empty-state';
import { FadeIn } from '@/components/mojo/motion';
import { PageBody, PageHeader } from '@/components/mojo/page-header';
import { Pagination } from '@/components/mojo/pagination';
import type { Paginated } from '@/components/mojo/pagination';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

type Props = {
    users: Paginated<AdminUserRow>;
    filters: { search?: string; role?: string; status?: string };
    counts: { all: number; admins: number; owners: number; disabled: number };
    stores: { id: number; name: string }[];
    me: number;
};

type DialogState = { kind: 'create' } | { kind: 'edit' | 'password' | 'disable' | 'enable' | 'delete'; user: AdminUserRow } | null;

/**
 * Validation errors from row actions (e.g. disabling yourself) as a toast.
 */
function showErrors(errors: Record<string, string>) {
    const first = Object.values(errors)[0];

    if (first) {
        toast.error(first);
    }
}

export default function AdminUsers({ users, filters: initial, counts, stores, me }: Props) {
    const { t, tr, number, relative, date } = useI18n();
    const u = t.admin.users;
    const { filters, set } = useFilters('/admin/users', initial);
    const [search, setSearch] = useDebouncedSearch(initial.search ?? '', (v) => set('search', v));
    const [dialog, setDialog] = useState<DialogState>(null);
    const close = () => setDialog(null);
    const target = dialog && 'user' in dialog ? dialog.user : null;

    const segments = [
        { label: u.counts.all, count: counts.all, active: !filters.role && !filters.status, apply: () => router.get('/admin/users', {}, { preserveState: true, replace: true }) },
        { label: u.counts.admins, count: counts.admins, active: filters.role === 'admin', apply: () => set('role', 'admin') },
        { label: u.counts.owners, count: counts.owners, active: filters.role === 'store_owner', apply: () => set('role', 'store_owner') },
        { label: u.counts.disabled, count: counts.disabled, active: filters.status === 'disabled' && !filters.role, apply: () => router.get('/admin/users', { status: 'disabled' }, { preserveState: true, replace: true }) },
    ];

    const post = (url: string, data: Record<string, string> = {}, done?: () => void) =>
        router.post(url, data, { preserveScroll: true, onSuccess: close, onError: showErrors, onFinish: done });

    return (
        <PageBody>
            <PageHeader
                title={u.title}
                description={u.subtitle}
                icon={Users}
                actions={
                    <Button onClick={() => setDialog({ kind: 'create' })}>
                        <Plus className="size-4" />
                        {u.newUser}
                    </Button>
                }
            />

            <FadeIn delay={0.05} className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div className="bg-muted inline-flex w-fit flex-wrap rounded-xl p-1">
                    {segments.map((seg) => (
                        <button
                            key={seg.label}
                            type="button"
                            onClick={seg.apply}
                            className={cn(
                                'flex cursor-pointer items-center gap-2 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                                seg.active ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {seg.label}
                            <span className="num bg-background/70 rounded-md px-1.5 text-xs">{number(seg.count)}</span>
                        </button>
                    ))}
                </div>
                <div className="flex flex-col gap-2 sm:flex-row">
                    <div className="relative sm:w-80">
                        <Search className="text-muted-foreground absolute start-3 top-1/2 size-4 -translate-y-1/2" />
                        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={u.searchPlaceholder} className="ps-9" />
                    </div>
                    <FilterSelect
                        value={filters.status}
                        onChange={(v) => set('status', v)}
                        allLabel={u.allStatuses}
                        options={[
                            { value: 'active', label: t.admin.statuses.active },
                            { value: 'disabled', label: t.admin.statuses.disabled },
                        ]}
                    />
                </div>
            </FadeIn>

            <FadeIn delay={0.1} className="bg-card overflow-hidden rounded-2xl border border-border/70 shadow-[0_1px_2px_rgba(16,24,40,0.04)]">
                {users.data.length === 0 ? (
                    <EmptyState icon={Users} title={u.empty} />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{u.user}</TableHead>
                                <TableHead>{u.role}</TableHead>
                                <TableHead>{u.stores}</TableHead>
                                <TableHead>{u.status}</TableHead>
                                <TableHead>{u.lastLogin}</TableHead>
                                <TableHead className="w-12" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {users.data.map((user) => (
                                <TableRow key={user.id} className={cn(user.status === 'disabled' && 'opacity-75')}>
                                    <TableCell>
                                        <div className="flex items-center gap-3">
                                            <Initials name={user.name} />
                                            <div className="min-w-0">
                                                <p className="flex items-center gap-2 font-medium">
                                                    <span className="max-w-52 truncate">{user.name}</span>
                                                    {user.id === me && <span className="bg-primary/10 text-primary rounded-md px-1.5 text-[11px]">{u.you}</span>}
                                                </p>
                                                <p className="text-muted-foreground max-w-60 truncate text-xs" dir="ltr">
                                                    {user.email}
                                                </p>
                                            </div>
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <RoleBadge role={user.role} />
                                    </TableCell>
                                    <TableCell>
                                        {user.stores.length === 0 ? (
                                            <span className="text-muted-foreground text-xs">{u.noStores}</span>
                                        ) : (
                                            <div className="flex max-w-64 flex-wrap gap-1">
                                                {user.stores.map((s) => (
                                                    <Link key={s.id} href={`/admin/stores/${s.id}`} className="bg-muted hover:bg-muted/70 rounded-md px-2 py-0.5 text-xs">
                                                        {s.name}
                                                    </Link>
                                                ))}
                                            </div>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {user.status === 'disabled' && (user.disabled_reason || user.disabled_at) ? (
                                            <Tooltip>
                                                <TooltipTrigger>
                                                    <UserStatusBadge status={user.status} />
                                                </TooltipTrigger>
                                                <TooltipContent className="max-w-64">
                                                    {user.disabled_at && tr(u.disabledSince, { date: date(user.disabled_at) })}
                                                    {user.disabled_reason && <span className="block">{user.disabled_reason}</span>}
                                                </TooltipContent>
                                            </Tooltip>
                                        ) : (
                                            <UserStatusBadge status={user.status} />
                                        )}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground text-xs">{user.last_login_at ? relative(user.last_login_at) : t.common.never}</TableCell>
                                    <TableCell>
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button variant="ghost" size="icon" className="size-8">
                                                    <MoreHorizontal className="size-4" />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end" className="w-52">
                                                <DropdownMenuItem onSelect={() => setDialog({ kind: 'edit', user })}>
                                                    <Pencil className="size-4" />
                                                    {u.edit}
                                                </DropdownMenuItem>
                                                <DropdownMenuItem onSelect={() => setDialog({ kind: 'password', user })}>
                                                    <KeyRound className="size-4" />
                                                    {u.resetPassword}
                                                </DropdownMenuItem>
                                                {user.id !== me && (
                                                    <>
                                                        <DropdownMenuSeparator />
                                                        {user.status === 'disabled' ? (
                                                            <DropdownMenuItem onSelect={() => setDialog({ kind: 'enable', user })} className="text-success focus:text-success">
                                                                <UserCheck className="size-4" />
                                                                {u.enable}
                                                            </DropdownMenuItem>
                                                        ) : (
                                                            <DropdownMenuItem onSelect={() => setDialog({ kind: 'disable', user })} className="text-warning focus:text-warning">
                                                                <UserX className="size-4" />
                                                                {u.disable}
                                                            </DropdownMenuItem>
                                                        )}
                                                        <DropdownMenuItem onSelect={() => setDialog({ kind: 'delete', user })} className="text-danger focus:text-danger">
                                                            <Trash2 className="size-4" />
                                                            {u.delete}
                                                        </DropdownMenuItem>
                                                    </>
                                                )}
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </FadeIn>

            <Pagination meta={users} />

            <UserFormDialog
                open={dialog?.kind === 'create' || dialog?.kind === 'edit'}
                onOpenChange={(open) => !open && close()}
                user={dialog?.kind === 'edit' ? dialog.user : null}
                stores={stores}
            />
            <PasswordDialog open={dialog?.kind === 'password'} onOpenChange={(open) => !open && close()} user={target} />
            <ReasonDialog
                open={dialog?.kind === 'disable'}
                onOpenChange={(open) => !open && close()}
                title={tr(u.disableTitle, { name: target?.name ?? '' })}
                description={u.disableDescription}
                label={u.disableReason}
                confirmLabel={u.disable}
                onConfirm={(reason, done) => post(`/admin/users/${target?.id}/disable`, { reason }, done)}
            />
            <ConfirmModal
                open={dialog?.kind === 'enable'}
                onOpenChange={(open) => !open && close()}
                title={tr(u.enableTitle, { name: target?.name ?? '' })}
                description={u.enableDescription}
                confirmLabel={u.enable}
                onConfirm={(done) => post(`/admin/users/${target?.id}/enable`, {}, done)}
            />
            <ConfirmModal
                open={dialog?.kind === 'delete'}
                onOpenChange={(open) => !open && close()}
                title={tr(u.deleteTitle, { name: target?.name ?? '' })}
                description={u.deleteDescription}
                confirmLabel={u.delete}
                destructive
                onConfirm={(done) =>
                    router.delete(`/admin/users/${target?.id}`, { preserveScroll: true, onSuccess: close, onError: showErrors, onFinish: done })
                }
            />
        </PageBody>
    );
}

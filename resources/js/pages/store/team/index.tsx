import { Link, router } from '@inertiajs/react';
import { Clock, PackageCheck, Plus, Search, ShieldCheck, Trophy, UsersRound } from 'lucide-react';
import { motion, MotionConfig } from 'motion/react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Initials, UserStatusBadge } from '@/components/admin/badges';
import { ConfirmModal } from '@/components/admin/store-actions';
import { EmptyState } from '@/components/mojo/empty-state';
import { FadeIn } from '@/components/mojo/motion';
import { PageBody, PageHeader } from '@/components/mojo/page-header';
import { SectionCard } from '@/components/mojo/section-card';
import { AccessChip, MemberMenu } from '@/components/store/team/member-actions';
import type { MemberAction } from '@/components/store/team/member-actions';
import { MemberAccessDialog, MemberFormDialog, MemberPasswordDialog, RemoveMemberDialog } from '@/components/store/team/member-dialogs';
import { TeamSecurityCard, TwoFactorMark } from '@/components/store/team/team-security';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

/** A member as the staff page shows them: who they are, not how they sell. */
type RosterMember = {
    id: number;
    name: string;
    email: string;
    status: 'active' | 'disabled';
    two_factor: boolean;
    last_login_at: string | null;
    joined_at: string | null;
    permissions: string[];
    products: number;
};

type Props = {
    members: RosterMember[];
    /** The pages the viewer may hand out. */
    sections: Record<string, string[]>;
    me: number;
    requireTwoFactor: boolean;
    /** The owner alone sets the team-wide two-factor rule. */
    canSetPolicy: boolean;
    canReport: boolean;
};

type DialogState = { kind: 'create' } | { kind: MemberAction; member: RosterMember } | null;

/**
 * The staff: add members, choose the pages each one opens, and look after
 * their accounts. How their products sell lives in the staff report.
 */
export default function TeamStaff({ members, sections, me, requireTwoFactor, canSetPolicy, canReport }: Props) {
    const { t, tr, number, relative } = useI18n();
    const m = t.team;
    const r = m.roster;
    const [dialog, setDialog] = useState<DialogState>(null);
    const [search, setSearch] = useState('');
    const close = () => setDialog(null);
    const target = dialog && 'member' in dialog ? dialog.member : null;
    const totalPages = Object.values(sections).flat().length;
    // Only the pages offered here count: a page switched off for now (or one
    // this manager does not hold) stays on the member's record untouched.
    const offered = new Set(Object.values(sections).flat());
    const shown = <T extends { permissions: string[] }>(member: T): T => ({ ...member, permissions: member.permissions.filter((key) => offered.has(key)) });

    const visible = useMemo(() => {
        const needle = search.trim().toLowerCase();

        return needle ? members.filter((member) => member.name.toLowerCase().includes(needle) || member.email.toLowerCase().includes(needle)) : members;
    }, [members, search]);

    const post = (url: string, done: () => void) =>
        router.post(url, {}, { preserveScroll: true, onSuccess: close, onError: (errors) => toast.error(Object.values(errors)[0]), onFinish: done });

    const addButton = (
        <Button onClick={() => setDialog({ kind: 'create' })}>
            <Plus className="size-4" />
            {m.addMember}
        </Button>
    );

    return (
        <MotionConfig reducedMotion="user">
            <PageBody>
                <PageHeader
                    title={m.title}
                    description={m.subtitle}
                    icon={UsersRound}
                    actions={
                        <>
                            {canReport && members.length > 0 && (
                                <Button asChild variant="outline">
                                    <Link href="/store/team/report" prefetch>
                                        <Trophy className="size-4" />
                                        {r.openReport}
                                    </Link>
                                </Button>
                            )}
                            {addButton}
                        </>
                    }
                />

                {members.length === 0 ? (
                    <SectionCard>
                        <EmptyState icon={UsersRound} title={m.empty.title} description={m.empty.description} action={addButton} />
                    </SectionCard>
                ) : (
                    <>
                        {canSetPolicy && (
                            <FadeIn>
                                <TeamSecurityCard members={members} required={requireTwoFactor} />
                            </FadeIn>
                        )}

                        {members.length > 4 && (
                            <div className="relative sm:w-80">
                                <Search className="text-muted-foreground pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2" />
                                <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={m.filters.search} className="h-10 ps-9 sm:h-9" />
                            </div>
                        )}

                        {visible.length === 0 ? (
                            <SectionCard>
                                <EmptyState icon={Search} title={m.filters.empty} />
                            </SectionCard>
                        ) : (
                            <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                                {visible.map((member, index) => {
                                    const self = member.id === me;
                                    const pick = (kind: MemberAction) => setDialog({ kind, member });

                                    return (
                                        <motion.li
                                            key={member.id}
                                            initial={{ opacity: 0, y: 8 }}
                                            animate={{ opacity: 1, y: 0 }}
                                            transition={{ duration: 0.25, delay: Math.min(index, 8) * 0.04 }}
                                            className={cn(
                                                'bg-card flex flex-col gap-4 rounded-2xl border border-border/70 p-4 shadow-[0_1px_2px_rgba(16,24,40,0.04)]',
                                                member.status === 'disabled' && 'opacity-75',
                                            )}
                                        >
                                            <div className="flex items-start gap-3">
                                                <Initials name={member.name} className="size-11 rounded-xl text-sm" />
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex flex-wrap items-center gap-1.5">
                                                        <span className="truncate font-semibold" dir="auto">
                                                            {member.name}
                                                        </span>
                                                        {self && <span className="bg-primary/10 text-primary rounded-full px-2 py-px text-[11px] font-semibold">{r.me}</span>}
                                                        <TwoFactorMark on={member.two_factor} onLabel={m.security.on} offLabel={m.security.off} />
                                                        {member.status === 'disabled' && <UserStatusBadge status={member.status} />}
                                                    </div>
                                                    <p className="text-muted-foreground truncate text-xs" dir="ltr">
                                                        {member.email}
                                                    </p>
                                                    <AccessChip granted={shown(member).permissions} total={totalPages} onClick={self ? undefined : () => pick('access')} />
                                                </div>
                                                {!self && <MemberMenu member={member} onPick={pick} showProducts={canReport} className="-me-1 -mt-1 size-10 sm:size-8" />}
                                            </div>

                                            <div className="text-muted-foreground mt-auto flex flex-wrap items-center gap-x-4 gap-y-1 border-t pt-3 text-xs">
                                                <span className="inline-flex items-center gap-1.5">
                                                    <PackageCheck className="size-3.5" />
                                                    <span className="num">{member.products > 0 ? tr(r.products, { count: number(member.products) }) : r.noProducts}</span>
                                                </span>
                                                <span className="inline-flex items-center gap-1.5">
                                                    <Clock className="size-3.5" />
                                                    {member.last_login_at ? tr(r.lastLogin, { when: relative(member.last_login_at) }) : r.neverLoggedIn}
                                                </span>
                                                {self ? (
                                                    <span className="ms-auto">{r.selfHint}</span>
                                                ) : (
                                                    <Button variant="outline" size="sm" className="ms-auto h-9 sm:h-8" onClick={() => pick('access')}>
                                                        <ShieldCheck className="size-4" />
                                                        {m.access.button}
                                                    </Button>
                                                )}
                                            </div>
                                        </motion.li>
                                    );
                                })}
                            </ul>
                        )}
                    </>
                )}

                <MemberFormDialog
                    open={dialog?.kind === 'create' || dialog?.kind === 'edit'}
                    onOpenChange={(open) => !open && close()}
                    member={dialog?.kind === 'edit' ? dialog.member : null}
                    sections={sections}
                />
                <MemberAccessDialog
                    open={dialog?.kind === 'access'}
                    onOpenChange={(open) => !open && close()}
                    member={dialog?.kind === 'access' ? shown(dialog.member) : null}
                    sections={sections}
                />
                <MemberPasswordDialog open={dialog?.kind === 'password'} onOpenChange={(open) => !open && close()} member={target} />
                <ConfirmModal
                    open={dialog?.kind === 'disable'}
                    onOpenChange={(open) => !open && close()}
                    title={tr(m.disable.title, { name: target?.name ?? '' })}
                    description={m.disable.description}
                    confirmLabel={t.team.actions.disable}
                    destructive
                    onConfirm={(done) => post(`/store/team/${target?.id}/disable`, done)}
                />
                <ConfirmModal
                    open={dialog?.kind === 'enable'}
                    onOpenChange={(open) => !open && close()}
                    title={tr(m.enable.title, { name: target?.name ?? '' })}
                    description={m.enable.description}
                    confirmLabel={t.team.actions.enable}
                    onConfirm={(done) => post(`/store/team/${target?.id}/enable`, done)}
                />
                <ConfirmModal
                    open={dialog?.kind === 'twoFactor'}
                    onOpenChange={(open) => !open && close()}
                    title={tr(m.resetTwoFactor.title, { name: target?.name ?? '' })}
                    description={m.resetTwoFactor.description}
                    confirmLabel={t.team.actions.resetTwoFactor}
                    destructive
                    onConfirm={(done) =>
                        router.delete(`/store/team/${target?.id}/two-factor`, {
                            preserveScroll: true,
                            onSuccess: close,
                            onError: (errors) => toast.error(Object.values(errors)[0]),
                            onFinish: done,
                        })
                    }
                />
                <RemoveMemberDialog
                    open={dialog?.kind === 'remove'}
                    onOpenChange={(open) => !open && close()}
                    member={dialog?.kind === 'remove' ? dialog.member : null}
                    others={members.filter((member) => member.id !== target?.id)}
                />
            </PageBody>
        </MotionConfig>
    );
}

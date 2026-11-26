import { Link } from '@inertiajs/react';
import { KeyRound, MoreHorizontal, PackageCheck, Pencil, ShieldCheck, ShieldOff, Trash2, UserCheck, UserX } from 'lucide-react';
import type { TeamMember } from '@/components/store/team/shared';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useI18n } from '@/hooks/use-i18n';
import { STORE_SECTIONS } from '@/lib/store-sections';
import { cn } from '@/lib/utils';

export type MemberAction = 'access' | 'edit' | 'password' | 'twoFactor' | 'disable' | 'enable' | 'remove';

/**
 * "6 of 14 pages" as a button that opens the member's pages, with the page
 * names on hover. Where the manager sees what a member can open is also
 * where they change it; without `onClick` it only reads.
 */
export function AccessChip({ granted, total, onClick }: { granted: string[]; total: number; onClick?: () => void }) {
    const { t, tr } = useI18n();
    const a = t.team.access;
    const shown = granted.length;
    const label = shown === 0 ? a.none : shown >= total ? a.all : tr(a.some, { count: shown, total });
    const partial = shown > 0 && shown < total;
    const names = granted.map((key) => (STORE_SECTIONS[key] ? t.nav[STORE_SECTIONS[key].label] : key)).join(' · ');

    if (!onClick) {
        return (
            <span className="text-muted-foreground mt-1 inline-flex items-center gap-1 text-xs" title={partial ? names : undefined}>
                <ShieldCheck className="size-3" />
                <span className="num">{label}</span>
            </span>
        );
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <button
                    type="button"
                    onClick={onClick}
                    className="group/access text-muted-foreground hover:border-primary/40 hover:bg-primary/5 hover:text-primary focus-visible:ring-ring mt-1 inline-flex cursor-pointer items-center gap-1 rounded-full border px-2 py-0.5 text-xs transition-colors outline-none focus-visible:ring-2 pointer-coarse:min-h-9 pointer-coarse:px-3"
                >
                    <ShieldCheck className="size-3" />
                    <span className="num">{label}</span>
                    <Pencil className="size-3 opacity-40 transition-opacity group-hover/access:opacity-100" />
                </button>
            </TooltipTrigger>
            <TooltipContent className="max-w-64">
                {partial && <span className="block">{names}</span>}
                <span className={cn('block', partial && 'mt-1 opacity-70')}>{a.change}</span>
            </TooltipContent>
        </Tooltip>
    );
}

/**
 * Everything the manager can do to one member's account. "Products and
 * sales" opens the member's page, which belongs to the report.
 */
export function MemberMenu({
    member,
    onPick,
    showProducts = true,
    className,
}: {
    member: Pick<TeamMember, 'id' | 'status' | 'two_factor'>;
    onPick: (kind: MemberAction) => void;
    showProducts?: boolean;
    className?: string;
}) {
    const { t } = useI18n();
    const a = t.team.actions;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className={cn('size-8', className)} aria-label={t.common.actions}>
                    <MoreHorizontal className="size-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-60">
                {showProducts && (
                    <DropdownMenuItem asChild>
                        <Link href={`/store/team/${member.id}`}>
                            <PackageCheck className="size-4" />
                            {a.products}
                        </Link>
                    </DropdownMenuItem>
                )}
                <DropdownMenuItem onSelect={() => onPick('access')}>
                    <ShieldCheck className="size-4" />
                    {a.access}
                </DropdownMenuItem>
                <DropdownMenuItem onSelect={() => onPick('edit')}>
                    <Pencil className="size-4" />
                    {a.edit}
                </DropdownMenuItem>
                <DropdownMenuItem onSelect={() => onPick('password')}>
                    <KeyRound className="size-4" />
                    {a.password}
                </DropdownMenuItem>
                {member.two_factor && (
                    <DropdownMenuItem onSelect={() => onPick('twoFactor')}>
                        <ShieldOff className="size-4" />
                        {a.resetTwoFactor}
                    </DropdownMenuItem>
                )}
                <DropdownMenuSeparator />
                {member.status === 'disabled' ? (
                    <DropdownMenuItem onSelect={() => onPick('enable')} className="text-success focus:text-success">
                        <UserCheck className="size-4" />
                        {a.enable}
                    </DropdownMenuItem>
                ) : (
                    <DropdownMenuItem onSelect={() => onPick('disable')} className="text-warning focus:text-warning">
                        <UserX className="size-4" />
                        {a.disable}
                    </DropdownMenuItem>
                )}
                <DropdownMenuItem onSelect={() => onPick('remove')} className="text-danger focus:text-danger">
                    <Trash2 className="size-4" />
                    {a.remove}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

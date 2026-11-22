import { router } from '@inertiajs/react';
import { ShieldCheck, ShieldOff } from 'lucide-react';
import { useState } from 'react';
import { Meter } from '@/components/mojo/meter';
import { SectionCard } from '@/components/mojo/section-card';
import type { TeamMember } from '@/components/store/team/shared';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

/**
 * A shield beside a member's name: green when they sign in with an
 * authenticator app code, faint when a password alone gets them in.
 */
export function TwoFactorMark({ on, onLabel, offLabel, className }: { on: boolean; onLabel: string; offLabel: string; className?: string }) {
    const Icon = on ? ShieldCheck : ShieldOff;

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span
                    role="img"
                    aria-label={on ? onLabel : offLabel}
                    className={cn('inline-flex shrink-0', on ? 'text-success' : 'text-muted-foreground/50', className)}
                >
                    <Icon className="size-3.5" />
                </span>
            </TooltipTrigger>
            <TooltipContent>{on ? onLabel : offLabel}</TooltipContent>
        </Tooltip>
    );
}

/**
 * How many on the team sign in with two-factor, who has not yet, and the
 * owner's switch that makes it a must for every member.
 */
export function TeamSecurityCard({ members, required }: { members: TeamMember[]; required: boolean }) {
    const { t, tr, number } = useI18n();
    const m = t.team.security;
    const [saving, setSaving] = useState(false);
    const active = members.filter((member) => member.status === 'active');
    const protectedCount = active.filter((member) => member.two_factor).length;
    const missing = active.filter((member) => !member.two_factor).map((member) => member.name);
    const share = active.length > 0 ? (protectedCount / active.length) * 100 : 0;

    const toggle = (value: boolean) =>
        router.put(
            '/store/team/security',
            { require_two_factor: value },
            { preserveScroll: true, preserveState: true, onStart: () => setSaving(true), onFinish: () => setSaving(false) },
        );

    return (
        <SectionCard title={m.title} description={m.description} icon={ShieldCheck}>
            <div className="grid gap-5 md:grid-cols-[1fr_auto] md:items-center">
                <div className="min-w-0 space-y-2">
                    <div className="flex items-baseline justify-between gap-3">
                        <span className="num text-sm font-semibold">{tr(m.count, { count: number(protectedCount), total: number(active.length) })}</span>
                    </div>
                    <Meter value={share} tone={share >= 100 ? 'success' : 'gold'} height={6} />
                    <p className="text-muted-foreground truncate text-xs" dir="auto">
                        {missing.length === 0 ? m.all : tr(m.missing, { names: missing.join('، ') })}
                    </p>
                </div>

                <div className="bg-muted/40 flex items-start gap-3 rounded-xl border p-3 md:max-w-sm">
                    <Switch id="require-two-factor" checked={required} disabled={saving} onCheckedChange={toggle} className="mt-0.5" />
                    <div className="space-y-0.5">
                        <Label htmlFor="require-two-factor" className="cursor-pointer text-sm font-medium">
                            {m.require}
                        </Label>
                        <p className="text-muted-foreground text-xs leading-relaxed">{m.requireHint}</p>
                    </div>
                </div>
            </div>
        </SectionCard>
    );
}

import { CircleAlert, CircleCheck, CircleDashed, PauseCircle, Plug, PlugZap, ShieldCheck, Store } from 'lucide-react';
import { toneClasses } from '@/components/mojo/tone';
import type { Tone } from '@/components/mojo/tone';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

function Pill({ tone, icon: Icon, children, className }: { tone: Tone; icon?: typeof Store; children: React.ReactNode; className?: string }) {
    return (
        <span
            className={cn(
                'inline-flex w-fit items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap',
                toneClasses[tone].soft,
                toneClasses[tone].text,
                className,
            )}
        >
            {Icon && <Icon className="size-3.5" />}
            {children}
        </span>
    );
}

export function StoreStatusBadge({ status }: { status: string }) {
    const { t } = useI18n();

    return status === 'suspended' ? (
        <Pill tone="danger" icon={PauseCircle}>
            {t.admin.statuses.suspended}
        </Pill>
    ) : (
        <Pill tone="success" icon={CircleCheck}>
            {t.admin.statuses.active}
        </Pill>
    );
}

export function ConnectionBadge({ status }: { status: string }) {
    const { t } = useI18n();
    const map: Record<string, { tone: Tone; icon: typeof Store }> = {
        connected: { tone: 'info', icon: PlugZap },
        error: { tone: 'warning', icon: CircleAlert },
        disconnected: { tone: 'neutral', icon: Plug },
    };
    const item = map[status] ?? map.disconnected;

    return (
        <Pill tone={item.tone} icon={item.icon}>
            {t.admin.connections[status as keyof typeof t.admin.connections] ?? status}
        </Pill>
    );
}

export function UserStatusBadge({ status }: { status: string }) {
    const { t } = useI18n();

    return status === 'disabled' ? (
        <Pill tone="danger" icon={CircleDashed}>
            {t.admin.statuses.disabled}
        </Pill>
    ) : (
        <Pill tone="success" icon={CircleCheck}>
            {t.admin.statuses.active}
        </Pill>
    );
}

export function RoleBadge({ role }: { role: string }) {
    const { t } = useI18n();

    return role === 'admin' ? (
        <Pill tone="gold" icon={ShieldCheck}>
            {t.admin.roles.admin}
        </Pill>
    ) : (
        <Pill tone="navy" icon={Store}>
            {t.admin.roles.store_owner}
        </Pill>
    );
}

/**
 * Two-letter avatar with a stable tint per name.
 */
export function Initials({ name, className }: { name: string; className?: string }) {
    const tones: Tone[] = ['navy', 'gold', 'success', 'info', 'warning'];
    let sum = 0;
    for (let i = 0; i < name.length; i++) {
        sum += name.charCodeAt(i);
    }
    const tone = tones[sum % tones.length];
    const letters = name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0])
        .join('');

    return (
        <span
            className={cn(
                'flex size-9 shrink-0 items-center justify-center rounded-xl text-xs font-semibold',
                toneClasses[tone].soft,
                toneClasses[tone].text,
                className,
            )}
        >
            {letters}
        </span>
    );
}

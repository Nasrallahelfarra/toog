import { Link, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { motion } from 'motion/react';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';

export type NavLink = {
    title: string;
    href: string;
    icon: LucideIcon;
    exact?: boolean;
    badge?: number;
    /** Paths under href that belong to another item. */
    exclude?: string[];
    /** Small status dot, e.g. the Zid connection state. */
    dot?: 'success' | 'danger' | 'muted';
};

export type NavGroup = {
    title: string;
    items: NavLink[];
};

function isActive(current: string, item: NavLink): boolean {
    const path = current.split('?')[0];

    if (item.exclude?.some((p) => path.startsWith(p))) {
        return false;
    }

    return item.exact ? path === item.href : path === item.href || path.startsWith(`${item.href}/`);
}

/**
 * Grouped navigation with a gold indicator that glides to the active item.
 */
export function NavMain({ groups }: { groups: NavGroup[] }) {
    const { url } = usePage();

    return (
        <>
            {groups.map((group) => (
                <SidebarGroup key={group.title} className="px-2 py-1.5">
                    <SidebarGroupLabel className="text-sidebar-foreground/45 text-[11px] font-medium tracking-wide">
                        {group.title}
                    </SidebarGroupLabel>
                    <SidebarMenu className="gap-0.5">
                        {group.items.map((item) => {
                            const active = isActive(url, item);

                            return (
                                <SidebarMenuItem key={item.href} className="relative">
                                    {active && (
                                        <motion.span
                                            layoutId="nav-indicator"
                                            className="bg-sidebar-primary absolute inset-y-1.5 start-0 z-10 w-[3px] rounded-full group-data-[collapsible=icon]:hidden"
                                            transition={{ type: 'spring', stiffness: 380, damping: 32 }}
                                        />
                                    )}
                                    <SidebarMenuButton
                                        asChild
                                        isActive={active}
                                        tooltip={{ children: item.title }}
                                        className="text-sidebar-foreground/75 data-[active=true]:text-sidebar-accent-foreground [&>svg]:size-[18px] data-[active=true]:[&>svg]:text-sidebar-primary"
                                    >
                                        <Link href={item.href} prefetch>
                                            <item.icon />
                                            <span>{item.title}</span>
                                            {item.dot && (
                                                <span
                                                    className={
                                                        item.dot === 'success'
                                                            ? 'bg-success ms-auto size-2 shrink-0 rounded-full shadow-[0_0_0_3px_color-mix(in_oklch,var(--success)_25%,transparent)]'
                                                            : item.dot === 'danger'
                                                              ? 'bg-danger ms-auto size-2 shrink-0 animate-pulse rounded-full'
                                                              : 'bg-sidebar-foreground/35 ms-auto size-2 shrink-0 rounded-full'
                                                    }
                                                />
                                            )}
                                        </Link>
                                    </SidebarMenuButton>
                                    {item.badge ? (
                                        <SidebarMenuBadge className="bg-sidebar-primary text-sidebar-primary-foreground rounded-full px-1.5 text-[10px]">
                                            {item.badge > 99 ? '99+' : item.badge}
                                        </SidebarMenuBadge>
                                    ) : null}
                                </SidebarMenuItem>
                            );
                        })}
                    </SidebarMenu>
                </SidebarGroup>
            ))}
        </>
    );
}

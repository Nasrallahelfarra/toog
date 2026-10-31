import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';
import { Palette, ShieldCheck, UserRound } from 'lucide-react';
import { useI18n } from '@/hooks/use-i18n';

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const { t } = useI18n();

    const sidebarNavItems: NavItem[] = [
        { title: t.account.profile, href: edit(), icon: UserRound },
        { title: t.account.security, href: editSecurity(), icon: ShieldCheck },
        { title: t.account.appearance, href: editAppearance(), icon: Palette },
    ];

    return (
        <div className="mx-auto w-full max-w-5xl px-4 py-6 md:px-8">
            <Heading title={t.account.title} description={t.account.description} />

            <div className="flex flex-col gap-8 lg:flex-row lg:gap-12">
                <aside className="w-full max-w-xl lg:w-48">
                    <nav
                        className="flex flex-col gap-1"
                        aria-label={t.account.title}
                    >
                        {sidebarNavItems.map((item, index) => (
                            <Button
                                key={`${toUrl(item.href)}-${index}`}
                                size="sm"
                                variant="ghost"
                                asChild
                                className={cn('w-full justify-start gap-2', {
                                    'bg-muted text-foreground': isCurrentOrParentUrl(item.href),
                                })}
                            >
                                <Link href={item.href}>
                                    {item.icon && (
                                        <item.icon className="h-4 w-4" />
                                    )}
                                    {item.title}
                                </Link>
                            </Button>
                        ))}
                    </nav>
                </aside>

                <Separator className="my-6 lg:hidden" />

                <div className="flex-1 md:max-w-2xl">
                    <section className="max-w-xl space-y-12">
                        {children}
                    </section>
                </div>
            </div>
        </div>
    );
}

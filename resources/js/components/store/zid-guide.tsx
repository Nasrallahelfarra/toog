import { BookOpen, CheckCircle2, KeyRound, Lock, PackageCheck, PackageX } from 'lucide-react';
import { SectionCard } from '@/components/mojo/section-card';
import { Stagger, StaggerItem } from '@/components/mojo/motion';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

/**
 * Step-by-step explanation of the Zid connection: what the owner does,
 * what happens after, what is imported and which permissions are needed.
 * The current step is highlighted from the connection state.
 */
export function ZidGuide({ status, hasData, checkOk }: { status: string; hasData: boolean; checkOk: boolean | null }) {
    const { t } = useI18n();
    const g = t.settings.zidGuide;

    // 0-1: not connected yet · 2: first sync · 3: check (until it passes) · 5: all done
    const current = status !== 'connected' ? 0 : !hasData ? 2 : checkOk !== true ? 3 : 5;

    return (
        <SectionCard title={g.title} description={g.description} icon={BookOpen} className="h-full">
            <Stagger className="space-y-1">
                {g.steps.map((step, i) => {
                    const done = i < current;
                    const active = i === current || (current === 0 && i === 1);

                    return (
                        <StaggerItem key={step.title} className="relative flex gap-3 pb-4 last:pb-0">
                            {i < g.steps.length - 1 && (
                                <span className={cn('absolute start-[15px] top-8 bottom-0 w-px', done ? 'bg-success/50' : 'bg-border')} />
                            )}
                            <span
                                className={cn(
                                    'relative z-10 flex size-8 shrink-0 items-center justify-center rounded-xl text-sm font-semibold',
                                    done && 'bg-success text-white',
                                    !done && active && 'bg-primary text-primary-foreground shadow-md',
                                    !done && !active && 'bg-muted text-muted-foreground',
                                )}
                            >
                                {done ? <CheckCircle2 className="size-4" /> : i + 1}
                            </span>
                            <div className="space-y-0.5 pt-1">
                                <div className={cn('text-sm font-semibold', !done && !active && 'text-foreground/70')}>{step.title}</div>
                                <p className="text-muted-foreground text-xs leading-relaxed">{step.body}</p>
                            </div>
                        </StaggerItem>
                    );
                })}
            </Stagger>

            <div className="mt-5 grid gap-3 sm:grid-cols-2">
                <div className="bg-success-soft/60 space-y-1.5 rounded-xl p-3">
                    <div className="text-success flex items-center gap-1.5 text-xs font-semibold">
                        <PackageCheck className="size-4" />
                        {g.importsTitle}
                    </div>
                    <ul className="text-foreground/80 space-y-1 text-xs leading-relaxed">
                        {g.imports.map((item) => (
                            <li key={item}>• {item}</li>
                        ))}
                    </ul>
                </div>
                <div className="bg-muted/70 space-y-1.5 rounded-xl p-3">
                    <div className="text-muted-foreground flex items-center gap-1.5 text-xs font-semibold">
                        <PackageX className="size-4" />
                        {g.notImportedTitle}
                    </div>
                    <ul className="text-foreground/80 space-y-1 text-xs leading-relaxed">
                        {g.notImported.map((item) => (
                            <li key={item}>• {item}</li>
                        ))}
                    </ul>
                </div>
            </div>

            <div className="mt-3 flex flex-wrap items-center gap-2 text-xs">
                <span className="text-muted-foreground flex items-center gap-1.5 font-medium">
                    <KeyRound className="size-3.5" />
                    {g.permissionsTitle}:
                </span>
                {g.permissions.map((p) => (
                    <span key={p} className="bg-primary/8 text-primary rounded-full px-2.5 py-0.5 font-medium dark:bg-primary/15">
                        {p}
                    </span>
                ))}
                <span className="text-muted-foreground flex items-center gap-1">
                    <Lock className="size-3" />
                    {g.readOnly}
                </span>
            </div>
        </SectionCard>
    );
}

import { router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, CircleDashed, Loader2, ShieldCheck, Stethoscope, Wrench, XCircle } from 'lucide-react';
import { AnimatePresence, motion } from 'motion/react';
import { useState } from 'react';
import { EmptyState } from '@/components/mojo/empty-state';
import { SectionCard } from '@/components/mojo/section-card';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

type CheckItem = { key: string; status: 'pass' | 'warn' | 'fail' | 'skip'; params: Record<string, string | number | null> };

export type ZidCheckResult = {
    ok: boolean;
    checked_at: string;
    counts: { pass: number; warn: number; fail: number };
    checks: CheckItem[];
};

const statusStyle = {
    pass: { icon: CheckCircle2, className: 'text-success', bg: 'bg-success-soft' },
    warn: { icon: AlertTriangle, className: 'text-warning', bg: 'bg-warning-soft' },
    fail: { icon: XCircle, className: 'text-danger', bg: 'bg-danger-soft' },
    skip: { icon: CircleDashed, className: 'text-muted-foreground', bg: 'bg-muted' },
};

/**
 * The connection health check: one row per step with its result, the
 * reason, and how to fix it. Runs automatically after connecting; the
 * owner can re-run it at any time.
 */
export function ZidCheck({ check, connected, canConfigure }: { check: ZidCheckResult | null; connected: boolean; canConfigure: boolean }) {
    const { t, tr, relative, number } = useI18n();
    const c = t.settings.zidCheck;
    const [running, setRunning] = useState(false);

    const run = () =>
        router.post('/store/integrations/zid/check', {}, {
            preserveScroll: true,
            onStart: () => setRunning(true),
            onFinish: () => setRunning(false),
        });

    const verdict = !check
        ? null
        : check.counts.fail > 0
          ? { label: c.hasIssues, className: 'bg-danger-soft text-danger', icon: XCircle }
          : check.counts.warn > 0
            ? { label: c.hasWarnings, className: 'bg-warning-soft text-warning', icon: AlertTriangle }
            : { label: c.allGood, className: 'bg-success-soft text-success', icon: ShieldCheck };

    // Message for one row, filled with its parameters.
    const message = (item: CheckItem): string => {
        const texts = c.items[item.key as keyof typeof c.items] as Record<string, string> | undefined;

        if (!texts || item.status === 'skip') {
            return '';
        }

        const p = item.params ?? {};
        const vars = {
            expires_at: p.expires_at ?? '',
            store: p.store_name ?? p.store_id ?? '—',
            http: p.http ?? '—',
            with: number(p.with_lines ?? 0),
            sample: number(p.sample ?? 0),
            products: number(p.products ?? 0),
            sales: number(p.sales_rows ?? 0),
            error: p.error ?? '',
            winner: number(p.winner ?? 0),
            average: number(p.average ?? 0),
            stagnant: number(p.stagnant ?? 0),
            at_risk: number(p.at_risk ?? 0),
        } as Record<string, string | number>;

        let key = item.status as string;

        if (item.status === 'warn' && p.reason) {
            const specific = `warn${String(p.reason).charAt(0).toUpperCase()}${String(p.reason).slice(1).replace(/_(\w)/g, (_, ch: string) => ch.toUpperCase())}`;
            key = texts[specific] ? specific : 'warn';
        }

        return tr(texts[key] ?? '', vars);
    };

    const fix = (item: CheckItem): string | null => {
        if (item.status !== 'fail' && item.status !== 'warn') {
            return null;
        }

        const texts = c.items[item.key as keyof typeof c.items] as Record<string, string> | undefined;

        return (item.key === 'app' && canConfigure ? texts?.fixAdmin : texts?.fix) ?? null;
    };

    return (
        <SectionCard
            title={c.title}
            description={c.description}
            icon={Stethoscope}
            action={
                <Button size="sm" variant={check && !check.ok ? 'default' : 'outline'} onClick={run} disabled={running || !connected}>
                    {running ? <Loader2 className="size-4 animate-spin" /> : <Stethoscope className="size-4" />}
                    {running ? c.running : c.run}
                </Button>
            }
        >
            {!check ? (
                <EmptyState icon={Stethoscope} title={c.never} description={connected ? c.neverHint : c.needsConnection} className="py-8" />
            ) : (
                <div className="space-y-4">
                    {verdict && (
                        <motion.div
                            key={check.checked_at}
                            initial={{ opacity: 0, y: 6 }}
                            animate={{ opacity: 1, y: 0 }}
                            className={cn('flex flex-wrap items-center justify-between gap-2 rounded-xl px-4 py-3', verdict.className)}
                        >
                            <span className="flex items-center gap-2 font-semibold">
                                <verdict.icon className="size-5" />
                                {verdict.label}
                            </span>
                            <span className="text-xs opacity-80">
                                {tr(c.summary, { pass: check.counts.pass, warn: check.counts.warn, fail: check.counts.fail })} ·{' '}
                                {tr(c.checkedAt, { time: relative(check.checked_at) })}
                            </span>
                        </motion.div>
                    )}

                    <ul className="divide-border/60 divide-y">
                        <AnimatePresence initial={false}>
                            {check.checks.map((item, i) => {
                                const style = statusStyle[item.status];
                                const texts = c.items[item.key as keyof typeof c.items];
                                const text = message(item);
                                const remedy = fix(item);

                                return (
                                    <motion.li
                                        key={`${check.checked_at}-${item.key}`}
                                        initial={{ opacity: 0, x: 8 }}
                                        animate={{ opacity: 1, x: 0 }}
                                        transition={{ delay: i * 0.04 }}
                                        className="flex gap-3 py-3"
                                    >
                                        <span className={cn('flex size-8 shrink-0 items-center justify-center rounded-lg', style.bg)}>
                                            <style.icon className={cn('size-4', style.className)} />
                                        </span>
                                        <div className="min-w-0 flex-1 space-y-1">
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <span className="text-sm font-medium">{texts?.title ?? item.key}</span>
                                                <span className={cn('text-xs font-medium', style.className)}>{c.status[item.status]}</span>
                                            </div>
                                            {text && <p className="text-muted-foreground text-xs leading-relaxed break-words">{text}</p>}
                                            {remedy && (
                                                <p className="bg-muted/60 flex items-start gap-1.5 rounded-lg px-2.5 py-1.5 text-xs leading-relaxed">
                                                    <Wrench className="text-primary mt-0.5 size-3.5 shrink-0" />
                                                    <span>
                                                        <strong className="font-semibold">{c.fix}: </strong>
                                                        {remedy}
                                                    </span>
                                                </p>
                                            )}
                                        </div>
                                    </motion.li>
                                );
                            })}
                        </AnimatePresence>
                    </ul>
                </div>
            )}
        </SectionCard>
    );
}

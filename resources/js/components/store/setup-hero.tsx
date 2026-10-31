import { Link, router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, FileSpreadsheet, Link2, Loader2, PlugZap, RefreshCw, Settings } from 'lucide-react';
import { motion } from 'motion/react';
import { useEffect, useState } from 'react';
import { Stagger, StaggerItem } from '@/components/mojo/motion';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

export type SetupState = {
    state: 'connect' | 'syncing' | 'empty';
    connection_status: 'connected' | 'disconnected' | 'error';
    zid_configured: boolean;
    can_configure: boolean;
    run: { status: string; stats: { products?: number; orders?: number }; error: string | null; started_at: string | null } | null;
};

/**
 * First-run screen for a store with no data yet: shows the Zid connection
 * status up front and walks the owner through connecting, then follows the
 * first import live until the dashboard has something to show.
 */
export function SetupHero({ setup }: { setup: SetupState }) {
    const { t, tr, relative, number } = useI18n();
    const s = t.dashboard.setup;
    const [syncing, setSyncing] = useState(false);
    const error = setup.connection_status === 'error';

    // While the first import runs, refresh every few seconds; the full
    // dashboard replaces this screen as soon as products arrive.
    useEffect(() => {
        if (setup.state !== 'syncing') {
            return;
        }

        const timer = window.setInterval(() => router.reload(), 4000);

        return () => window.clearInterval(timer);
    }, [setup.state]);

    const status =
        setup.state === 'syncing'
            ? { label: s.statusSyncing, className: 'bg-info-soft text-info', dot: 'bg-info' }
            : setup.state === 'empty'
              ? { label: s.statusConnected, className: 'bg-success-soft text-success', dot: 'bg-success' }
              : error
                ? { label: s.statusError, className: 'bg-danger-soft text-danger', dot: 'bg-danger' }
                : { label: s.statusDisconnected, className: 'bg-muted text-muted-foreground', dot: 'bg-muted-foreground' };

    const [title, body] =
        setup.state === 'syncing'
            ? [s.syncingTitle, s.syncingBody]
            : setup.state === 'empty'
              ? [s.emptyTitle, s.emptyBody]
              : error
                ? [s.errorTitle, s.errorBody]
                : [s.connectTitle, s.connectBody];

    const activeStep = setup.state === 'connect' ? 0 : setup.state === 'syncing' ? 1 : 2;

    return (
        <motion.section
            initial={{ opacity: 0, y: 12 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.5, ease: [0.22, 1, 0.36, 1] }}
            className="bg-card overflow-hidden rounded-3xl border border-border/70 shadow-sm"
        >
            <div className="grid lg:grid-cols-[1.25fr_1fr]">
                {/* Status and call to action */}
                <div className="space-y-6 p-6 md:p-8">
                    <div className="flex flex-wrap items-center gap-3">
                        <div className="from-primary to-primary/80 relative flex size-14 items-center justify-center rounded-2xl bg-gradient-to-br text-lg font-bold text-white shadow-md">
                            زد
                            {setup.state === 'syncing' && (
                                <span className="bg-card absolute -end-1.5 -bottom-1.5 flex size-6 items-center justify-center rounded-full shadow">
                                    <Loader2 className="text-info size-4 animate-spin" />
                                </span>
                            )}
                        </div>
                        <span className={cn('inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium', status.className)}>
                            <span className={cn('size-1.5 rounded-full', status.dot, setup.state === 'syncing' && 'animate-pulse')} />
                            {status.label}
                        </span>
                    </div>

                    <div className="space-y-2">
                        <h2 className="text-2xl font-semibold tracking-tight text-balance">{title}</h2>
                        <p className="text-muted-foreground max-w-xl leading-relaxed">{body}</p>
                    </div>

                    {setup.state === 'syncing' && setup.run && (
                        <div className="space-y-2">
                            <div className="bg-muted h-2 overflow-hidden rounded-full">
                                <motion.div
                                    className="bg-info h-full w-1/3 rounded-full"
                                    animate={{ x: ['-100%', '300%'] }}
                                    transition={{ duration: 1.6, repeat: Infinity, ease: 'easeInOut' }}
                                />
                            </div>
                            <p className="text-muted-foreground text-xs">
                                {tr(s.imported, { products: number(setup.run.stats.products ?? 0), orders: number(setup.run.stats.orders ?? 0) })}
                                {setup.run.started_at && <> · {tr(s.startedAgo, { time: relative(setup.run.started_at) })}</>}
                            </p>
                        </div>
                    )}

                    {setup.run?.status === 'failed' && setup.run.error && (
                        <div className="bg-danger-soft text-danger flex gap-2 rounded-xl p-3 text-xs leading-relaxed">
                            <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                            <span>
                                <strong className="font-semibold">{s.lastError}: </strong>
                                {setup.run.error}
                            </span>
                        </div>
                    )}

                    {setup.state !== 'syncing' && (
                        <div className="space-y-3">
                            <div className="flex flex-wrap items-center gap-2">
                                {setup.state === 'empty' ? (
                                    <Button
                                        size="lg"
                                        disabled={syncing}
                                        onClick={() =>
                                            router.post('/store/integrations/zid/sync', {}, {
                                                preserveScroll: true,
                                                onStart: () => setSyncing(true),
                                                onFinish: () => setSyncing(false),
                                            })
                                        }
                                    >
                                        <RefreshCw className={cn('size-4', syncing && 'animate-spin')} />
                                        {s.syncNow}
                                    </Button>
                                ) : setup.zid_configured ? (
                                    <Button size="lg" asChild>
                                        {/* Full page navigation: the server redirects to Zid's consent screen. */}
                                        <a href="/store/integrations/zid/redirect">
                                            <Link2 className="size-4" />
                                            {error ? s.reconnect : s.connect}
                                        </a>
                                    </Button>
                                ) : (
                                    <Button size="lg" disabled>
                                        <Link2 className="size-4" />
                                        {s.connect}
                                    </Button>
                                )}
                                <Button size="lg" variant="ghost" asChild>
                                    <Link href="/store/settings/integration">
                                        <Settings className="size-4" />
                                        {s.settings}
                                    </Link>
                                </Button>
                            </div>

                            {!setup.zid_configured && setup.state === 'connect' && (
                                <p className="text-muted-foreground flex items-start gap-2 text-xs leading-relaxed">
                                    <PlugZap className="text-warning mt-0.5 size-4 shrink-0" />
                                    {setup.can_configure ? s.notConfiguredAdmin : s.notAvailable}
                                </p>
                            )}

                            <Link
                                href="/store/settings/integration"
                                className="text-primary inline-flex items-center gap-1.5 text-sm underline-offset-4 hover:underline"
                            >
                                <FileSpreadsheet className="size-4" />
                                {s.uploadInstead}
                            </Link>
                        </div>
                    )}
                </div>

                {/* Steps and what the owner gets */}
                <div className="bg-muted/40 relative space-y-6 border-t p-6 md:p-8 lg:border-s lg:border-t-0">
                    <div className="bg-sadu text-primary/[0.03] pointer-events-none absolute inset-0" />
                    <Stagger className="relative space-y-4">
                        {s.steps.map((step, i) => {
                            const done = i < activeStep;
                            const active = i === activeStep;

                            return (
                                <StaggerItem key={step.title} className="flex gap-3">
                                    <span
                                        className={cn(
                                            'flex size-8 shrink-0 items-center justify-center rounded-xl text-sm font-semibold transition-colors',
                                            done && 'bg-success text-white',
                                            active && 'bg-primary text-primary-foreground shadow-md',
                                            !done && !active && 'bg-card text-muted-foreground border',
                                        )}
                                    >
                                        {done ? <CheckCircle2 className="size-4" /> : active && setup.state === 'syncing' ? <Loader2 className="size-4 animate-spin" /> : i + 1}
                                    </span>
                                    <div className="space-y-0.5 pt-1">
                                        <div className={cn('text-sm font-semibold', !done && !active && 'text-muted-foreground')}>{step.title}</div>
                                        <p className="text-muted-foreground text-xs leading-relaxed">{step.body}</p>
                                    </div>
                                </StaggerItem>
                            );
                        })}
                    </Stagger>

                    <div className="relative space-y-2">
                        <div className="text-muted-foreground text-xs font-medium">{s.youGet}</div>
                        <div className="flex flex-wrap gap-1.5">
                            {s.benefits.map((b) => (
                                <span key={b} className="bg-card rounded-full border px-2.5 py-1 text-xs">
                                    {b}
                                </span>
                            ))}
                        </div>
                    </div>
                </div>
            </div>
        </motion.section>
    );
}

import { router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    Copy,
    Download,
    FileSpreadsheet,
    History,
    Link2,
    Loader2,
    PlugZap,
    RefreshCw,
    Unplug,
    UploadCloud,
    XCircle,
} from 'lucide-react';
import { motion } from 'motion/react';
import { useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { ConfirmDialog } from '@/components/mojo/confirm-dialog';
import { EmptyState } from '@/components/mojo/empty-state';
import { FadeIn } from '@/components/mojo/motion';
import { SectionCard } from '@/components/mojo/section-card';
import { toneClasses } from '@/components/mojo/tone';
import { SettingsShell } from '@/components/store/settings-shell';
import { ZidCheck } from '@/components/store/zid-check';
import type { ZidCheckResult } from '@/components/store/zid-check';
import { ZidGuide } from '@/components/store/zid-guide';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useClipboard } from '@/hooks/use-clipboard';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

type Run = {
    id: number;
    type: string;
    status: 'running' | 'completed' | 'failed';
    stats: Record<string, number | string>;
    error: string | null;
    started_at: string | null;
    duration: number | null;
};

type ImportReport = {
    type: 'products' | 'sales';
    imported: number;
    skipped: number;
    errors: { row: number; message: string }[];
};

type Props = {
    check: ZidCheckResult | null;
    zid: {
        configured: boolean;
        can_configure: boolean;
        status: 'connected' | 'disconnected' | 'error';
        platform: string;
        platform_store_id: string | null;
        has_credentials: boolean;
        token_expires_at: string | null;
        last_synced_at: string | null;
        sync_frequency: 'daily' | 'weekly';
        redirect_uri: string;
    };
    counts: { products: number; sales_rows: number };
    runs: Run[];
    importReport: ImportReport | null;
};

export default function IntegrationSettings({ zid, counts, runs, importReport, check }: Props) {
    const { t, tr, number } = useI18n();
    const s = t.settings.integration;

    return (
        <SettingsShell>
            <div className="grid gap-6 xl:grid-cols-5">
                <FadeIn className="xl:col-span-3">
                    <ZidCard zid={zid} />
                </FadeIn>
                <FadeIn delay={0.08} className="xl:col-span-2">
                    <FrequencyCard zid={zid} counts={counts} />
                </FadeIn>
            </div>

            <div className="grid gap-6 xl:grid-cols-5">
                <FadeIn delay={0.09} className="xl:col-span-3">
                    <ZidCheck check={check} connected={zid.status === 'connected' && zid.has_credentials} canConfigure={zid.can_configure} />
                </FadeIn>
                <FadeIn delay={0.1} className="xl:col-span-2">
                    <ZidGuide status={zid.status} hasData={counts.products > 0} checkOk={check ? check.ok : null} />
                </FadeIn>
            </div>

            <FadeIn delay={0.1}>
                <SectionCard title={s.importTitle} description={s.importHint} icon={FileSpreadsheet}>
                    <div className="grid gap-4 lg:grid-cols-2">
                        <ImportDropzone type="products" title={s.productsFile} hint={s.productsFileHint} />
                        <ImportDropzone type="sales" title={s.salesFile} hint={s.salesFileHint} />
                    </div>
                    {importReport && <ImportResult report={importReport} />}
                    <p className="text-muted-foreground mt-4 text-xs">
                        {tr(s.counts, { products: number(counts.products), rows: number(counts.sales_rows) })}
                    </p>
                </SectionCard>
            </FadeIn>

            <FadeIn delay={0.12}>
                <RunsCard runs={runs} />
            </FadeIn>
        </SettingsShell>
    );
}

function ZidCard({ zid }: { zid: Props['zid'] }) {
    const { t, relative, date } = useI18n();
    const s = t.settings.integration;
    const [, copy] = useClipboard();
    const [syncing, setSyncing] = useState(false);
    const connected = zid.status === 'connected';
    const tone = toneClasses[connected ? 'success' : zid.status === 'error' ? 'danger' : 'neutral'];

    return (
        <SectionCard title={s.zidTitle} description={s.zidHint} icon={PlugZap} className="h-full">
            <div className="space-y-5">
                <div className="flex flex-wrap items-center gap-4">
                    <div className="from-primary to-primary/80 relative flex size-14 items-center justify-center rounded-2xl bg-gradient-to-br text-lg font-bold text-white shadow-md">
                        زد
                        {connected && (
                            <motion.span
                                initial={{ scale: 0 }}
                                animate={{ scale: 1 }}
                                className="bg-success ring-card absolute -end-1 -bottom-1 flex size-5 items-center justify-center rounded-full ring-2"
                            >
                                <CheckCircle2 className="size-3.5 text-white" />
                            </motion.span>
                        )}
                    </div>
                    <div className="min-w-0 flex-1 space-y-1">
                        <span className={cn('inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium', tone.soft, tone.text)}>
                            <span className={cn('size-1.5 rounded-full', tone.bg)} />
                            {s.status[zid.status]}
                        </span>
                        <p className="text-muted-foreground text-xs">
                            {s.lastSync}: {zid.last_synced_at ? relative(zid.last_synced_at) : s.never}
                        </p>
                    </div>
                </div>

                {connected && !zid.has_credentials && (
                    <div className="bg-warning-soft text-warning flex gap-2 rounded-xl p-3 text-xs leading-relaxed">
                        <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                        {s.demoConnected}
                    </div>
                )}

                {!zid.configured && !zid.can_configure && (
                    <div className="bg-muted/60 space-y-1.5 rounded-xl p-4 text-sm">
                        <div className="flex items-center gap-2 font-medium">
                            <AlertTriangle className="text-warning size-4" />
                            {s.notAvailableTitle}
                        </div>
                        <p className="text-muted-foreground text-xs leading-relaxed">{s.notAvailableBody}</p>
                    </div>
                )}

                {!zid.configured && zid.can_configure && (
                    <div className="bg-gold-soft space-y-2 rounded-xl p-4 text-sm">
                        <div className="text-gold-foreground dark:text-gold flex items-center gap-2 font-medium">
                            <AlertTriangle className="size-4" />
                            {s.notConfiguredTitle}
                        </div>
                        <p className="text-muted-foreground text-xs leading-relaxed">{s.notConfiguredBody}</p>
                        <button
                            type="button"
                            onClick={() => void copy(zid.redirect_uri)}
                            className="bg-card hover:bg-muted flex w-full cursor-pointer items-center justify-between gap-2 rounded-lg border px-3 py-2 font-mono text-xs"
                            dir="ltr"
                        >
                            <span className="truncate">{zid.redirect_uri}</span>
                            <Copy className="size-3.5 shrink-0" />
                        </button>
                    </div>
                )}

                {(zid.platform_store_id || zid.token_expires_at) && connected && (
                    <dl className="grid grid-cols-2 gap-3 text-sm">
                        {zid.platform_store_id && (
                            <div className="bg-muted/50 rounded-xl p-3">
                                <dt className="text-muted-foreground text-xs">{s.storeId}</dt>
                                <dd className="num mt-0.5 font-medium">{zid.platform_store_id}</dd>
                            </div>
                        )}
                        {zid.token_expires_at && (
                            <div className="bg-muted/50 rounded-xl p-3">
                                <dt className="text-muted-foreground text-xs">{s.tokenExpires}</dt>
                                <dd className="mt-0.5 font-medium">{date(zid.token_expires_at)}</dd>
                            </div>
                        )}
                    </dl>
                )}

                <div className="flex flex-wrap gap-2">
                    {zid.configured && (
                        <Button asChild variant={connected && zid.has_credentials ? 'outline' : 'default'}>
                            {/* A full page navigation: the server redirects to Zid's consent screen. */}
                            <a href="/store/integrations/zid/redirect">
                                <Link2 className="size-4" />
                                {connected ? s.reconnect : s.connect}
                            </a>
                        </Button>
                    )}
                    {!zid.configured && (
                        <Button disabled>
                            <Link2 className="size-4" />
                            {s.connect}
                        </Button>
                    )}
                    {connected && zid.has_credentials && (
                        <Button
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
                    )}
                    {zid.status !== 'disconnected' && (
                        <ConfirmDialog
                            trigger={
                                <Button variant="ghost" className="text-danger hover:text-danger">
                                    <Unplug className="size-4" />
                                    {s.disconnect}
                                </Button>
                            }
                            title={s.disconnect}
                            description={s.disconnectConfirm}
                            destructive
                            confirmLabel={s.disconnect}
                            onConfirm={() => new Promise<void>((resolve) => router.delete('/store/integrations/zid', { preserveScroll: true, onFinish: () => resolve() }))}
                        />
                    )}
                </div>
            </div>
        </SectionCard>
    );
}

function FrequencyCard({ zid }: { zid: Props['zid']; counts: Props['counts'] }) {
    const { t } = useI18n();
    const s = t.settings.integration;
    const form = useForm({ sync_frequency: zid.sync_frequency });

    const choose = (value: 'daily' | 'weekly') => {
        form.setData('sync_frequency', value);
        router.put('/store/settings/integration/frequency', { sync_frequency: value }, { preserveScroll: true });
    };

    return (
        <SectionCard title={s.frequencyTitle} description={s.frequencyHint} icon={RefreshCw} className="h-full">
            <div className="grid grid-cols-2 gap-3">
                {(['daily', 'weekly'] as const).map((value) => {
                    const active = form.data.sync_frequency === value;

                    return (
                        <button
                            key={value}
                            type="button"
                            onClick={() => choose(value)}
                            className={cn(
                                'relative cursor-pointer rounded-xl border p-4 text-start transition-colors',
                                active ? 'border-primary bg-primary/5' : 'hover:bg-muted/50',
                            )}
                        >
                            <span className="block text-sm font-semibold">{s.frequencies[value]}</span>
                            <span className="text-muted-foreground block text-xs">03:00</span>
                            {active && (
                                <motion.span layoutId="frequency-check" className="text-primary absolute end-3 top-3">
                                    <CheckCircle2 className="size-4" />
                                </motion.span>
                            )}
                        </button>
                    );
                })}
            </div>
        </SectionCard>
    );
}

function ImportDropzone({ type, title, hint }: { type: 'products' | 'sales'; title: string; hint: string }) {
    const { t } = useI18n();
    const s = t.settings.integration;
    const input = useRef<HTMLInputElement>(null);
    const [dragging, setDragging] = useState(false);
    const form = useForm<{ file: File | null }>({ file: null });

    const upload = (file: File | null) => {
        if (!file) {
            return;
        }

        form.setData('file', file);
        form.transform(() => ({ file }));
        form.post(`/store/settings/integration/import/${type}`, {
            preserveScroll: true,
            forceFormData: true,
            onFinish: () => {
                form.reset();

                if (input.current) {
                    input.current.value = '';
                }
            },
        });
    };

    return (
        <div className="space-y-2">
            <div className="flex items-center justify-between gap-2">
                <span className="text-sm font-semibold">{title}</span>
                <Button asChild variant="ghost" size="sm" className="h-7 text-xs">
                    <a href={`/store/settings/integration/template/${type}`}>
                        <Download className="size-3.5" />
                        {s.downloadTemplate}
                    </a>
                </Button>
            </div>
            <button
                type="button"
                onClick={() => input.current?.click()}
                onDragOver={(e) => {
                    e.preventDefault();
                    setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={(e) => {
                    e.preventDefault();
                    setDragging(false);
                    upload(e.dataTransfer.files?.[0] ?? null);
                }}
                disabled={form.processing}
                className={cn(
                    'flex w-full cursor-pointer flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed px-4 py-8 text-center transition-colors',
                    dragging ? 'border-primary bg-primary/5' : 'hover:border-primary/40 hover:bg-muted/40',
                )}
            >
                {form.processing ? (
                    <Loader2 className="text-primary size-7 animate-spin" />
                ) : (
                    <motion.span animate={dragging ? { y: -4, scale: 1.08 } : { y: 0, scale: 1 }}>
                        <UploadCloud className="text-primary size-7" />
                    </motion.span>
                )}
                <span className="text-sm font-medium">{form.processing ? t.common.processing : s.chooseFile}</span>
                <span className="text-muted-foreground max-w-sm text-[11px] leading-relaxed">{hint}</span>
                <span className="text-muted-foreground/70 text-[10px]">CSV · XLSX</span>
            </button>
            <input ref={input} type="file" accept=".csv,.txt,.xlsx,.xls" className="hidden" onChange={(e) => upload(e.target.files?.[0] ?? null)} />
            <InputError message={form.errors.file} />
        </div>
    );
}

function ImportResult({ report }: { report: ImportReport }) {
    const { t, tr, number } = useI18n();
    const s = t.settings.integration;
    const ok = report.imported > 0;

    return (
        <motion.div
            initial={{ opacity: 0, y: 8 }}
            animate={{ opacity: 1, y: 0 }}
            className={cn('mt-5 rounded-2xl border p-4', ok ? 'border-success/30' : 'border-danger/30')}
        >
            <div className="flex flex-wrap items-center gap-3">
                <span className="text-sm font-semibold">
                    {s.reportTitle} — {report.type === 'sales' ? s.salesFile : s.productsFile}
                </span>
                <span className="bg-success-soft text-success num rounded-full px-2.5 py-0.5 text-xs font-medium">
                    {number(report.imported)} {s.imported}
                </span>
                {report.skipped > 0 && (
                    <span className="bg-danger-soft text-danger num rounded-full px-2.5 py-0.5 text-xs font-medium">
                        {number(report.skipped)} {s.skipped}
                    </span>
                )}
            </div>
            {report.errors.length > 0 && (
                <ul className="mt-3 max-h-48 space-y-1 overflow-y-auto text-xs">
                    {report.errors.map((e, i) => (
                        <li key={i} className="flex gap-2">
                            <span className="text-danger num shrink-0 font-medium">{tr(s.row, { n: e.row })}</span>
                            <span className="text-muted-foreground">{e.message}</span>
                        </li>
                    ))}
                </ul>
            )}
        </motion.div>
    );
}

function RunsCard({ runs }: { runs: Run[] }) {
    const { t, tr, dateTime, number } = useI18n();
    const s = t.settings.integration;
    const icons = { running: Loader2, completed: CheckCircle2, failed: XCircle };
    const tones = { running: 'info', completed: 'success', failed: 'danger' } as const;

    const describe = (run: Run) => {
        const stats = run.stats ?? {};

        return Object.entries(stats)
            .filter(([, v]) => typeof v === 'number')
            .map(([k, v]) => `${k}: ${number(v)}`)
            .join(' · ');
    };

    return (
        <SectionCard title={s.historyTitle} icon={History} flush>
            {runs.length === 0 ? (
                <EmptyState icon={History} title={s.noRuns} className="py-10" />
            ) : (
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="ps-5">{t.common.type}</TableHead>
                            <TableHead>{t.common.status}</TableHead>
                            <TableHead>{t.common.details}</TableHead>
                            <TableHead className="pe-5">{t.common.date}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {runs.map((run) => {
                            const Icon = icons[run.status] ?? CheckCircle2;
                            const tone = toneClasses[tones[run.status] ?? 'neutral'];

                            return (
                                <TableRow key={run.id}>
                                    <TableCell className="ps-5 font-medium">
                                        {s.runTypes[run.type as keyof typeof s.runTypes] ?? run.type}
                                        {typeof run.stats?.file === 'string' && (
                                            <span className="text-muted-foreground ms-1.5 text-xs">
                                                ({run.stats.file === 'sales' ? s.salesFile : s.productsFile})
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <span className={cn('inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium', tone.soft, tone.text)}>
                                            <Icon className={cn('size-3.5', run.status === 'running' && 'animate-spin')} />
                                            {s.runStatus[run.status] ?? run.status}
                                        </span>
                                    </TableCell>
                                    <TableCell className="text-muted-foreground max-w-md truncate text-xs whitespace-normal">
                                        {run.error ? <span className="text-danger">{run.error}</span> : describe(run) || '—'}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground pe-5 text-xs">
                                        {dateTime(run.started_at)}
                                        {run.duration !== null && <span className="ms-1.5">· {tr(s.seconds, { n: run.duration })}</span>}
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </Table>
            )}
        </SectionCard>
    );
}

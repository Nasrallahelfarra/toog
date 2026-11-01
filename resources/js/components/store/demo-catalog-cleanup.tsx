import { router, useForm } from '@inertiajs/react';
import { CheckCircle2, Loader2, RefreshCw, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { useI18n } from '@/hooks/use-i18n';

export type DemoCatalogPreview = {
    products_count: number;
    sales_count: number;
    remaining_count: number;
    preview_token: string;
    sync_running: boolean;
    items: { id: number; external_id: string; sku: string; name: string }[];
};

export function DemoCatalogCleanup({ data }: { data: DemoCatalogPreview }) {
    const { t, tr, number, dir } = useI18n();
    const c = t.dashboard.demoCleanup;
    const [open, setOpen] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const submitting = useRef(false);
    const form = useForm({ preview_token: '' });
    const empty = data.products_count === 0;
    const busy = form.processing || refreshing;

    useEffect(() => {
        if (!data.sync_running || open || form.processing) return;
        const timer = window.setInterval(
            () => router.reload({ only: ['demoCatalog'] }),
            5000,
        );
        return () => window.clearInterval(timer);
    }, [data.sync_running, open, form.processing]);

    const refresh = () => {
        if (busy) return;
        setRefreshing(true);
        router.reload({
            only: ['demoCatalog'],
            onSuccess: () => form.clearErrors(),
            onFinish: () => setRefreshing(false),
        });
    };

    const submit = () => {
        if (submitting.current || busy || empty || data.sync_running) return;
        submitting.current = true;
        form.transform(() => ({ preview_token: data.preview_token }));
        form.post('/store/catalog/clear-demo', {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                form.reset();
            },
            onFinish: () => {
                submitting.current = false;
            },
        });
    };

    return (
        <section
            aria-label={c.title}
            className="flex flex-col gap-4 rounded-2xl border border-primary/25 bg-primary/5 p-5 sm:flex-row sm:items-center sm:justify-between"
        >
            <div className="flex min-w-0 items-start gap-3">
                <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                    {empty ? (
                        <CheckCircle2 className="size-5" />
                    ) : (
                        <Trash2 className="size-5" />
                    )}
                </span>
                <div className="min-w-0 space-y-1">
                    <h2 className="font-semibold">{c.title}</h2>
                    <p
                        className="text-sm leading-6 text-muted-foreground"
                        role="status"
                    >
                        {empty
                            ? c.empty
                            : tr(c.description, {
                                  count: number(data.products_count),
                              })}
                    </p>
                    {!empty && data.sync_running && (
                        <p
                            className="flex items-center gap-2 text-sm text-primary"
                            role="status"
                        >
                            <Loader2 className="size-4 shrink-0 motion-safe:animate-spin" />
                            {c.waitForSync}
                        </p>
                    )}
                </div>
            </div>

            <Dialog
                open={open}
                onOpenChange={(next) => {
                    if (!busy) setOpen(next);
                }}
            >
                <DialogTrigger asChild>
                    <Button
                        variant="outline"
                        disabled={empty || data.sync_running}
                        className="h-auto min-h-10 shrink-0 whitespace-normal"
                    >
                        {empty ? (
                            <CheckCircle2 className="size-4 shrink-0" />
                        ) : (
                            <Trash2 className="size-4 shrink-0" />
                        )}
                        {empty ? c.done : c.button}
                    </Button>
                </DialogTrigger>
                <DialogContent
                    dir={dir}
                    className="max-h-[90dvh] overflow-y-auto sm:max-w-lg"
                >
                    <DialogHeader className="text-start sm:text-start">
                        <DialogTitle className="pe-6 leading-7">
                            {c.confirmTitle}
                        </DialogTitle>
                        <DialogDescription className="leading-6">
                            {c.confirmBody}
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            submit();
                        }}
                        className="min-w-0 space-y-4"
                        aria-busy={form.processing}
                    >
                        <dl className="grid grid-cols-2 gap-3 rounded-xl bg-muted/50 p-4">
                            <div className="space-y-1">
                                <dt className="text-xs text-muted-foreground">
                                    {c.products}
                                </dt>
                                <dd className="text-2xl font-semibold tabular-nums">
                                    {number(data.products_count)}
                                </dd>
                            </div>
                            <div className="space-y-1">
                                <dt className="text-xs text-muted-foreground">
                                    {c.sales}
                                </dt>
                                <dd className="text-2xl font-semibold tabular-nums">
                                    {number(data.sales_count)}
                                </dd>
                            </div>
                        </dl>
                        <p className="text-sm leading-6">
                            {tr(c.remaining, {
                                count: number(data.remaining_count),
                            })}
                        </p>
                        {data.remaining_count === 0 && (
                            <p className="text-sm leading-6 text-muted-foreground">
                                {c.noImports}
                            </p>
                        )}
                        <details className="rounded-xl border p-3 text-sm">
                            <summary className="cursor-pointer font-medium">
                                {c.showProducts}
                            </summary>
                            <ul className="mt-3 max-h-40 space-y-2 overflow-y-auto">
                                {data.items.map((item) => (
                                    <li
                                        key={item.id}
                                        className="border-b border-border/50 pb-2 break-words last:border-0"
                                    >
                                        {item.name}
                                    </li>
                                ))}
                            </ul>
                        </details>
                        {form.hasErrors && (
                            <div role="alert" className="space-y-2">
                                <InputError
                                    message={form.errors.preview_token}
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={busy}
                                    onClick={refresh}
                                >
                                    {refreshing ? (
                                        <Loader2 className="size-4 motion-safe:animate-spin" />
                                    ) : (
                                        <RefreshCw className="size-4" />
                                    )}
                                    {c.refresh}
                                </Button>
                            </div>
                        )}
                        {form.processing && (
                            <p
                                role="status"
                                className="text-sm text-muted-foreground"
                            >
                                {c.deleting}
                            </p>
                        )}
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="ghost"
                                disabled={busy}
                                onClick={() => setOpen(false)}
                            >
                                {t.common.cancel}
                            </Button>
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={busy || empty || data.sync_running}
                                className="h-auto min-h-10 whitespace-normal"
                            >
                                {form.processing ? (
                                    <Loader2 className="size-4 shrink-0 motion-safe:animate-spin" />
                                ) : (
                                    <Trash2 className="size-4 shrink-0" />
                                )}
                                {form.processing ? c.deletingButton : c.confirm}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </section>
    );
}

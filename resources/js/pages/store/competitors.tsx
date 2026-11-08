import { Link, router, useForm, usePoll } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowUpRight,
    CheckCircle2,
    Clock,
    Eye,
    Pencil,
    Plus,
    Radar,
    RefreshCw,
    ScanLine,
    Swords,
    Trash2,
    Unplug,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { CompetitorWorkspace } from '@/components/market/competitor-workspace';
import type {
    Competitor,
    CompetitorDetails,
} from '@/components/market/competitor-types';
import { PlatformBadge } from '@/components/market/platform-badge';
import {
    CompetitorScanProgress,
    isScanning,
} from '@/components/market/competitor-scan-progress';
import { ConfirmDialog } from '@/components/mojo/confirm-dialog';
import { EmptyState } from '@/components/mojo/empty-state';
import { HoverLift, Stagger, StaggerItem } from '@/components/mojo/motion';
import { PageBody, PageHeader } from '@/components/mojo/page-header';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

type Props = {
    competitors: Competitor[];
    stats: { total: number; active: number; products: number; posts: number };
    platforms: string[];
    categories: string[];
    selected: CompetitorDetails | null;
    socialPosts: { limit: number; max: number };
    lastScan: {
        status: string;
        stats: {
            added?: number;
            updated?: number;
            price_changes?: number;
        } | null;
        finished_at: string | null;
    } | null;
};

const SOCIAL = ['instagram', 'tiktok', 'facebook', 'snapchat'];

function ScanPoll({ onFailure }: { onFailure: (failed: boolean) => void }) {
    usePoll(2000, {
        only: ['competitors', 'selected', 'stats', 'lastScan'],
        onSuccess: () => onFailure(false),
        onNetworkError: () => {
            onFailure(true);
            return false;
        },
        onHttpException: () => {
            onFailure(true);
            return false;
        },
    });
    return null;
}

export default function Competitors({
    competitors,
    stats,
    platforms,
    categories,
    selected,
    socialPosts,
    lastScan,
}: Props) {
    const { t, tr, relative, number } = useI18n();
    const [editing, setEditing] = useState<Competitor | 'new' | null>(null);
    const [scanning, setScanning] = useState<number | 'all' | null>(null);
    const [requestFailed, setRequestFailed] = useState(false);
    const [pollFailed, setPollFailed] = useState(false);
    const c = t.market.competitors;

    const openDetails = (id: number | null) =>
        router.get('/store/competitors', id ? { competitor: id } : {}, {
            preserveState: true,
            preserveScroll: false,
            only: ['selected'],
            replace: false,
        });

    const scan = (id: number | 'all') =>
        router.post(
            id === 'all'
                ? '/store/competitors/scan'
                : `/store/competitors/${id}/scan`,
            {},
            {
                preserveScroll: true,
                onStart: () => {
                    setScanning(id);
                    setRequestFailed(false);
                },
                onError: () => setRequestFailed(true),
                onNetworkError: () => {
                    setRequestFailed(true);
                    return false;
                },
                onHttpException: () => {
                    setRequestFailed(true);
                    return false;
                },
                onFinish: () => setScanning(null),
            },
        );

    const selectedCompetitor = selected
        ? competitors.find((x) => x.id === selected.id)
        : null;

    if (selected && selectedCompetitor) {
        return (
            <PageBody>
                {scanning === null &&
                    competitors.some((x) => isScanning(x.scan)) && (
                        <ScanPoll onFailure={setPollFailed} />
                    )}
                <CompetitorWorkspace
                    key={selected.id}
                    competitor={selectedCompetitor}
                    details={selected}
                    scanning={
                        scanning === selected.id ||
                        isScanning(selectedCompetitor.scan)
                    }
                    onScan={() => scan(selected.id)}
                    onBack={() => openDetails(null)}
                    errorMessage={
                        requestFailed
                            ? c.scanProgress.requestFailed
                            : pollFailed
                              ? c.scanProgress.updateFailed
                              : null
                    }
                />
            </PageBody>
        );
    }

    return (
        <PageBody>
            <PageHeader
                title={c.title}
                description={c.description}
                icon={Swords}
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <Link href="/store/market">
                                <Radar className="size-4" />
                                {t.market.title}
                            </Link>
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() => scan('all')}
                            disabled={
                                scanning !== null ||
                                competitors.length === 0 ||
                                competitors.some((x) => isScanning(x.scan))
                            }
                        >
                            <RefreshCw
                                className={cn(
                                    'size-4',
                                    scanning === 'all' && 'animate-spin',
                                )}
                            />
                            {c.scanAll}
                        </Button>
                        <Button onClick={() => setEditing('new')}>
                            <Plus className="size-4" />
                            {c.add}
                        </Button>
                    </>
                }
            />

            {scanning === null &&
                competitors.some((x) => isScanning(x.scan)) && (
                    <ScanPoll onFailure={setPollFailed} />
                )}
            {pollFailed && (
                <p
                    role="alert"
                    className="rounded-xl border p-4 text-sm text-warning"
                >
                    {c.scanProgress.updateFailed}
                </p>
            )}
            {scanning !== null && (
                <div
                    role="status"
                    className="rounded-xl border bg-muted/40 p-4 text-sm"
                >
                    {c.scanProgress.starting}
                </div>
            )}
            {requestFailed && (
                <div
                    role="alert"
                    className="rounded-xl border border-danger/30 p-4 text-sm text-danger"
                >
                    {c.scanProgress.requestFailed}
                </div>
            )}

            <div className="flex flex-wrap items-center gap-x-6 gap-y-2 rounded-2xl border border-border/70 bg-card px-5 py-3.5 text-sm shadow-[0_1px_2px_rgba(16,24,40,0.04)]">
                {(['total', 'active', 'products', 'posts'] as const).map(
                    (key) => (
                        <span key={key} className="flex items-baseline gap-1.5">
                            <strong className="num text-lg font-semibold">
                                {number(stats[key])}
                            </strong>
                            <span className="text-muted-foreground">
                                {c.totals[key]}
                            </span>
                        </span>
                    ),
                )}
                {lastScan?.finished_at && (
                    <span className="ms-auto flex items-center gap-1.5 text-xs text-muted-foreground">
                        <Clock className="size-3.5" />
                        {tr(c.lastScan, {
                            time: relative(lastScan.finished_at),
                        })}
                        {lastScan.stats && (
                            <span className="hidden md:inline">
                                ·{' '}
                                {tr(c.scanStats, {
                                    added: lastScan.stats.added ?? 0,
                                    updated: lastScan.stats.updated ?? 0,
                                    changes: lastScan.stats.price_changes ?? 0,
                                })}
                            </span>
                        )}
                    </span>
                )}
            </div>

            <div className="flex flex-col gap-3 rounded-xl bg-info-soft/60 px-4 py-3 text-xs leading-relaxed text-info lg:flex-row lg:items-center">
                <div className="flex flex-1 items-start gap-2.5">
                    <ScanLine className="mt-0.5 size-4 shrink-0" />
                    <span>
                        <strong className="font-semibold">
                            {c.coverage}:{' '}
                        </strong>
                        {c.coverageHint}
                    </span>
                </div>
                <SocialPostsLimit
                    key={socialPosts.limit}
                    limit={socialPosts.limit}
                    max={socialPosts.max}
                />
            </div>

            {competitors.length === 0 ? (
                <EmptyState
                    icon={Swords}
                    title={c.empty}
                    description={c.emptyHint}
                    action={
                        <Button onClick={() => setEditing('new')}>
                            <Plus className="size-4" />
                            {c.add}
                        </Button>
                    }
                />
            ) : (
                <Stagger className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {competitors.map((competitor) => (
                        <StaggerItem key={competitor.id}>
                            <CompetitorCard
                                competitor={competitor}
                                scanning={
                                    scanning === competitor.id ||
                                    isScanning(competitor.scan)
                                }
                                onOpen={() => openDetails(competitor.id)}
                                onEdit={() => setEditing(competitor)}
                                onScan={() => scan(competitor.id)}
                            />
                        </StaggerItem>
                    ))}
                </Stagger>
            )}

            <CompetitorDialog
                key={editing === 'new' ? 'new' : (editing?.id ?? 'closed')}
                competitor={editing}
                platforms={platforms}
                categories={categories}
                onClose={() => setEditing(null)}
            />
        </PageBody>
    );
}

/** How many of each social account's latest posts a scan reads. */
function SocialPostsLimit({ limit, max }: { limit: number; max: number }) {
    const { t } = useI18n();
    const c = t.market.competitors;
    const form = useForm({ posts_limit: limit });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put('/store/competitors/social-settings', {
            preserveScroll: true,
        });
    };

    return (
        <form
            onSubmit={submit}
            className="flex shrink-0 flex-col gap-1 text-foreground"
        >
            <div className="flex items-center gap-2">
                <Label htmlFor="posts_limit" className="text-xs">
                    {c.postsLimit}
                </Label>
                <Input
                    id="posts_limit"
                    type="number"
                    min={1}
                    max={max}
                    required
                    value={form.data.posts_limit}
                    onChange={(e) =>
                        form.setData('posts_limit', Number(e.target.value))
                    }
                    className="num h-8 w-20 bg-card text-center"
                    aria-describedby="posts_limit_hint"
                />
                <Button
                    type="submit"
                    size="sm"
                    variant="outline"
                    className="h-8"
                    disabled={
                        form.processing || form.data.posts_limit === limit
                    }
                >
                    {t.common.save}
                </Button>
            </div>
            <p id="posts_limit_hint" className="text-muted-foreground">
                {c.postsLimitHint}
            </p>
            <InputError message={form.errors.posts_limit} />
        </form>
    );
}

function CompetitorCard({
    competitor,
    scanning,
    onOpen,
    onEdit,
    onScan,
}: {
    competitor: Competitor;
    scanning: boolean;
    onOpen: () => void;
    onEdit: () => void;
    onScan: () => void;
}) {
    const { t, relative, number } = useI18n();
    const c = t.market.competitors;

    return (
        <HoverLift className="h-full">
            <div
                className={cn(
                    'flex h-full flex-col gap-4 rounded-2xl border border-border/70 bg-card p-5 shadow-[0_1px_2px_rgba(16,24,40,0.04)] transition-shadow hover:shadow-md',
                    !competitor.is_active && 'opacity-60',
                )}
            >
                <div className="flex items-start justify-between gap-3">
                    <button
                        type="button"
                        onClick={onOpen}
                        className="min-w-0 cursor-pointer text-start"
                    >
                        <div className="truncate font-semibold hover:underline">
                            {competitor.name}
                        </div>
                        <div className="mt-1 flex flex-wrap items-center gap-2">
                            <PlatformBadge platform={competitor.platform} />
                            <span className="text-xs text-muted-foreground">
                                ·{' '}
                                {competitor.is_social
                                    ? c.socialKind
                                    : c.storeKind}
                            </span>
                        </div>
                    </button>
                    <span
                        className={cn(
                            'inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium',
                            competitor.auto_scan
                                ? 'bg-success-soft text-success'
                                : 'bg-muted text-muted-foreground',
                        )}
                    >
                        {competitor.auto_scan ? (
                            <CheckCircle2 className="size-3" />
                        ) : (
                            <Unplug className="size-3" />
                        )}
                        {competitor.auto_scan
                            ? c.autoScan
                            : competitor.scan_blocker === 'needs_apify'
                              ? c.needsApify
                              : competitor.is_social
                                ? c.needsApi
                                : c.manualOnly}
                    </span>
                </div>

                <div className="grid grid-cols-2 gap-3">
                    <div className="rounded-xl bg-muted/50 px-3 py-2.5">
                        <div className="num text-lg font-semibold">
                            {number(competitor.products_count)}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            {c.products}
                        </div>
                    </div>
                    <div className="rounded-xl bg-muted/50 px-3 py-2.5">
                        <div className="num text-lg font-semibold">
                            {number(competitor.posts_count)}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            {c.posts}
                        </div>
                    </div>
                </div>

                <div className="space-y-1 text-xs">
                    <div className="flex items-center gap-1.5 text-muted-foreground">
                        <Clock className="size-3.5" />
                        {c.lastChecked}:{' '}
                        {competitor.last_checked_at
                            ? relative(competitor.last_checked_at)
                            : c.neverChecked}
                    </div>
                    {competitor.last_error && !competitor.scan && (
                        <div
                            className="flex items-start gap-1.5 text-danger"
                            title={competitor.last_error}
                        >
                            <AlertTriangle className="mt-0.5 size-3.5 shrink-0" />
                            <span className="line-clamp-1">
                                {competitor.last_error}
                            </span>
                        </div>
                    )}
                    {!competitor.is_active && (
                        <div className="font-medium text-warning">
                            {c.paused}
                        </div>
                    )}
                </div>

                {competitor.scan && competitor.scan.status !== 'completed' && (
                    <CompetitorScanProgress
                        scan={competitor.scan}
                        social={competitor.is_social}
                        compact
                    />
                )}
                <Button variant="outline" size="sm" onClick={onOpen}>
                    <Eye className="size-4" />
                    {scanning ? c.scanProgress.follow : c.analysis}
                </Button>

                <div className="mt-auto flex items-center gap-2 border-t border-border/60 pt-3">
                    <Button
                        size="sm"
                        variant="soft"
                        onClick={onScan}
                        disabled={
                            scanning ||
                            !competitor.is_active ||
                            !competitor.auto_scan
                        }
                        className="flex-1"
                    >
                        <RefreshCw
                            className={cn(
                                'size-3.5',
                                scanning && 'animate-spin',
                            )}
                        />
                        {scanning ? c.scanProgress.running : c.scanNow}
                    </Button>
                    <Button
                        size="icon"
                        variant="ghost"
                        className="size-8"
                        onClick={onEdit}
                        disabled={scanning}
                        aria-label={c.edit}
                    >
                        <Pencil className="size-4" />
                    </Button>
                    <ConfirmDialog
                        title={c.deleteTitle}
                        description={c.deleteBody}
                        destructive
                        confirmLabel={t.common.delete}
                        onConfirm={() =>
                            new Promise<void>((resolve) =>
                                router.delete(
                                    `/store/competitors/${competitor.id}`,
                                    {
                                        preserveScroll: true,
                                        onFinish: () => resolve(),
                                    },
                                ),
                            )
                        }
                        trigger={
                            <Button
                                size="icon"
                                variant="ghost"
                                className="size-8 text-danger hover:text-danger"
                                aria-label={t.common.delete}
                                disabled={scanning}
                            >
                                <Trash2 className="size-4" />
                            </Button>
                        }
                    />
                    {competitor.profile_url && (
                        <Button
                            size="icon"
                            variant="ghost"
                            className="size-8"
                            asChild
                        >
                            <a
                                href={competitor.profile_url}
                                target="_blank"
                                rel="noreferrer"
                                aria-label={t.common.open}
                            >
                                <ArrowUpRight className="size-4 rtl:-scale-x-100" />
                            </a>
                        </Button>
                    )}
                </div>
            </div>
        </HoverLift>
    );
}

function CompetitorDialog({
    competitor,
    platforms,
    categories,
    onClose,
}: {
    competitor: Competitor | 'new' | null;
    platforms: string[];
    categories: string[];
    onClose: () => void;
}) {
    const { t } = useI18n();
    const c = t.market.competitors;
    const existing = competitor && competitor !== 'new' ? competitor : null;

    const form = useForm({
        name: existing?.name ?? '',
        platform: existing?.platform ?? 'website',
        url: existing?.url ?? '',
        handle: existing?.handle ?? '',
        category: existing?.category ?? '',
        notes: existing?.notes ?? '',
        is_active: existing?.is_active ?? true,
    });

    const social = SOCIAL.includes(form.data.platform);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => onClose() };

        if (existing) {
            form.put(`/store/competitors/${existing.id}`, options);
        } else {
            form.post('/store/competitors', options);
        }
    };

    return (
        <Dialog
            open={competitor !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit} className="space-y-5">
                    <DialogHeader>
                        <DialogTitle>{existing ? c.edit : c.add}</DialogTitle>
                        <DialogDescription>{c.description}</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor="name">{c.name}</Label>
                            <Input
                                id="name"
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                placeholder={c.nameHint}
                                required={social}
                            />
                            <InputError message={form.errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label>{c.platform}</Label>
                            <Select
                                value={form.data.platform}
                                onValueChange={(v) =>
                                    form.setData('platform', v)
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {platforms.map((p) => (
                                        <SelectItem key={p} value={p}>
                                            {t.market.platforms[
                                                p as keyof typeof t.market.platforms
                                            ] ?? p}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.platform} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="category">{c.category}</Label>
                            <Input
                                id="category"
                                list="competitor-categories"
                                value={form.data.category}
                                onChange={(e) =>
                                    form.setData('category', e.target.value)
                                }
                            />
                            <datalist id="competitor-categories">
                                {categories.map((cat) => (
                                    <option key={cat} value={cat} />
                                ))}
                            </datalist>
                        </div>

                        {social && (
                            <div className="grid gap-2 sm:col-span-2">
                                <Label htmlFor="handle">{c.handle}</Label>
                                <Input
                                    id="handle"
                                    dir="ltr"
                                    placeholder="@account"
                                    value={form.data.handle}
                                    onChange={(e) =>
                                        form.setData('handle', e.target.value)
                                    }
                                />
                                <p className="text-xs leading-relaxed text-muted-foreground">
                                    {c.handleHint}
                                </p>
                                <InputError message={form.errors.handle} />
                            </div>
                        )}

                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor="url">{c.url}</Label>
                            <Input
                                id="url"
                                dir="ltr"
                                type="url"
                                placeholder="https://"
                                value={form.data.url}
                                onChange={(e) =>
                                    form.setData('url', e.target.value)
                                }
                                required={!social}
                            />
                            <InputError message={form.errors.url} />
                        </div>

                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor="notes">{c.notes}</Label>
                            <Textarea
                                id="notes"
                                rows={2}
                                value={form.data.notes}
                                onChange={(e) =>
                                    form.setData('notes', e.target.value)
                                }
                            />
                        </div>

                        <label className="flex items-center gap-3 sm:col-span-2">
                            <Switch
                                checked={form.data.is_active}
                                onCheckedChange={(v) =>
                                    form.setData('is_active', v)
                                }
                            />
                            <span className="text-sm">{c.active}</span>
                        </label>
                    </div>

                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            {t.common.cancel}
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {t.common.save}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

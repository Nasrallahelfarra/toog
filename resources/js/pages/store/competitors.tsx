import { Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowUpRight,
    CheckCircle2,
    Clock,
    Eye,
    Heart,
    Pencil,
    Plus,
    Radar,
    RefreshCw,
    ScanLine,
    Swords,
    Trash2,
    Trophy,
    Unplug,
} from 'lucide-react';
import { motion } from 'motion/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { PlatformBadge } from '@/components/market/platform-badge';
import { ColorChips } from '@/components/mojo/color-chips';
import { ConfirmDialog } from '@/components/mojo/confirm-dialog';
import { EmptyState } from '@/components/mojo/empty-state';
import { HoverLift, Stagger, StaggerItem } from '@/components/mojo/motion';
import { PageBody, PageHeader } from '@/components/mojo/page-header';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

type Competitor = {
    id: number;
    name: string;
    platform: string;
    url: string | null;
    handle: string | null;
    category: string | null;
    notes: string | null;
    is_active: boolean;
    is_social: boolean;
    auto_scan: boolean;
    products_count: number;
    posts_count: number;
    last_checked_at: string | null;
    last_error: string | null;
};

type Details = {
    id: number;
    products: {
        id: number;
        name: string;
        category: string | null;
        price: number | null;
        previous_price: number | null;
        image_url: string | null;
        url: string | null;
        is_best_seller: boolean;
        colors: string[];
        pattern: string | null;
        first_seen_at: string | null;
    }[];
    posts: { id: number; caption: string | null; url: string | null; views: number; likes: number; engagement_rate: number; posted_at: string | null }[];
};

type Props = {
    competitors: Competitor[];
    stats: { total: number; active: number; products: number; posts: number };
    platforms: string[];
    categories: string[];
    selected: Details | null;
    lastScan: { status: string; stats: { added?: number; updated?: number; price_changes?: number } | null; finished_at: string | null } | null;
};

const SOCIAL = ['instagram', 'tiktok', 'snapchat'];

export default function Competitors({ competitors, stats, platforms, categories, selected, lastScan }: Props) {
    const { t, tr, relative, number, isRtl } = useI18n();
    const [editing, setEditing] = useState<Competitor | 'new' | null>(null);
    const [scanning, setScanning] = useState<number | 'all' | null>(null);
    const c = t.market.competitors;

    const openDetails = (id: number | null) =>
        router.get('/store/competitors', id ? { competitor: id } : {}, { preserveState: true, preserveScroll: true, only: ['selected'], replace: true });

    const scan = (id: number | 'all') =>
        router.post(id === 'all' ? '/store/competitors/scan' : `/store/competitors/${id}/scan`, {}, {
            preserveScroll: true,
            onStart: () => setScanning(id),
            onFinish: () => setScanning(null),
        });

    const selectedCompetitor = selected ? competitors.find((x) => x.id === selected.id) : null;

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
                        <Button variant="outline" onClick={() => scan('all')} disabled={scanning !== null || competitors.length === 0}>
                            <RefreshCw className={cn('size-4', scanning === 'all' && 'animate-spin')} />
                            {c.scanAll}
                        </Button>
                        <Button onClick={() => setEditing('new')}>
                            <Plus className="size-4" />
                            {c.add}
                        </Button>
                    </>
                }
            />

            <div className="flex flex-wrap items-center gap-x-6 gap-y-2 rounded-2xl border border-border/70 bg-card px-5 py-3.5 text-sm shadow-[0_1px_2px_rgba(16,24,40,0.04)]">
                {(['total', 'active', 'products', 'posts'] as const).map((key) => (
                    <span key={key} className="flex items-baseline gap-1.5">
                        <strong className="num text-lg font-semibold">{number(stats[key])}</strong>
                        <span className="text-muted-foreground">{c.totals[key]}</span>
                    </span>
                ))}
                {lastScan?.finished_at && (
                    <span className="text-muted-foreground ms-auto flex items-center gap-1.5 text-xs">
                        <Clock className="size-3.5" />
                        {tr(c.lastScan, { time: relative(lastScan.finished_at) })}
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

            <div className="bg-info-soft/60 text-info flex items-start gap-2.5 rounded-xl px-4 py-3 text-xs leading-relaxed">
                <ScanLine className="mt-0.5 size-4 shrink-0" />
                <span>
                    <strong className="font-semibold">{c.coverage}: </strong>
                    {c.coverageHint}
                </span>
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
                                scanning={scanning === competitor.id}
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

            <Sheet open={!!selected} onOpenChange={(open) => !open && openDetails(null)}>
                <SheetContent side={isRtl ? 'left' : 'right'} className="w-full overflow-y-auto sm:max-w-xl">
                    {selected && selectedCompetitor && <DetailsPanel competitor={selectedCompetitor} details={selected} />}
                </SheetContent>
            </Sheet>
        </PageBody>
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
                    'bg-card flex h-full flex-col gap-4 rounded-2xl border border-border/70 p-5 shadow-[0_1px_2px_rgba(16,24,40,0.04)] transition-shadow hover:shadow-md',
                    !competitor.is_active && 'opacity-60',
                )}
            >
                <div className="flex items-start justify-between gap-3">
                    <button type="button" onClick={onOpen} className="min-w-0 cursor-pointer text-start">
                        <div className="truncate font-semibold hover:underline">{competitor.name}</div>
                        <div className="mt-1 flex flex-wrap items-center gap-2">
                            <PlatformBadge platform={competitor.platform} />
                            <span className="text-muted-foreground text-xs">· {competitor.is_social ? c.socialKind : c.storeKind}</span>
                        </div>
                    </button>
                    <span
                        className={cn(
                            'inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium',
                            competitor.auto_scan ? 'bg-success-soft text-success' : 'bg-muted text-muted-foreground',
                        )}
                    >
                        {competitor.auto_scan ? <CheckCircle2 className="size-3" /> : <Unplug className="size-3" />}
                        {competitor.auto_scan ? c.autoScan : competitor.is_social ? c.needsApi : c.manualOnly}
                    </span>
                </div>

                <div className="grid grid-cols-2 gap-3">
                    <div className="bg-muted/50 rounded-xl px-3 py-2.5">
                        <div className="num text-lg font-semibold">{number(competitor.products_count)}</div>
                        <div className="text-muted-foreground text-xs">{c.products}</div>
                    </div>
                    <div className="bg-muted/50 rounded-xl px-3 py-2.5">
                        <div className="num text-lg font-semibold">{number(competitor.posts_count)}</div>
                        <div className="text-muted-foreground text-xs">{c.posts}</div>
                    </div>
                </div>

                <div className="space-y-1 text-xs">
                    <div className="text-muted-foreground flex items-center gap-1.5">
                        <Clock className="size-3.5" />
                        {c.lastChecked}: {competitor.last_checked_at ? relative(competitor.last_checked_at) : c.neverChecked}
                    </div>
                    {competitor.last_error && (
                        <div className="text-danger flex items-start gap-1.5" title={competitor.last_error}>
                            <AlertTriangle className="mt-0.5 size-3.5 shrink-0" />
                            <span className="line-clamp-1">{competitor.last_error}</span>
                        </div>
                    )}
                    {!competitor.is_active && <div className="text-warning font-medium">{c.paused}</div>}
                </div>

                <div className="mt-auto flex items-center gap-2 border-t border-border/60 pt-3">
                    <Button size="sm" variant="soft" onClick={onScan} disabled={scanning} className="flex-1">
                        <RefreshCw className={cn('size-3.5', scanning && 'animate-spin')} />
                        {c.scanNow}
                    </Button>
                    <Button size="icon" variant="ghost" className="size-8" onClick={onEdit} aria-label={c.edit}>
                        <Pencil className="size-4" />
                    </Button>
                    <ConfirmDialog
                        title={c.deleteTitle}
                        description={c.deleteBody}
                        destructive
                        confirmLabel={t.common.delete}
                        onConfirm={() => new Promise<void>((resolve) => router.delete(`/store/competitors/${competitor.id}`, { preserveScroll: true, onFinish: () => resolve() }))}
                        trigger={
                            <Button size="icon" variant="ghost" className="text-danger hover:text-danger size-8" aria-label={t.common.delete}>
                                <Trash2 className="size-4" />
                            </Button>
                        }
                    />
                    {(competitor.url || competitor.handle) && (
                        <Button size="icon" variant="ghost" className="size-8" asChild>
                            <a href={competitor.url ?? '#'} target="_blank" rel="noreferrer" aria-label={t.common.open}>
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
        platform: existing?.platform ?? 'salla',
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
        <Dialog open={competitor !== null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit} className="space-y-5">
                    <DialogHeader>
                        <DialogTitle>{existing ? c.edit : c.add}</DialogTitle>
                        <DialogDescription>{c.description}</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor="name">{c.name}</Label>
                            <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                            <InputError message={form.errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label>{c.platform}</Label>
                            <Select value={form.data.platform} onValueChange={(v) => form.setData('platform', v)}>
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {platforms.map((p) => (
                                        <SelectItem key={p} value={p}>
                                            {t.market.platforms[p as keyof typeof t.market.platforms] ?? p}
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
                                onChange={(e) => form.setData('category', e.target.value)}
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
                                <Input id="handle" dir="ltr" placeholder="@account" value={form.data.handle} onChange={(e) => form.setData('handle', e.target.value)} />
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
                                onChange={(e) => form.setData('url', e.target.value)}
                                required={!social}
                            />
                            <InputError message={form.errors.url} />
                        </div>

                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor="notes">{c.notes}</Label>
                            <Textarea id="notes" rows={2} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                        </div>

                        <label className="flex items-center gap-3 sm:col-span-2">
                            <Switch checked={form.data.is_active} onCheckedChange={(v) => form.setData('is_active', v)} />
                            <span className="text-sm">{c.active}</span>
                        </label>
                    </div>

                    <DialogFooter className="gap-2">
                        <Button type="button" variant="outline" onClick={onClose}>
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

function DetailsPanel({ competitor, details }: { competitor: Competitor; details: Details }) {
    const { t, money, relative, compact } = useI18n();
    const c = t.market.competitors;

    return (
        <div className="space-y-5 p-1">
            <SheetHeader className="space-y-2">
                <SheetTitle className="flex items-center gap-2">
                    {competitor.name}
                    <PlatformBadge platform={competitor.platform} withLabel={false} />
                </SheetTitle>
                <SheetDescription>{c.details}</SheetDescription>
            </SheetHeader>

            <Tabs defaultValue={competitor.is_social ? 'posts' : 'products'} className="px-4">
                <TabsList className="w-full">
                    <TabsTrigger value="products">
                        {c.trackedProducts} ({details.products.length})
                    </TabsTrigger>
                    <TabsTrigger value="posts">
                        {c.trackedPosts} ({details.posts.length})
                    </TabsTrigger>
                </TabsList>

                <TabsContent value="products" className="space-y-2">
                    {details.products.length === 0 ? (
                        <EmptyState icon={Swords} title={t.common.noData} className="py-8" />
                    ) : (
                        details.products.map((p, i) => (
                            <motion.div
                                key={p.id}
                                initial={{ opacity: 0, y: 6 }}
                                animate={{ opacity: 1, y: 0 }}
                                transition={{ delay: Math.min(i * 0.03, 0.3) }}
                                className="flex items-center gap-3 rounded-xl border p-3"
                            >
                                {p.image_url ? (
                                    <img src={p.image_url} alt="" className="size-12 shrink-0 rounded-lg object-cover" loading="lazy" />
                                ) : (
                                    <div className="bg-muted flex size-12 shrink-0 items-center justify-center rounded-lg">
                                        <ColorChips colors={p.colors.slice(0, 1)} showLabels={false} size="md" />
                                    </div>
                                )}
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-1.5">
                                        <span className="truncate text-sm font-medium">{p.name}</span>
                                        {p.is_best_seller && <Trophy className="text-gold size-3.5 shrink-0" aria-label={c.bestSeller} />}
                                    </div>
                                    <div className="text-muted-foreground mt-0.5 flex items-center gap-2 text-xs">
                                        <ColorChips colors={p.colors} showLabels={false} />
                                        <span>
                                            {c.firstSeen} {relative(p.first_seen_at)}
                                        </span>
                                    </div>
                                </div>
                                <div className="shrink-0 text-end">
                                    <div className="num text-sm font-semibold">{money(p.price)}</div>
                                    {p.previous_price !== null && p.previous_price !== p.price && (
                                        <div className="text-muted-foreground num text-xs line-through">{money(p.previous_price)}</div>
                                    )}
                                </div>
                                {p.url && (
                                    <a href={p.url} target="_blank" rel="noreferrer" className="text-muted-foreground hover:text-foreground">
                                        <ArrowUpRight className="size-4 rtl:-scale-x-100" />
                                    </a>
                                )}
                            </motion.div>
                        ))
                    )}
                </TabsContent>

                <TabsContent value="posts" className="space-y-2">
                    {details.posts.length === 0 ? (
                        <EmptyState icon={Swords} title={t.common.noData} className="py-8" />
                    ) : (
                        details.posts.map((post) => (
                            <div key={post.id} className="space-y-1.5 rounded-xl border p-3">
                                <p className="text-sm leading-relaxed">{post.caption}</p>
                                <div className="text-muted-foreground num flex items-center gap-3 text-xs">
                                    <span className="flex items-center gap-1">
                                        <Eye className="size-3.5" />
                                        {compact(post.views)}
                                    </span>
                                    <span className="flex items-center gap-1">
                                        <Heart className="size-3.5" />
                                        {compact(post.likes)}
                                    </span>
                                    <span className="text-primary font-medium">{post.engagement_rate.toFixed(1)}%</span>
                                    <span className="ms-auto">{relative(post.posted_at)}</span>
                                </div>
                            </div>
                        ))
                    )}
                </TabsContent>
            </Tabs>
        </div>
    );
}

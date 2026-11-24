import { Link, router, useForm, usePage } from '@inertiajs/react';
import { CheckCircle2, ChevronDown, FileSpreadsheet, FileText, Gauge, Layers, Loader2, MoreHorizontal, PenLine, Plus, ScanSearch, Search, Trash2, X, XCircle } from 'lucide-react';
import { useEffect, useState } from 'react';
import { DeleteEvaluationButton } from '@/components/evaluation/delete-evaluation-button';
import type { EvaluationSummary, Thresholds } from '@/components/evaluation/types';
import { ColorChips } from '@/components/mojo/color-chips';
import { DecisionBadge } from '@/components/mojo/decision-badge';
import { EmptyState } from '@/components/mojo/empty-state';
import { HoverLift, Stagger, StaggerItem } from '@/components/mojo/motion';
import { PageBody, PageHeader } from '@/components/mojo/page-header';
import { Pagination } from '@/components/mojo/pagination';
import type { Paginated } from '@/components/mojo/pagination';
import { ScoreRing } from '@/components/mojo/score-ring';
import { StatCard } from '@/components/mojo/stat-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useI18n } from '@/hooks/use-i18n';

type Filters = Partial<Record<'recommendation' | 'decision' | 'category' | 'search' | 'from' | 'to' | 'source', string>>;

type Props = {
    evaluations: Paginated<EvaluationSummary>;
    filters: Filters;
    stats: { total: number; accept: number; modify: number; reject: number; pending: number; average: number; overrides: number };
    categories: string[];
    batches: { id: number; name: string; count: number; created_at: string }[];
    thresholds: Thresholds;
};

const ALL = '__all';

export default function EvaluationsIndex({ evaluations, filters, stats, categories, batches, thresholds }: Props) {
    const { t, tr, relative, money } = useI18n();
    const e = t.evaluation;
    const [search, setSearch] = useState(filters.search ?? '');

    const apply = (next: Filters) => {
        const params = Object.fromEntries(Object.entries({ ...filters, ...next }).filter(([, v]) => v !== undefined && v !== '' && v !== ALL));
        router.get('/store/evaluations', params, { preserveState: true, preserveScroll: true, replace: true });
    };

    useEffect(() => {
        if ((filters.search ?? '') === search) {
            return;
        }

        const id = setTimeout(() => apply({ search }), 350);

        return () => clearTimeout(id);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const exportUrl = (format: 'xlsx' | 'pdf') => {
        const params = new URLSearchParams({ format, ...Object.fromEntries(Object.entries(filters).filter(([, v]) => v)) as Record<string, string> });

        return `/store/reports/evaluations/export?${params.toString()}`;
    };

    const hasFilters = Object.values(filters).some(Boolean);

    return (
        <PageBody>
            <PageHeader
                title={e.title}
                description={e.subtitle}
                icon={ScanSearch}
                actions={
                    <>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="outline">
                                    {t.common.export}
                                    <ChevronDown className="size-3.5" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem asChild>
                                    <a href={exportUrl('xlsx')}>
                                        <FileSpreadsheet className="size-4" />
                                        {t.common.exportExcel}
                                    </a>
                                </DropdownMenuItem>
                                <DropdownMenuItem asChild>
                                    <a href={exportUrl('pdf')} target="_blank" rel="noreferrer">
                                        <FileText className="size-4" />
                                        {t.common.exportPdf}
                                    </a>
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                        <Button variant="outline" asChild>
                            <Link href="/store/batches/create">
                                <Layers className="size-4" />
                                {e.batchNew}
                            </Link>
                        </Button>
                        <Button asChild>
                            <Link href="/store/evaluations/create">
                                <Plus className="size-4" />
                                {e.new}
                            </Link>
                        </Button>
                    </>
                }
            />

            <Stagger className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-5 max-sm:[&>*:last-child:nth-child(odd)]:col-span-2">
                <StaggerItem>
                    <StatCard label={e.stats.total} value={stats.total} icon={ScanSearch} hint={stats.pending ? `${stats.pending} ${e.stats.pending}` : undefined} />
                </StaggerItem>
                <StaggerItem>
                    <StatCard label={e.stats.accept} value={stats.accept} icon={CheckCircle2} tone="success" hint={stats.total ? `${Math.round((stats.accept / stats.total) * 100)}%` : undefined} />
                </StaggerItem>
                <StaggerItem>
                    <StatCard label={e.stats.modify} value={stats.modify} icon={PenLine} tone="warning" hint={stats.total ? `${Math.round((stats.modify / stats.total) * 100)}%` : undefined} />
                </StaggerItem>
                <StaggerItem>
                    <StatCard label={e.stats.reject} value={stats.reject} icon={XCircle} tone="danger" hint={stats.total ? `${Math.round((stats.reject / stats.total) * 100)}%` : undefined} />
                </StaggerItem>
                <StaggerItem>
                    <StatCard label={e.stats.average} value={stats.average} icon={Gauge} tone="gold" hint={stats.overrides ? `${stats.overrides} ${e.stats.overrides}` : undefined} />
                </StaggerItem>
            </Stagger>

            {batches.length > 0 && (
                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <span className="text-muted-foreground">{e.list.recentBatches}:</span>
                    {batches.map((b) => (
                        <Link
                            key={b.id}
                            href={`/store/batches/${b.id}`}
                            className="bg-card hover:border-primary/40 flex items-center gap-2 rounded-full border px-3 py-1 transition-colors"
                        >
                            <Layers className="text-primary size-3.5" />
                            <span className="font-medium">{b.name}</span>
                            <span className="text-muted-foreground text-xs">{tr(e.list.items, { n: b.count })}</span>
                        </Link>
                    ))}
                </div>
            )}

            {/* Filters */}
            <div className="bg-card flex flex-col gap-2 rounded-2xl border border-border/70 p-3 sm:gap-3 lg:flex-row lg:items-center">
                <div className="relative flex-1">
                    <Search className="text-muted-foreground pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2" />
                    <Input value={search} onChange={(ev) => setSearch(ev.target.value)} placeholder={e.filters.search} className="h-10 ps-9" />
                </div>
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:flex">
                    <FilterSelect
                        value={filters.recommendation}
                        placeholder={e.filters.allRecommendations}
                        onChange={(v) => apply({ recommendation: v })}
                        options={(['accept', 'modify', 'reject'] as const).map((k) => ({ value: k, label: t.common.decision[k] }))}
                    />
                    <FilterSelect
                        value={filters.decision}
                        placeholder={e.filters.allDecisions}
                        onChange={(v) => apply({ decision: v })}
                        options={[
                            { value: 'none', label: e.filters.noDecision },
                            ...(['approved', 'needs_changes', 'rejected'] as const).map((k) => ({ value: k, label: t.common.decision[k] })),
                        ]}
                    />
                    <FilterSelect
                        value={filters.category}
                        placeholder={e.filters.allCategories}
                        onChange={(v) => apply({ category: v })}
                        options={categories.map((c) => ({ value: c, label: c }))}
                    />
                    <FilterSelect
                        value={filters.source}
                        placeholder={e.filters.sourceDefault}
                        onChange={(v) => apply({ source: v })}
                        options={[
                            { value: 'design', label: e.filters.sourceDesign },
                            { value: 'all', label: e.filters.sourceAll },
                        ]}
                    />
                </div>
                <div className="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] items-center gap-2 lg:flex">
                    <Input type="date" value={filters.from ?? ''} onChange={(ev) => apply({ from: ev.target.value })} className="h-10 w-full lg:w-36" aria-label={e.filters.from} />
                    <Input type="date" value={filters.to ?? ''} onChange={(ev) => apply({ to: ev.target.value })} className="h-10 w-full lg:w-36" aria-label={e.filters.to} />
                    {hasFilters && (
                        <Button variant="ghost" size="icon" className="max-lg:size-10" onClick={() => router.get('/store/evaluations')} aria-label={e.filters.reset}>
                            <X className="size-4" />
                        </Button>
                    )}
                </div>
            </div>

            {evaluations.data.length === 0 ? (
                <div className="bg-card rounded-2xl border border-border/70">
                    <EmptyState
                        icon={ScanSearch}
                        title={hasFilters ? t.common.noResults : e.list.emptyTitle}
                        description={hasFilters ? undefined : e.list.emptyBody}
                        action={
                            !hasFilters && (
                                <Button asChild>
                                    <Link href="/store/evaluations/create">
                                        <Plus className="size-4" />
                                        {e.new}
                                    </Link>
                                </Button>
                            )
                        }
                    />
                </div>
            ) : (
                <Stagger key={evaluations.current_page + JSON.stringify(filters)} className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {evaluations.data.map((ev) => (
                        <StaggerItem key={ev.id}>
                            <HoverLift className="bg-card group flex h-full flex-col rounded-2xl border border-border/70 shadow-[0_1px_2px_rgba(16,24,40,0.04)] transition-shadow hover:shadow-md">
                                <Link
                                    href={`/store/evaluations/${ev.id}`}
                                    prefetch
                                    className="flex flex-1 gap-4 rounded-t-2xl p-4"
                                >
                                    <div className="bg-muted relative size-20 shrink-0 overflow-hidden rounded-xl">
                                        {ev.image_url ? (
                                            <img src={ev.image_url} alt="" className="size-full object-cover transition-transform duration-500 group-hover:scale-105" />
                                        ) : (
                                            <div className="flex size-full items-center justify-center">
                                                <ColorChips colors={ev.colors.slice(0, 3)} showLabels={false} />
                                            </div>
                                        )}
                                    </div>
                                    <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                                        <div className="flex items-start justify-between gap-2">
                                            <h3 className="line-clamp-2 leading-snug font-semibold">{ev.name}</h3>
                                            {ev.status === 'completed' ? (
                                                <ScoreRing score={ev.score} size={52} stroke={5} accept={thresholds.accept} reject={thresholds.reject} className="shrink-0" />
                                            ) : (
                                                <DecisionBadge value={ev.status} />
                                            )}
                                        </div>
                                        <p className="text-muted-foreground truncate text-xs">
                                            {[ev.category, ev.price ? money(ev.price) : null].filter(Boolean).join(' · ')}
                                        </p>
                                        <div className="mt-auto flex flex-wrap items-center gap-1.5 pt-1">
                                            <DecisionBadge value={ev.recommendation} />
                                            {ev.final_decision && <DecisionBadge value={ev.final_decision} />}
                                            {ev.version > 1 && <Badge variant="info">{tr(e.list.version, { n: ev.version })}</Badge>}
                                            {ev.source === 'design' && <Badge variant="gold">{e.list.designSource}</Badge>}
                                            {ev.override && <Badge variant="warning">{e.show.overrideBadge}</Badge>}
                                        </div>
                                        <p className="text-muted-foreground hidden text-[11px] sm:block" suppressHydrationWarning>
                                            {relative(ev.created_at)}
                                            {ev.user ? ` · ${tr(e.list.by, { name: ev.user })}` : ''}
                                        </p>
                                    </div>
                                </Link>
                                <div className="hidden justify-end border-t border-border/50 px-3 py-1.5 sm:flex">
                                    <DeleteEvaluationButton evaluation={ev} />
                                </div>
                                {/* Phone: the date moves down here and delete waits behind a menu. */}
                                <div className="flex items-center gap-2 border-t border-border/50 ps-4 pe-0.5 sm:hidden">
                                    <p className="text-muted-foreground min-w-0 flex-1 truncate text-[11px]" suppressHydrationWarning>
                                        {relative(ev.created_at)}
                                        {ev.user ? ` · ${tr(e.list.by, { name: ev.user })}` : ''}
                                    </p>
                                    <CardMenu evaluation={ev} />
                                </div>
                            </HoverLift>
                        </StaggerItem>
                    ))}
                </Stagger>
            )}

            <Pagination meta={evaluations} />
        </PageBody>
    );
}

function FilterSelect({
    value,
    placeholder,
    options,
    onChange,
}: {
    value?: string;
    placeholder: string;
    options: { value: string; label: string }[];
    onChange: (value: string | undefined) => void;
}) {
    return (
        <Select value={value ?? ALL} onValueChange={(v) => onChange(v === ALL ? undefined : v)}>
            <SelectTrigger className="h-10 w-full rounded-lg lg:w-44">
                <SelectValue placeholder={placeholder} />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={ALL}>{placeholder}</SelectItem>
                {options.map((o) => (
                    <SelectItem key={o.value} value={o.value}>
                        {o.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

/**
 * The phone card's "⋯" menu: delete sits here instead of a red button on every card,
 * with the same confirm step as the desktop button.
 */
function CardMenu({ evaluation }: { evaluation: { id: number; name: string } }) {
    const { t, tr, isRtl } = useI18n();
    const { url } = usePage();
    const [open, setOpen] = useState(false);
    const [failed, setFailed] = useState(false);
    const form = useForm({ return_to: url });
    const c = t.evaluation.deletion;

    const remove = () => {
        setFailed(false);
        form.transform(() => ({ return_to: url }));
        form.delete(`/store/evaluations/${evaluation.id}`, {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
            onError: () => setFailed(true),
        });
    };

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button type="button" variant="ghost" size="icon" className="text-muted-foreground size-11 shrink-0" aria-label={t.common.more}>
                        <MoreHorizontal className="size-5" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem
                        className="text-danger focus:bg-danger/10 focus:text-danger min-h-11 [&_svg]:!text-danger"
                        onSelect={() => setOpen(true)}
                    >
                        <Trash2 className="size-4" />
                        {c.button}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            <Dialog
                open={open}
                onOpenChange={(value) => {
                    if (!form.processing) setOpen(value);
                }}
            >
                <DialogContent dir={isRtl ? 'rtl' : 'ltr'} className="sm:max-w-md">
                    <DialogHeader className="text-start sm:text-start">
                        <DialogTitle>{c.button}</DialogTitle>
                        <DialogDescription className="leading-7 break-words">{tr(c.confirm, { name: evaluation.name })}</DialogDescription>
                    </DialogHeader>
                    {failed && (
                        <p role="alert" className="text-danger text-sm">
                            {c.failed}
                        </p>
                    )}
                    <DialogFooter className="gap-2">
                        <Button type="button" variant="outline" disabled={form.processing} onClick={() => setOpen(false)}>
                            {t.common.cancel}
                        </Button>
                        <Button type="button" variant="destructive" disabled={form.processing} onClick={remove}>
                            {form.processing ? <Loader2 className="size-4 motion-safe:animate-spin" /> : <Trash2 className="size-4" />}
                            {form.processing ? c.deleting : c.button}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

import { Link, router } from '@inertiajs/react';
import { CheckCircle2, ChevronDown, FileSpreadsheet, FileText, Gauge, Layers, PenLine, Plus, ScanSearch, Search, X, XCircle } from 'lucide-react';
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

            <Stagger className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
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
            <div className="bg-card flex flex-col gap-3 rounded-2xl border border-border/70 p-3 lg:flex-row lg:items-center">
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
                <div className="flex items-center gap-2">
                    <Input type="date" value={filters.from ?? ''} onChange={(ev) => apply({ from: ev.target.value })} className="h-10 w-full lg:w-36" aria-label={e.filters.from} />
                    <Input type="date" value={filters.to ?? ''} onChange={(ev) => apply({ to: ev.target.value })} className="h-10 w-full lg:w-36" aria-label={e.filters.to} />
                    {hasFilters && (
                        <Button variant="ghost" size="icon" onClick={() => router.get('/store/evaluations')} aria-label={e.filters.reset}>
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
                                        <p className="text-muted-foreground text-[11px]" suppressHydrationWarning>
                                            {relative(ev.created_at)}
                                            {ev.user ? ` · ${tr(e.list.by, { name: ev.user })}` : ''}
                                        </p>
                                    </div>
                                </Link>
                                <div className="flex justify-end border-t border-border/50 px-3 py-1.5">
                                    <DeleteEvaluationButton evaluation={ev} />
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

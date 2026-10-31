import { Link, router } from '@inertiajs/react';
import { Heart, Images, Search, WandSparkles, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { DesignItem } from '@/components/evaluation/types';
import { EmptyState } from '@/components/mojo/empty-state';
import { Stagger, StaggerItem } from '@/components/mojo/motion';
import { PageBody, PageHeader } from '@/components/mojo/page-header';
import { Pagination } from '@/components/mojo/pagination';
import type { Paginated } from '@/components/mojo/pagination';
import { DesignCard, useDesignLabels } from '@/components/studio/design-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

type Filters = Partial<Record<'status' | 'favorite' | 'tag' | 'output_type' | 'search', string>>;

type Props = {
    designs: Paginated<DesignItem>;
    filters: Filters;
    counts: Record<'all' | 'favorite' | 'draft' | 'in_review' | 'approved' | 'rejected', number>;
    tags: string[];
    outputTypes: string[];
};

const ALL = '__all';

/**
 * FR-4.8: every design with status, favourites, tags, search and download.
 */
export default function Library({ designs, filters, counts, tags, outputTypes }: Props) {
    const { t } = useI18n();
    const s = t.studio;
    const { tagLabel } = useDesignLabels();
    const [search, setSearch] = useState(filters.search ?? '');
    const outputs = s.form.outputs as Record<string, string>;

    const apply = (next: Filters) => {
        const params = Object.fromEntries(Object.entries({ ...filters, ...next }).filter(([, v]) => v !== undefined && v !== '' && v !== ALL));
        router.get('/store/library', params, { preserveState: true, preserveScroll: true, replace: true });
    };

    useEffect(() => {
        if ((filters.search ?? '') === search) {
            return;
        }

        const id = setTimeout(() => apply({ search }), 350);

        return () => clearTimeout(id);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const tabs: { key: string; label: string; count: number; active: boolean; onClick: () => void }[] = [
        { key: 'all', label: s.library.all, count: counts.all, active: !filters.status && !filters.favorite, onClick: () => apply({ status: undefined, favorite: undefined }) },
        { key: 'fav', label: s.library.favorites, count: counts.favorite, active: filters.favorite === '1', onClick: () => apply({ favorite: '1', status: undefined }) },
        ...(['draft', 'in_review', 'approved', 'rejected'] as const).map((st) => ({
            key: st,
            label: s.design.status[st],
            count: counts[st],
            active: filters.status === st,
            onClick: () => apply({ status: st, favorite: undefined }),
        })),
    ];

    const hasFilters = Object.values(filters).some(Boolean);

    return (
        <PageBody>
            <PageHeader
                title={s.libraryTitle}
                description={s.librarySubtitle}
                icon={Images}
                actions={
                    <Button asChild variant="gold">
                        <Link href="/store/studio">
                            <WandSparkles className="size-4" />
                            {s.title}
                        </Link>
                    </Button>
                }
            />

            <div className="flex flex-wrap gap-2">
                {tabs.map((tab) => (
                    <button
                        key={tab.key}
                        type="button"
                        onClick={tab.onClick}
                        className={cn(
                            'flex cursor-pointer items-center gap-2 rounded-full border px-3.5 py-1.5 text-sm transition-colors',
                            tab.active ? 'border-primary bg-primary text-primary-foreground' : 'bg-card hover:border-primary/40',
                        )}
                    >
                        {tab.key === 'fav' && <Heart className="size-3.5" />}
                        {tab.label}
                        <span className={cn('num rounded-full px-1.5 text-xs', tab.active ? 'bg-white/20' : 'bg-muted text-muted-foreground')}>{tab.count}</span>
                    </button>
                ))}
            </div>

            <div className="bg-card flex flex-col gap-3 rounded-2xl border border-border/70 p-3 md:flex-row md:items-center">
                <div className="relative flex-1">
                    <Search className="text-muted-foreground pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2" />
                    <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={s.library.search} className="h-10 ps-9" />
                </div>
                <Select value={filters.tag ?? ALL} onValueChange={(v) => apply({ tag: v === ALL ? undefined : v })}>
                    <SelectTrigger className="h-10 w-full md:w-52">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ALL}>{s.library.allTags}</SelectItem>
                        {tags.map((tag) => (
                            <SelectItem key={tag} value={tag}>
                                {tagLabel(tag)}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <Select value={filters.output_type ?? ALL} onValueChange={(v) => apply({ output_type: v === ALL ? undefined : v })}>
                    <SelectTrigger className="h-10 w-full md:w-52">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ALL}>{s.library.allOutputs}</SelectItem>
                        {outputTypes.map((o) => (
                            <SelectItem key={o} value={o}>
                                {outputs[o] ?? o}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                {hasFilters && (
                    <Button variant="ghost" size="icon" onClick={() => router.get('/store/library')} aria-label={t.common.clear}>
                        <X className="size-4" />
                    </Button>
                )}
            </div>

            {designs.data.length === 0 ? (
                <div className="bg-card rounded-2xl border">
                    <EmptyState
                        icon={Images}
                        title={hasFilters ? t.common.noResults : s.library.emptyTitle}
                        description={hasFilters ? undefined : s.library.emptyBody}
                        action={
                            !hasFilters && (
                                <Button asChild>
                                    <Link href="/store/studio">{s.library.goStudio}</Link>
                                </Button>
                            )
                        }
                    />
                </div>
            ) : (
                <Stagger key={designs.current_page + JSON.stringify(filters)} className="columns-1 gap-4 sm:columns-2 lg:columns-3 2xl:columns-4">
                    {designs.data.map((design) => (
                        <StaggerItem key={design.id} className="mb-4 break-inside-avoid">
                            <DesignCard design={design} />
                        </StaggerItem>
                    ))}
                </Stagger>
            )}

            <Pagination meta={designs} />
        </PageBody>
    );
}

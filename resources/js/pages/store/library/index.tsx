import { Link, router } from '@inertiajs/react';
import { FolderOpen, Heart, Images, LayoutGrid, Search, WandSparkles, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { DesignItem } from '@/components/evaluation/types';
import { EmptyState } from '@/components/mojo/empty-state';
import { Stagger, StaggerItem } from '@/components/mojo/motion';
import { PageBody, PageHeader } from '@/components/mojo/page-header';
import { Pagination } from '@/components/mojo/pagination';
import type { Paginated } from '@/components/mojo/pagination';
import { CollectionCard } from '@/components/studio/collection-card';
import type { LibraryCollection } from '@/components/studio/collection-card';
import { DesignCard, useDesignLabels } from '@/components/studio/design-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

type Filters = Partial<Record<'status' | 'favorite' | 'tag' | 'output_type' | 'search' | 'collection', string>>;

type Props = {
    view: 'designs' | 'collections';
    designs: Paginated<DesignItem> | null;
    collections: Paginated<LibraryCollection> | null;
    /** The collection whose designs are shown, when browsing one. */
    collection: { id: number; title: string } | null;
    filters: Filters;
    counts: Record<'collections' | 'all' | 'favorite' | 'draft' | 'in_review' | 'approved' | 'rejected', number>;
    tags: string[];
    outputTypes: string[];
};

const ALL = '__all';

/**
 * FR-4.8: every design with status, favourites, tags, search and download;
 * the AI designer's finished collections can be browsed as collections.
 */
export default function Library({ view, designs, collections, collection, filters, counts, tags, outputTypes }: Props) {
    const { t, tr } = useI18n();
    const s = t.studio;
    const { tagLabel } = useDesignLabels();
    const [search, setSearch] = useState(filters.search ?? '');
    const outputs = s.form.outputs as Record<string, string>;

    const apply = (next: Filters) => {
        const params = Object.fromEntries(Object.entries({ ...filters, ...next }).filter(([, v]) => v !== undefined && v !== '' && v !== ALL));
        router.get('/store/library', { ...params, ...(view === 'collections' ? { view } : {}) }, { preserveState: true, preserveScroll: true, replace: true });
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
    const lc = s.library.collections;
    const switchView = (next: Props['view']) => router.get('/store/library', next === 'collections' ? { view: next } : {});

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

            <div className="bg-muted inline-flex w-fit gap-1 rounded-xl p-1">
                {(
                    [
                        ['designs', s.library.views.designs, counts.all, LayoutGrid],
                        ['collections', s.library.views.collections, counts.collections, FolderOpen],
                    ] as const
                ).map(([key, label, n, Icon]) => (
                    <button
                        key={key}
                        type="button"
                        onClick={() => switchView(key)}
                        className={cn(
                            'flex cursor-pointer items-center gap-2 rounded-lg px-4 py-1.5 text-sm font-medium transition-colors',
                            view === key ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                        )}
                    >
                        <Icon className="size-4" />
                        {label}
                        <span className="num bg-background/70 rounded-full px-1.5 text-xs">{n}</span>
                    </button>
                ))}
            </div>

            {view === 'designs' && collection && (
                <div className="border-gold/40 bg-gold-soft flex flex-wrap items-center gap-3 rounded-2xl border px-4 py-3">
                    <FolderOpen className="text-gold-foreground dark:text-gold size-4 shrink-0" />
                    <span className="min-w-0 flex-1 text-sm font-medium">{tr(lc.filtered, { title: collection.title })}</span>
                    <Button size="sm" variant="gold" asChild>
                        <Link href={`/store/studio?mode=designer&collection=${collection.id}`}>
                            <WandSparkles className="size-4" />
                            {lc.open}
                        </Link>
                    </Button>
                    <Button size="sm" variant="outline" onClick={() => switchView('collections')}>
                        {lc.all}
                    </Button>
                    <Button variant="ghost" size="icon" onClick={() => router.get('/store/library')} aria-label={t.common.clear}>
                        <X className="size-4" />
                    </Button>
                </div>
            )}

            {view === 'designs' && (
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
            )}

            <div className="bg-card flex flex-col gap-3 rounded-2xl border border-border/70 p-3 md:flex-row md:items-center">
                <div className="relative flex-1">
                    <Search className="text-muted-foreground pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2" />
                    <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={view === 'collections' ? lc.search : s.library.search} className="h-10 ps-9" />
                </div>
                {view === 'designs' && (
                    <>
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
                    </>
                )}
                {hasFilters && (
                    <Button variant="ghost" size="icon" onClick={() => switchView(view)} aria-label={t.common.clear}>
                        <X className="size-4" />
                    </Button>
                )}
            </div>

            {view === 'collections' && collections && (
                <>
                    {collections.data.length === 0 ? (
                        <div className="bg-card rounded-2xl border">
                            <EmptyState
                                icon={FolderOpen}
                                title={hasFilters ? t.common.noResults : lc.emptyTitle}
                                description={hasFilters ? undefined : lc.emptyBody}
                                action={
                                    !hasFilters && (
                                        <Button asChild variant="gold">
                                            <Link href="/store/studio?mode=designer">
                                                <WandSparkles className="size-4" />
                                                {lc.goDesigner}
                                            </Link>
                                        </Button>
                                    )
                                }
                            />
                        </div>
                    ) : (
                        <Stagger key={collections.current_page + JSON.stringify(filters)} className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                            {collections.data.map((c) => (
                                <StaggerItem key={c.id}>
                                    <CollectionCard collection={c} />
                                </StaggerItem>
                            ))}
                        </Stagger>
                    )}
                    <Pagination meta={collections} />
                </>
            )}

            {view === 'designs' && designs && (designs.data.length === 0 ? (
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
            ))}

            {view === 'designs' && designs && <Pagination meta={designs} />}
        </PageBody>
    );
}

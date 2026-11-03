import { Link, router } from '@inertiajs/react';
import { AlertTriangle, Banknote, Package, Search, Turtle } from 'lucide-react';
import { useState } from 'react';
import { PageBody, PageHeader } from '@/components/mojo/page-header';
import { Pagination } from '@/components/mojo/pagination';
import type { Paginated } from '@/components/mojo/pagination';
import { StatCard } from '@/components/mojo/stat-card';
import {
    ClassificationButton,
    ClassificationProgress,
} from '@/components/store/classification-refresh';
import type { ClassificationRun } from '@/components/store/classification-refresh';
import { StagnantProductGrid } from '@/components/store/stagnant-products';
import type {
    StagnantProduct,
    StagnantSummary,
} from '@/components/store/stagnant-products';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/hooks/use-i18n';

type Props = {
    products: Paginated<StagnantProduct>;
    summary: StagnantSummary;
    filters: { search?: string; category?: string; class?: string };
    categories: string[];
    classification: ClassificationRun | null;
};

export default function Stagnant({
    products,
    summary,
    filters,
    categories,
    classification,
}: Props) {
    const { t, tr, number, money } = useI18n();
    const s = t.stagnant;
    const [search, setSearch] = useState(filters.search ?? '');
    const [category, setCategory] = useState(filters.category ?? '');
    return (
        <PageBody>
            <PageHeader
                title={s.title}
                description={s.subtitle}
                icon={Turtle}
                actions={
                    <>
                        <ClassificationButton run={classification} />
                        <Button asChild variant="outline">
                            <Link href="/store/performance">
                                {s.performance}
                            </Link>
                        </Button>
                    </>
                }
            />
            <ClassificationProgress run={classification} />
            {summary.needs_reclassification && (
                <p
                    role="status"
                    className="rounded-xl border border-warning/30 bg-warning/10 p-4 text-sm leading-7"
                >
                    {s.refreshNeeded}
                </p>
            )}
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label={s.total}
                    value={summary.stagnant}
                    icon={Turtle}
                    tone="danger"
                />
                <StatCard
                    label={s.atRisk}
                    value={summary.at_risk}
                    icon={AlertTriangle}
                    tone="warning"
                />
                <StatCard
                    label={s.stock}
                    value={summary.stock}
                    icon={Package}
                />
                <StatCard
                    label={s.stockValue}
                    value={summary.stock_value}
                    format={money}
                    icon={Banknote}
                    hint={s.stockValueHint}
                    tone="gold"
                />
            </div>
            <div className="space-y-2 text-xs leading-6 text-muted-foreground">
                <p>{s.classification}</p>
                <p>{s.period}</p>
                <p>{s.reclassifyHint}</p>
            </div>
            <nav aria-label={s.statusFilter} className="flex flex-wrap gap-2">
                {[
                    { value: '', label: s.allStatuses, count: summary.total },
                    {
                        value: 'at_risk',
                        label: s.atRisk,
                        count: summary.at_risk,
                    },
                    {
                        value: 'stagnant',
                        label: s.total,
                        count: summary.stagnant,
                    },
                ].map((tab) => (
                    <Button
                        key={tab.value}
                        asChild
                        variant={
                            (filters.class ?? '') === tab.value
                                ? 'default'
                                : 'outline'
                        }
                    >
                        <Link
                            href="/store/stagnant"
                            data={{
                                search: filters.search ?? '',
                                category: filters.category ?? '',
                                class: tab.value,
                            }}
                            preserveScroll
                            aria-current={
                                (filters.class ?? '') === tab.value
                                    ? 'page'
                                    : undefined
                            }
                        >
                            {tab.label}{' '}
                            <span className="ms-1">{number(tab.count)}</span>
                        </Link>
                    </Button>
                ))}
            </nav>
            {summary.unclassified > 0 && (
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-muted/40 p-4 text-sm leading-7">
                    <p className="flex-1 text-muted-foreground">
                        {tr(s.unclassified, {
                            count: number(summary.unclassified),
                        })}
                    </p>
                    <Button asChild variant="outline" size="sm">
                        <Link href="/store/settings/integration">
                            {s.importSales}
                        </Link>
                    </Button>
                </div>
            )}
            <form
                className="flex flex-col gap-3 sm:flex-row"
                onSubmit={(event) => {
                    event.preventDefault();
                    router.get(
                        '/store/stagnant',
                        { search, category, class: filters.class ?? '' },
                        { preserveScroll: true },
                    );
                }}
            >
                <Input
                    aria-label={s.search}
                    placeholder={s.search}
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    className="h-10 flex-1"
                />
                <select
                    aria-label={s.categories}
                    value={category}
                    onChange={(e) => setCategory(e.target.value)}
                    className="h-10 rounded-md border bg-background px-3 text-sm sm:max-w-60"
                >
                    <option value="">{s.categories}</option>
                    {categories.map((value) => (
                        <option key={value} value={value}>
                            {value}
                        </option>
                    ))}
                </select>
                <Button type="submit">
                    <Search className="size-4" />
                    {s.searchButton}
                </Button>
                {(filters.search || filters.category || filters.class) && (
                    <Button asChild variant="ghost">
                        <Link href="/store/stagnant">{s.reset}</Link>
                    </Button>
                )}
            </form>
            <StagnantProductGrid
                products={products.data}
                filtered={
                    !!(filters.search || filters.category || filters.class)
                }
                stale={summary.needs_reclassification}
            />
            <Pagination meta={products} />
        </PageBody>
    );
}

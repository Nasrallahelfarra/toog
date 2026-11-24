import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useI18n } from '@/hooks/use-i18n';
import { cn } from '@/lib/utils';

export type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    per_page: number;
};

type Visit = {
    /** Reload only these props (e.g. the list itself), keeping the rest of the page as it is. */
    only?: string[];
};

/**
 * Pager for a Laravel LengthAwarePaginator passed as a prop.
 *
 * On a phone it is two large "previous" and "next" buttons with "page 2 of
 * 5" between them; from the sm breakpoint up, the numbered pages.
 */
export function Pagination({ meta, className, only }: { meta: Omit<Paginated<unknown>, 'data'>; className?: string } & Visit) {
    const { t, tr, number } = useI18n();

    if (meta.last_page <= 1) {
        return null;
    }

    const links = meta.links.slice(1, -1);
    const prev = meta.links[0];
    const next = meta.links[meta.links.length - 1];
    const visit = only ? { only, preserveState: true } : {};

    const item = 'flex h-8 min-w-8 items-center justify-center rounded-lg px-2 text-sm transition-colors';
    const big = 'flex h-11 flex-1 items-center justify-center gap-1.5 rounded-xl border px-3 text-sm font-medium transition-colors';

    return (
        <div className={cn('flex flex-col items-center justify-between gap-3 sm:flex-row', className)}>
            <p className="text-muted-foreground hidden text-xs sm:block">
                {tr(t.common.showing, { from: number(meta.from ?? 0), to: number(meta.to ?? 0), total: number(meta.total) })}
            </p>

            {/* Phone: big targets, and where you are in words. */}
            <nav className="flex w-full items-center gap-2 sm:hidden" aria-label={t.common.page}>
                <PageLink url={prev.url} className={big} label={t.common.previous} visit={visit}>
                    <ChevronRight className="size-4 ltr:rotate-180" />
                    {t.common.previous}
                </PageLink>
                <span className="num text-muted-foreground shrink-0 px-1 text-xs whitespace-nowrap">
                    {tr(t.common.pageOf, { page: number(meta.current_page), last: number(meta.last_page) })}
                </span>
                <PageLink url={next.url} className={big} label={t.common.next} visit={visit}>
                    {t.common.next}
                    <ChevronLeft className="size-4 ltr:rotate-180" />
                </PageLink>
            </nav>

            <nav className="hidden items-center gap-1 sm:flex" aria-label={t.common.page}>
                <PageLink url={prev.url} className={item} label={t.common.previous} visit={visit}>
                    <ChevronLeft className="size-4 rtl:rotate-180" />
                </PageLink>
                {links.map((link, i) =>
                    link.url ? (
                        <Link
                            key={i}
                            href={link.url}
                            preserveScroll
                            {...visit}
                            aria-current={link.active ? 'page' : undefined}
                            className={cn(item, link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted')}
                        >
                            <span className="num">{link.label}</span>
                        </Link>
                    ) : (
                        <span key={i} className={cn(item, 'text-muted-foreground')}>
                            …
                        </span>
                    ),
                )}
                <PageLink url={next.url} className={item} label={t.common.next} visit={visit}>
                    <ChevronRight className="size-4 rtl:rotate-180" />
                </PageLink>
            </nav>
        </div>
    );
}

function PageLink({ url, className, label, visit, children }: { url: string | null; className: string; label: string; visit: Visit; children: React.ReactNode }) {
    return url ? (
        <Link href={url} preserveScroll {...visit} aria-label={label} className={cn(className, 'hover:bg-muted')}>
            {children}
        </Link>
    ) : (
        <span aria-label={label} aria-disabled className={cn(className, 'opacity-40')}>
            {children}
        </span>
    );
}

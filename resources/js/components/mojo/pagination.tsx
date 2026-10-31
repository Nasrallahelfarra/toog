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

/**
 * Pager for a Laravel LengthAwarePaginator passed as a prop.
 */
export function Pagination({ meta, className }: { meta: Omit<Paginated<unknown>, 'data'>; className?: string }) {
    const { t, tr, number } = useI18n();

    if (meta.last_page <= 1) {
        return null;
    }

    const links = meta.links.slice(1, -1);
    const prev = meta.links[0];
    const next = meta.links[meta.links.length - 1];

    const item = 'flex h-8 min-w-8 items-center justify-center rounded-lg px-2 text-sm transition-colors';

    return (
        <div className={cn('flex flex-col items-center justify-between gap-3 sm:flex-row', className)}>
            <p className="text-muted-foreground text-xs">
                {tr(t.common.showing, { from: number(meta.from ?? 0), to: number(meta.to ?? 0), total: number(meta.total) })}
            </p>
            <nav className="flex items-center gap-1">
                <PageLink url={prev.url} className={item} label={t.common.previous}>
                    <ChevronLeft className="size-4 rtl:rotate-180" />
                </PageLink>
                {links.map((link, i) =>
                    link.url ? (
                        <Link
                            key={i}
                            href={link.url}
                            preserveScroll
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
                <PageLink url={next.url} className={item} label={t.common.next}>
                    <ChevronRight className="size-4 rtl:rotate-180" />
                </PageLink>
            </nav>
        </div>
    );
}

function PageLink({ url, className, label, children }: { url: string | null; className: string; label: string; children: React.ReactNode }) {
    return url ? (
        <Link href={url} preserveScroll aria-label={label} className={cn(className, 'hover:bg-muted')}>
            {children}
        </Link>
    ) : (
        <span aria-label={label} className={cn(className, 'opacity-40')}>
            {children}
        </span>
    );
}

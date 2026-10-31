import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

type Filters = Record<string, string | null | undefined>;

/**
 * Keeps list filters in the query string. Changing a filter reloads the
 * page data (keeping scroll and component state) and resets pagination.
 */
export function useFilters(url: string, initial: Filters) {
    const [filters, setFilters] = useState<Filters>(initial);

    const apply = (next: Filters) => {
        const query = Object.fromEntries(Object.entries(next).filter(([, v]) => v !== null && v !== undefined && v !== ''));
        router.get(url, query, { preserveState: true, preserveScroll: true, replace: true });
    };

    const set = (key: string, value: string | null) => {
        const next = { ...filters, [key]: value };
        setFilters(next);
        apply(next);
    };

    return { filters, set };
}

/**
 * Search box value that calls `onSearch` once typing pauses.
 */
export function useDebouncedSearch(initial: string, onSearch: (value: string) => void, delay = 350) {
    const [value, setValue] = useState(initial);
    const callback = useRef(onSearch);
    const first = useRef(true);

    useEffect(() => {
        callback.current = onSearch;
    });

    useEffect(() => {
        if (first.current) {
            first.current = false;

            return;
        }

        const id = setTimeout(() => callback.current(value), delay);

        return () => clearTimeout(id);
    }, [value, delay]);

    return [value, setValue] as const;
}

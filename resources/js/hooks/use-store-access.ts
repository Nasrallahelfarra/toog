import { usePage } from '@inertiajs/react';

/**
 * What the signed-in user may open in the current store. The owner (and the
 * platform admin inside a store) opens everything; a staff member opens the
 * pages the owner granted. The server enforces the same rule on every
 * route, so this only decides what to show.
 */
export function useStoreAccess() {
    const access = usePage().props.access;
    const owner = access?.owner ?? false;
    const sections = access?.sections ?? [];

    return {
        owner,
        staff: access !== null && access !== undefined && !owner,
        /** True when the user holds at least one of the sections. */
        can: (...wanted: string[]) => owner || wanted.some((section) => sections.includes(section)),
    };
}

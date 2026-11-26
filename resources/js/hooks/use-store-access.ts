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
        /** Where this user lands in the store: the first page they open. */
        home: access?.home ?? '/store',
        /**
         * True when the user opens at least one of the sections. Pages
         * switched off for stores are in no one's list, the owner's
         * included; the Zid connection is the owner's alone.
         */
        can: (...wanted: string[]) => wanted.some((section) => sections.includes(section) || (owner && OWNER_PAGES.includes(section))),
    };
}

/** Pages that are not sections: always the owner's, never staff's. */
const OWNER_PAGES = ['connection'];

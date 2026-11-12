import { usePage } from '@inertiajs/react';

/**
 * Whether this store may see revenue. The platform admin switches it on per
 * store; while off the server sends no revenue figures and pages show units
 * sold and orders instead.
 */
export function useRevenueVisible(): boolean {
    return usePage().props.currentStore?.show_revenue === true;
}

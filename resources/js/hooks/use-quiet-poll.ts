import type { PollOptions, ReloadOptions } from '@inertiajs/core';
import { usePoll } from '@inertiajs/react';

/** A background check that fails stays quiet: the next one tries again. */
const quiet = {
    onHttpException: () => false,
    onNetworkError: () => false,
} satisfies Partial<ReloadOptions>;

/**
 * Inertia's usePoll for background progress checks. A failed check (a
 * gateway timeout during a deploy, a dropped connection) never covers the
 * page with an error; the next tick asks again.
 */
export function useQuietPoll(
    interval: number,
    requestOptions: ReloadOptions | (() => ReloadOptions) = {},
    options?: PollOptions,
) {
    return usePoll(
        interval,
        typeof requestOptions === 'function'
            ? () => ({ ...requestOptions(), ...quiet })
            : { ...requestOptions, ...quiet },
        options,
    );
}

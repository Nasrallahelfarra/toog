import type { zidSync as ar } from '../ar/zid-sync';

export const zidSync: typeof ar = {
    title: 'Zid import progress',
    hint: 'Counts update after each page received from Zid. You can leave and return to check progress.',
    stages: {
        queued: 'Waiting for a worker',
        connecting: 'Checking connection',
        products: 'Reading products',
        orders: 'Reading orders',
        saving: 'Saving and analyzing',
        completed: 'Import complete',
        failed: 'Import stopped',
        running: 'Importing',
        waiting: 'Waiting for Zid',
    },
    products: 'Products read',
    orders: 'Orders read',
    productsSaved: 'Products saved',
    ordersSaved: 'Orders analyzed',
    pages: '{n} pages',
    elapsed: 'Elapsed time',
    seconds: '{n} seconds',
    updated: 'Last recorded progress',
    queuedAt: 'Requested at',
    retrying:
        'Zid requested a temporary pause. Import will resume automatically from the last saved page. Next attempt: {time}.',
    retryingApi:
        'Zid is temporarily not responding. Import will resume automatically from the last saved page. Next attempt: {time}.',
    reading:
        'These counts reflect data read. Results are saved after reading and validation finish. Orders cover the last 12 months.',
    saved: 'Results were saved and product analysis updated. Analyzed orders may differ from orders read because canceled or invalid orders are excluded.',
    waiting:
        'Import has not started. If this continues, the platform needs to check its queue worker.',
    slow: 'No new progress for over two minutes. The import may be waiting for Zid; completion has not been confirmed.',
    network:
        'Could not refresh import status. Retrying automatically; these are the last received counts.',
    detail: 'Zid replied: {detail}',
    resumeHint:
        'Pages read before the stop are saved. You can continue from the last page instead of reading everything again.',
    resume: 'Continue from last page',
    errors: {
        queue_failed:
            'Could not queue the import. Check the queue connection and worker, then retry.',
        queue_stalled:
            'The job expired before starting. Check the queue worker, then sync again.',
        worker_stopped:
            'The job exceeded its execution window without recording completion. The worker may have stopped. Check it before retrying.',
        timed_out:
            'The import timed out. No partial results were saved; retry or check the failed job log.',
        catalog_busy:
            'Another task is updating this catalog. Wait briefly, then sync again.',
        import_failed:
            'Reading or saving failed. The store’s previous data is unchanged. Check the failed job log, then retry.',
        authorization_failed:
            'Zid refused access. Reconnect the store with product and order permissions, then sync again.',
        zid_rejected:
            'Zid rejected one of the read requests. The store’s previous data is unchanged.',
        api_unavailable:
            'Zid did not respond after several waits. The store’s previous data is unchanged.',
        job_failed:
            'The import job stopped. Check the failed job log, then sync again.',
        rate_limited:
            'Zid continued limiting requests after several waits. Please sync again later.',
    },
};

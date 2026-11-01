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
    reading:
        'These counts reflect data read. Results are saved after reading and validation finish. Orders cover the last 12 months.',
    saved: 'Results were saved and product analysis updated. Analyzed orders may differ from orders read because canceled or invalid orders are excluded.',
    waiting:
        'Import has not started. If this continues, the platform needs to check its queue worker.',
    slow: 'No new progress for over two minutes. The import may be waiting for Zid; completion has not been confirmed.',
    network:
        'Could not refresh import status. Retrying automatically; these are the last received counts.',
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
            'Reading or saving failed. Previous data was preserved. Check Zid product/order permissions and the failed job log, then retry.',
        job_failed:
            'The import job stopped. Check the failed job log, then sync again.',
    },
};

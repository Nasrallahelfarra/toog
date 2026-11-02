<?php

// Messages for the store dashboard, performance and settings screens.
return [
    'demo_cleanup_success' => 'Removed :products demo products and :sales demo sales records, and updated the remaining product metrics.',
    'demo_cleanup_empty' => 'No matching demo products remain to remove.',
    'demo_cleanup_errors' => [
        'busy' => 'A sync or cleanup is queued or running. Wait for completion, then refresh the preview.',
        'changed' => 'Store data changed since the preview. Refresh and review the counts before confirming again.',
        'ineligible' => 'Cleanup is available for a demo store after connecting it to Zid. Check the connection and refresh.',
        'inconsistent' => 'Sales data needs an administrator review before cleanup. Nothing was deleted.',
        'failed' => 'Cleanup could not be completed and was rolled back. Please try again.',
    ],
    'zid_not_configured' => 'The Zid app credentials (ZID_CLIENT_ID and ZID_CLIENT_SECRET) are not set on the server.',
    'zid_connected' => 'Your store is connected to Zid. The first sync has started.',
    'zid_failed' => 'Could not complete the Zid connection: :error',
    'zid_state_mismatch' => 'The connection request expired, please try again.',
    'zid_disconnected' => 'Zid connection removed.',
    'sync_needs_connection' => 'Connect the store to Zid first to sync.',
    'sync_start_failed' => 'Could not start the sync. Check the import progress details.',
    'sync_resumed' => 'The import resumed from the last saved page.',
    'sync_resume_busy' => 'Another task is updating this catalog. Wait briefly, then try again.',
    'sync_resume_unavailable' => 'This import has no valid saved pages. Start a new sync.',
    'reclassified' => 'Product performance classification updated.',
    'extraction_started' => 'Extracting attributes for :count products.',
    'nothing_to_extract' => 'All products already have complete attributes.',
    'import_summary' => ':imported rows imported, :skipped skipped.',
    'import_failed' => 'No rows were imported. See the errors below.',
    'thresholds_order' => 'The accept threshold must be higher than the reject threshold.',
    'weights_required' => 'The weights must add up to more than zero.',
    'zid_check_ok' => 'The connection passed every check — everything works.',
    'zid_check_failed' => ':count connection checks failed. See the details and fixes below.',
];

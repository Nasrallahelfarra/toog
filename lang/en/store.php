<?php

// Messages for the store dashboard, performance and settings screens.
return [
    'zid_not_configured' => 'The Zid app credentials (ZID_CLIENT_ID and ZID_CLIENT_SECRET) are not set on the server.',
    'zid_connected' => 'Your store is connected to Zid. The first sync has started.',
    'zid_failed' => 'Could not complete the Zid connection: :error',
    'zid_state_mismatch' => 'The connection request expired, please try again.',
    'zid_disconnected' => 'Zid connection removed.',
    'sync_needs_connection' => 'Connect the store to Zid first to sync.',
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

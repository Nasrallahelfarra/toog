import type { stagnant as ar } from '../ar/stagnant';

export const stagnant: typeof ar = {
    title: 'Stagnant and at-risk products',
    subtitle:
        'Separate products with sustained inactivity from those needing early action, and request tailored advice.',
    dashboardHint: '{stagnant} stagnant · {atRisk} at risk',
    all: 'View all products',
    total: 'Stagnant products',
    atRisk: 'At risk of stagnation',
    allStatuses: 'All products needing attention',
    statusFilter: 'Filter by stagnation status',
    refreshNeeded:
        'These counts use the previous classification. Refresh classification to separate stagnant inventory from at-risk products using the new rules.',
    reasons: {
        no_sales_90:
            'Stock remains with no sales for at least 90 consecutive days covered by reliable data.',
        slow_turnover:
            'The product sells, but clearing its stock would take about 240 days or more at the recorded rate.',
        low_performance:
            'Sales performance is low relative to store products; an early warning, not confirmed stagnation.',
        early_no_sales:
            'Available records show no sales, but coverage does not establish 90 consecutive days of inactivity.',
    },
    stock: 'Units in stock',
    stockValue: 'Stock value at selling price',
    stockValueHint: 'An estimate at selling price, not inventory cost',
    units: 'Units sold',
    period: 'Latest classification, using up to 12 months of imported sales',
    turnover: 'Days to clear stock',
    price: 'Product price',
    classification:
        'Stagnant: stock with no sales for at least 90 consecutive days covered by sufficient data. At risk: stock with a score of 33 or below, or 240+ days to clear, without confirmed stagnation. Stock and value figures include both groups.',
    reclassify: 'Refresh classification',
    reclassifying: 'Refreshing classification',
    reclassifyHint:
        'Classification uses saved sales data and does not wait for image attribute extraction.',
    unclassified:
        '{count} products are unclassified. Refresh classification, then complete missing sales data if needed. Missing data does not mean a product is slow-moving.',
    importSales: 'Sync or import sales',
    search: 'Search by product name or SKU',
    searchButton: 'Search',
    categories: 'All categories',
    empty: 'No stagnant or at-risk products',
    emptyHint:
        'Refresh classification on this page. If the list stays empty, check that sales sync or import is complete.',
    noResults: 'No products match your search',
    reset: 'Clear filters',
    performance: 'View store performance',
    request: 'Request recommendation',
    view: 'View recommendation',
    pending: 'Preparing recommendation',
    retry: 'Request again',
    expert: 'Product expert recommendation',
    expertHint:
        'A review of this product using its image, performance and available evidence.',
    sending: 'Sending your request',
    sendingHint: 'Checking the request status before starting the review.',
    checkStatus: 'Check request status',
    refreshStatus: 'Refresh request status',
    phases: {
        queued: 'Your request is waiting to start',
        context: 'Reading product performance',
        image: 'Preparing the product image',
        model: 'The expert is reviewing the image and drafting recommendations',
        saving: 'Saving expert recommendations',
    },
    phaseHints: {
        queued: 'Your request is saved. AI analysis has not started yet.',
        context:
            'Gathering sales, stock and available evidence for this product.',
        image: 'Preparing the original image so suggestions refer to this exact product.',
        model: 'The request has been sent to Gemini. Response time varies with the image and requested detail.',
        saving: 'Checking and saving the analysis for you to review.',
    },
    elapsed: 'Processing for {minutes}m {seconds}s',
    queueElapsed: 'Waiting for {minutes}m {seconds}s',
    queueAhead: '{count} ready tasks are ahead in the processing queue.',
    queueWaiting: 'Waiting for processing capacity.',
    queueBusy: 'Other tasks are currently running.',
    queueSlow:
        'Analysis has not started yet; your request will start when processing is available. If this persists, the processing service needs checking.',
    network:
        'Could not refresh the status due to a connection issue. Processing may continue; refresh the status to check.',
    waiting:
        'You can close this window and return. The result is saved and appears automatically when ready.',
    saved: 'Saved recommendation',
    regenerate: 'Request new suggestions',
    regenerateHint:
        'Requests a new expert analysis and counts toward your AI usage.',
    visualOptions: 'Suggested directions for improving the image',
    chooseHint:
        'Compare each direction’s visual impact and benefit for the shopper, then choose one to apply. These are independent alternatives, not edits to combine.',
    visualImpact: 'Observation and visual impact',
    persuasion: 'How it may persuade the shopper',
    choose: 'Choose this suggestion',
    selected: 'Selected suggestion',
    chooseFirst: 'Choose a visual suggestion first',
    apply: 'Apply selected suggestion in studio',
    applyHint:
        'Opens the product image and editing instructions for you to review and generate an improved version.',
    businessStep: 'Suggested next step',
    noActions:
        'The expert suggested no additional actions with the available data.',
    failed: 'Recommendation incomplete',
    fromRecovery:
        'Improve “{name}” using its product performance recommendation. The original image and editing instructions are ready.',
    errors: {
        not_configured:
            'The specialist review requires a Gemini connection in platform settings.',
        budget_exceeded:
            'The store has reached its AI budget. Try again after updating the budget.',
        review_failed:
            'The expert review could not be completed. Please try again.',
        queue_failed: 'The request could not be queued. Please try again.',
        queue_unavailable:
            'The processing service is not configured for recommendations. Background processing needs to be enabled.',
        job_lost:
            'The request was interrupted before analysis started. You can request the recommendation again.',
        timed_out:
            'The request timed out. Check that background jobs are running and try again.',
        request_failed:
            'Could not confirm receipt. Refresh the status first; the request may have been saved and started.',
    },
};

import type { stagnant as ar } from '../ar/stagnant';

export const stagnant: typeof ar = {
    title: 'Slow-moving products',
    subtitle:
        'Find slow movers and request a tailored recommendation to help each product recover.',
    dashboardHint: '{count} products need attention · Highest stock first',
    all: 'View all products',
    total: 'Slow-moving products',
    stock: 'Units in stock',
    stockValue: 'Stock value at selling price',
    stockValueHint: 'An estimate at selling price, not inventory cost',
    units: 'Units sold',
    period: 'Latest classification, using up to 12 months of imported sales',
    turnover: 'Days to clear stock',
    price: 'Product price',
    classification:
        'Based on store performance: a low score (33 or below) or slow stock turnover (240+ days). Includes stocked products with no sales for at least 90 days after launch covered by a completed sync.',
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
    empty: 'No products classified as slow movers',
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
    loading: 'The expert is reviewing your product',
    loadingHint:
        'Reviewing sales, inventory and the image to suggest practical next steps.',
    waiting:
        'You can close this window and return. The result is saved and appears automatically when ready.',
    steps: [
        'Reading product performance',
        'Reviewing design and context',
        'Preparing recommendations',
    ],
    saved: 'Saved recommendation',
    apply: 'Apply recommendation in studio',
    applyHint:
        'Opens the product image and editing instructions for you to review and generate an improved version.',
    businessStep: 'Suggested next step',
    noActions:
        'The expert suggested no additional actions with the available data.',
    failed: 'Recommendation incomplete',
    fromRecovery:
        'Improve “{name}” using its slow-mover recommendation. The original image and editing instructions are ready.',
    errors: {
        not_configured:
            'The specialist review requires a Gemini connection in platform settings.',
        budget_exceeded:
            'The store has reached its AI budget. Try again after updating the budget.',
        review_failed:
            'The expert review could not be completed. Please try again.',
        queue_failed: 'The request could not be queued. Please try again.',
        timed_out:
            'The request timed out. Check that background jobs are running and try again.',
        request_failed:
            'The request could not be sent. Refresh the page and try again.',
    },
};

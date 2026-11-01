import type { productReview as ar } from '../ar/product-review';

export const productReview: typeof ar = {
    title: 'Product & design review',
    subtitle:
        'Recommendations for your product and audience, with edits you select and inspect.',
    audience: 'Target audience',
    audienceHint:
        'For example: women aged 25–40 in Saudi Arabia looking for practical, mid-priced abayas.',
    unavailable:
        'A specialist review is not available for this evaluation yet.',
    unavailableHint:
        'Request a new review. Actual visual findings require an available Gemini connection and readable image; the calculated indicator alone is not a design review.',
    rerun: 'Request specialist review',
    notConfigured: 'Configure Gemini before applying improvements.',
    confidence: 'Review confidence',
    levels: { low: 'Limited', medium: 'Medium', high: 'High' },
    verdicts: {
        suitable: 'Suitable on available evidence',
        improve: 'Improvements recommended',
        insufficient: 'Insufficient information',
        unsuitable: 'Unsuitable in its current form',
    },
    dimensions: {
        design: 'Design quality',
        market: 'Market fit',
        audience: 'Audience fit',
    },
    assessments: {
        strong: 'Strength',
        needs_work: 'Needs attention',
        not_assessed: 'Not assessed',
    },
    missing: 'Information that would improve the review',
    evidenceLimit:
        'These recommendations and hypotheses need testing. They do not guarantee sales or measure actual customer response. Coverage is limited to available data.',
    recommendations: 'Choose your improvements',
    selectionHint:
        'Only selected visual improvements are applied to a new version. Your original is kept.',
    visual: 'Image improvement',
    business: 'Merchant action',
    priorities: {
        high: 'High priority',
        medium: 'Medium priority',
        low: 'Low priority',
    },
    why: 'Reason',
    effect: 'Expected effect',
    apply: 'Apply selected improvements',
    selected: 'selected improvements',
    none: 'The review suggests no further changes.',
    sources: 'Review evidence',
    sourceKinds: {
        store: 'Store data',
        trend: 'Observed trend',
        competitor: 'Competitor product',
    },
    results: 'Improved versions',
    stages: {
        queued: 'Waiting to edit',
        generating: 'Applying improvements',
        review_queued: 'Waiting for visual inspection',
        reviewing: 'Comparing images and reviewing the result',
        completed: 'Version review complete',
        failed: 'Process incomplete',
    },
    waiting:
        'You can leave and return; progress is saved and updates automatically. If waiting persists, check that background workers are running.',
    before: 'Original design',
    after: 'Improved version',
    noImage: 'Image not available yet',
    failedImage: 'Unable to load image',
    passed: 'Selected improvements verified',
    attention: 'Please review the findings',
    unverified:
        'Visual verification is incomplete. Edits are not considered successful until inspected.',
    checks: {
        applied: 'Applied',
        partial: 'Partially applied',
        not_applied: 'Not applied',
        unclear: 'Unclear',
    },
    preserved: 'Product identity and unselected details were preserved.',
    notPreserved:
        'Other details changed or could not be verified as preserved.',
    retryReview: 'Retry inspection only',
    retryGenerate: 'Retry image edit',
    errors: {
        generation_failed:
            'Unable to create the version. Check the source image, Gemini connection and store budget.',
        review_failed:
            'The image is saved, but inspection is incomplete. Retry without generating another image.',
        queue_failed: 'Unable to enqueue the task. Try again.',
        timed_out: 'The task timed out. Check background workers and retry.',
    },
    acknowledge:
        'I reviewed the findings and accept this version despite them.',
    approve: 'Approve version',
    approved: 'Version approved',
    fullReview: 'View new evaluation',
    more: 'Request further improvements',
    download: 'Download version',
    score: 'Calculated fit indicator',
    scoreHint:
        'Based on observed attributes and available data; not a success probability or sales percentage.',
};

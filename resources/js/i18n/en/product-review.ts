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
        'Only selected design changes are applied to a new version. Your original is kept.',
    visual: 'Design change',
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
    previous: 'Previous version',
    after: 'Improved version',
    noImage: 'Image not available yet',
    failedImage: 'Unable to load image',
    imageLoading: {
        submitting: 'Sending your edit request…',
        queued: 'Your request is ready, waiting to generate',
        generating: 'Generating your new image…',
        hint: 'Your image will appear here automatically when ready. The previous version stays saved.',
        fetching: 'Loading image…',
        reviewing: 'Image ready. Checking the edits and reviewing the result.',
    },
    custom: {
        button: 'Regenerate with a custom edit',
        title: 'Make this version your own',
        description:
            'Describe what you want changed in the generated image below. This version will be the starting point.',
        source: 'Image to edit',
        label: 'What would you like to change?',
        placeholder:
            'For example: change the sleeves to beige and keep the rest of the design unchanged.',
        hint: 'The result is saved and reviewed as a new version. Previous versions remain available.',
        submit: 'Generate edited version',
        cancel: 'Cancel',
        requested: 'Your requested edit',
    },
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

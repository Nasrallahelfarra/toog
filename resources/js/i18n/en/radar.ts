import type { radar as ar } from '../ar/radar';

export const radar: typeof ar = {
    title: 'Competitor radar',
    description:
        'What engages your competitors’ audience right now, and which of their designs are worth adding.',
    tabs: { radar: 'Radar', products: 'Their products', accounts: 'Accounts' },
    windows: { 7: '7 days', 30: '30 days', 90: '90 days' },
    marketLink: 'Market trends',
    lastScan: 'Last scan {time}',
    demo: 'Demo data: these posts come from sample data, not a real scan.',
    howWeRead: 'How we read this',
    howWeReadBody:
        'Each post is compared only with its own account’s usual (the median of its posts), so big and small accounts or platforms are never compared. Engagement is interest, not sales, and we can’t tell whether a post was paid. “Best seller” is the competitor store’s own claim.',

    hero: {
        eyebrow: 'The standout idea right now',
        idea: 'at {n} competitors',
        ideaPlatforms: 'engaging on two platforms',
        posts: '{n} posts',
        products: '{n} products in their stores',
        usual: '{x}× their usual',
        postUsual: '{x}× its account’s usual',
        seePosts: 'See the posts',
        empty: 'Nothing stands out yet',
        emptyBody:
            'When a post beats its account’s usual or an idea repeats at more than one competitor, it shows here.',
    },

    designLike: 'Design like this',
    openPost: 'Open post',
    openProduct: 'Open product',
    competitor: 'Competitor',
    whyItWorked: 'Why did it work?',
    whatProduct: 'What product?',

    posts: {
        title: 'Most engaging right now',
        hint: 'Against each account’s usual, favouring the most recent',
        lift: '{x}× its usual',
        usualShort: 'its usual',
        liftTip: 'Against the median of {n} posts from the same account',
        tiers: {
            exceptional: 'Exceptional',
            strong: 'Strong',
            above: 'Above usual',
        },
        early: 'Early',
        inStore: 'The same product in their store',
        earlyTip: 'Read before it was 3 days old; engagement may still grow',
        smallSample: 'Small sample',
        empty: 'No posts above their usual in {d}',
        widen: 'Show the last {d} days',
    },

    tags: {
        formats: {
            video: 'Video',
            carousel: 'Carousel',
            image: 'Image',
            text: 'Text',
        },
        hooks: {
            offer: 'Offer or discount',
            new_arrival: 'New arrival',
            limited: 'Limited',
            restock: 'Back in stock',
            sold_out: 'Sold out',
            styling: 'Styling',
            try_on: 'Try-on',
            price_shown: 'Price shown',
            details: 'Details & fabric',
            review: 'Customer review',
            collab: 'Collaboration',
            giveaway: 'Giveaway',
            call_to_order: 'Call to order',
        },
        occasions: {
            graduation: 'Graduation',
            summer: 'Summer',
            winter: 'Winter',
        },
        flags: {
            giveaway: 'Giveaway',
            collab: 'Collab',
            comments_only: 'Comments only',
        },
        presentations: {
            on_model: 'On model',
            flat_lay: 'Flat lay',
            mannequin: 'Mannequin',
            hanger: 'On hanger',
            video_try_on: 'Try-on video',
            detail_shot: 'Detail shot',
            lifestyle: 'Lifestyle',
            no_product: 'No product',
        },
    },

    drivers: {
        title: 'What lifts engagement',
        hint: 'Within each account: posts with the element against posts without it',
        lifts: 'Lifts',
        weakens: 'Weakens',
        axis: 'Usual 1×',
        meta: '{p} posts · higher at {a} of {n}',
        metaWeak: '{p} posts · lower at {a} of {n}',
        conflicting: 'Mixed',
        likelyFormat: 'Likely the video effect',
        dimensions: {
            all: 'All',
            product: 'Product',
            hook: 'Angle',
            format: 'Format',
        },
        empty: 'Not enough posts yet to tell what lifts engagement (at least 5 posts per element, from two competitors).',
    },

    ideas: {
        title: 'Ideas working at more than one competitor',
        meta: '{b} competitors · {p} posts · {q} products',
        avg: 'avg {x}×',
        crossPlatform: 'One competitor on two platforms',
        notInCatalog: 'Not in your catalog',
        empty: 'No idea has repeated at two competitors yet.',
    },

    recs: {
        title: 'Best competitor designs you should add',
        hint: 'Designs with a demand signal and a second, independent one, against your catalog and prices',
        all: 'See all',
        verbs: {
            add_now: 'Add now',
            price_fit: 'Offer it within your band',
            test_small: 'Test a small run',
            restock: 'Restock it',
            watch: 'Watch',
        },
        novelty: {
            none: 'You have nothing like it',
            close: 'You have something close',
            unknown: 'Your product attributes are incomplete',
            similar: 'You have a similar one',
        },
        closest: 'Your closest product',
        differs: {
            colors: 'differs in colour',
            pattern: 'differs in pattern',
            style: 'differs in style',
        },
        brands: 'at {n} competitors',
        socialLift: '{x}× in its post',
        newGarment: 'New category for you',
        price: {
            within: 'Within your price band',
            above: '{p}% above your band',
            below: '{p}% below your band',
        },
        band: 'Your band {min}–{max}',
        watch: 'Watch',
        watching: 'Watching',
        empty: 'No designs with enough signals yet; scan your competitors’ stores and accounts.',
    },

    signals: {
        best_seller_label: 'Their best seller',
        published_sales: 'Published sales',
        homepage_featured: 'Featured on their storefront',
        price_drop: 'Price cut',
        new_arrival: 'New',
        engagement: 'Above-usual engagement',
    },
    claim: 'The store’s own claim',

    reading: {
        title: 'Toog’s reading',
        idle: 'Toog reads the images of the standout posts alongside weak posts for contrast, to tell you what product each shows and why it engaged.',
        read: 'Read the images',
        refresh: 'Refresh the reading',
        readNew: 'Read the new posts ({n})',
        refreshChanged: 'Refresh the analysis: engagement changed',
        upToDate: 'Reading is up to date',
        upToDateHint:
            'No new posts and no notable change in engagement — no cost.',
        costNote:
            'Each post’s image is read once; later refreshes are text-only and cheaper.',
        reading: 'Reading and comparing the post images…',
        stale: 'The radar is newer than this reading',
        noImages:
            'No images saved for these posts; the reading uses captions only. Rescan the accounts to save images.',
        failed: 'The reading failed for now. The computed results above are still correct.',
        footer: 'Inferences from public images and text, not evidence of sales.',
        single: 'One account only',
        kinds: {
            product: 'Product',
            content: 'Content',
            offer: 'Offer',
            timing: 'Timing',
        },
        designTakeaway: 'In design',
        contentTakeaway: 'In content',
        confidence: {
            high: 'High confidence',
            medium: 'Medium confidence',
            low: 'Low confidence',
        },
        demo: 'The AI reading is off in demo mode.',
    },

    alerts: {
        title: 'Alerts',
        empty: 'No alerts right now.',
        open: 'Open',
        all: 'All alerts ({n})',
    },
    advice: {
        title: 'Toog’s advice',
        basis: 'Based on {p} posts and {q} designs tracked',
    },

    confidence: {
        high: 'High confidence',
        medium: 'Medium confidence',
        low: 'Early signal',
        tip: 'Based on {p} posts from {c} competitors',
        lowBanner:
            'Early results: only {p} posts. Add competitors or widen the window.',
    },

    coverage: {
        accounts: '{n} social accounts',
        stores: '{n} stores',
        posts: '{n} posts in the window',
        images: '{n} saved images',
        thin: 'Need more data: {names}',
        footnote:
            'Engagement measures audience interest, not sales; we can’t tell whether a post was paid.',
    },

    empty: {
        noCompetitors: 'Start with one competitor',
        noCompetitorsBody:
            'Add a store or an Instagram or TikTok account; the radar shows what engages its audience after the first scan.',
        noSocial: 'The radar only sees their stores',
        noSocialBody:
            'Add social accounts to learn which posts and ideas engage.',
        addSocial: 'Add a social account',
    },

    activity: {
        title: 'Activity in their stores',
        hint: 'Price cuts and new products without a demand signal — activity, not demand',
        cut: 'We saw a cut from {from} to {to}',
    },

    analysis: {
        title: 'Their products',
        hint: 'From the products we read in the last 45 days — possibly not all of them',
        prices: 'Price position',
        pricesHint: 'Cheapest to priciest; the navy column is your price band',
        mine: 'Your store',
        mix: 'Their range vs yours',
        missing: 'They have it, you don’t',
        colors: 'Their top colours',
        colorsMissing: 'Colours they have and you don’t',
        pace: 'New arrivals pace',
        paceHint: 'New products per week over the last 4 weeks',
        paceUnknown: 'Needs scans covering at least two weeks',
        cuts: 'Price cuts',
        cutsValue: '{n} cuts · median {p}%',
        signalShare: '{p}% of their products carry a signal',
        products: '{n} products read',
        classified: '{p}% classified',
        noPrices: 'Not enough prices, or in another currency',
        open: 'Competitor details',
        empty: 'Add competitor stores to see their prices and range against yours.',
    },
};

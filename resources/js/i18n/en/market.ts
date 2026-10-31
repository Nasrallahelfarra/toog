import type { market as ar } from '../ar/market';

export const market: typeof ar = {
    title: 'Market trends',
    description:
        'What is trending with your competitors and on social media in Saudi Arabia — and what your catalog is missing.',
    refresh: 'Refresh analysis',
    lastAnalysis: 'Last analysed {time}',
    neverAnalyzed: 'Not analysed yet',

    stats: {
        rising: 'Rising trends',
        gaps: 'Gap opportunities',
        competitors: 'Active competitors',
        engagement: 'Avg. engagement',
        tracked: 'products tracked',
    },

    filters: {
        type: 'Type',
        platform: 'Platform',
        status: 'Status',
        allTypes: 'All types',
        allPlatforms: 'All platforms',
        allStatuses: 'All statuses',
    },

    types: {
        color: 'Colour',
        pattern: 'Pattern',
        style: 'Style',
        shape: 'Shape',
        material: 'Material',
        theme: 'Season & occasion',
    },

    status: {
        rising: 'Rising',
        stable: 'Stable',
        declining: 'Declining',
    },

    platforms: {
        website: 'Store website (auto detect)',
        salla: 'Salla',
        zid: 'Zid',
        shopify: 'Shopify',
        instagram: 'Instagram',
        tiktok: 'TikTok',
        snapchat: 'Snapchat',
    },

    trends: 'Trends',
    trendsHint:
        'Momentum measures how fast an idea is spreading; saturation, how common it already is among competitors.',
    momentum: 'Momentum',
    growth: 'Growth',
    saturation: 'Saturation',
    inCatalog: 'In your catalog',
    notInCatalog: 'Missing from your catalog',
    noTrends: 'No matching trends',
    noTrendsHint:
        'Change the filters, or refresh the analysis after scanning competitors.',

    map: 'Opportunity map',
    mapHint: 'High momentum with low saturation means a better opportunity.',
    opportunityZone: 'Opportunity zone',

    gaps: 'Gap opportunities',
    gapsHint:
        'Design elements rising in the market that your catalog does not carry yet.',
    noGaps: 'Your catalog covers every rising trend right now',
    evaluateIdea: 'Evaluate an idea',
    designIdea: 'Design an idea',

    activity: 'Competitor activity',
    activityHint:
        'New products, price drops and best sellers at your competitors.',
    noActivity: 'No competitor activity in the last 30 days',
    kinds: {
        new: 'New product',
        price_drop: 'Price drop',
        best_seller: 'Best seller',
    },

    posts: 'Top-performing content',
    postsHint: 'Competitors’ best posts on TikTok, Instagram and Snapchat.',
    noPosts: 'No posts tracked',
    views: 'views',
    likes: 'likes',
    shares: 'shares',
    comments: 'comments',
    engagement: 'Engagement',

    reviews: 'Customer reviews',
    reviewsHint:
        'Arabic (including Saudi dialect) and English reviews, analysed.',
    analyze: 'Analyse with AI',
    pendingReviews: '{count} reviews waiting for analysis',
    allAnalyzed: 'All reviews analysed',
    sentiment: {
        positive: 'Positive',
        neutral: 'Neutral',
        negative: 'Negative',
    },
    likesTopics: 'What customers love',
    dislikesTopics: 'What puts them off',
    recentReviews: 'Latest reviews',
    noReviews: 'No reviews yet',
    topics: {
        fabric: 'Fabric',
        fit: 'Fit',
        sizing: 'Sizing',
        color_accuracy: 'Colour accuracy',
        quality: 'Quality',
        price: 'Price',
        delivery: 'Delivery',
        packaging: 'Packaging',
        design: 'Design',
        service: 'Customer service',
    },
    sources: {
        store: 'Your store',
        competitor: 'Competitor',
    },
    publicDataNote:
        'Monitoring uses publicly available data only, within each platform’s terms and Saudi regulations.',

    competitors: {
        title: 'Competitors',
        description:
            'The stores and social accounts طوق follows to learn which design elements work.',
        add: 'Add competitor',
        edit: 'Edit competitor',
        scanAll: 'Scan all',
        scanNow: 'Scan now',
        name: 'Name',
        platform: 'Platform',
        url: 'Store or profile URL',
        handle: 'Account handle',
        category: 'Category',
        notes: 'Notes',
        active: 'Monitoring on',
        paused: 'Paused',
        products: 'products',
        posts: 'posts',
        lastChecked: 'Last checked',
        neverChecked: 'Not checked yet',
        lastError: 'Last error',
        autoScan: 'Auto monitoring',
        manualOnly: 'Needs a data provider',
        needsApi: 'Needs API access',
        storeKind: 'Store',
        socialKind: 'Social',
        deleteTitle: 'Delete competitor?',
        deleteBody:
            'All tracked products and posts of this competitor will be deleted.',
        empty: 'No competitors yet',
        emptyHint:
            'Add competitor stores and social accounts so طوق can start tracking trends.',
        details: 'Competitor details',
        trackedProducts: 'Tracked products',
        trackedPosts: 'Tracked posts',
        bestSeller: 'Best seller',
        analysis: 'Report',
        analysisEmpty:
            'Choose “Scan now” to collect products and generate a report for this competitor.',
        nameHint: 'Optional; defaults to the website domain',
        sellerClaim: 'Best seller according to the store',
        featured: 'Featured on the storefront',
        priceRange: 'Sample price range',
        evidenceNote:
            'Best-seller labels are merchant claims. Homepage placement shows on-site promotion, not advertising frequency or sales volume.',
        salesPublished:
            'Published sales figures were found; original labels and source links appear beside the products.',
        salesUnavailable:
            'No published sales figures were found on the pages read. This does not mean zero sales.',
        sampleNote:
            'This report covers a limited sample of the catalog and sections read, not all store sales or campaigns.',
        partialCrawl:
            'Some pages could not be read. Check the sources for this scan’s coverage.',
        noEvidence:
            'No evidence for this classification was found in the current sample.',
        geminiAnalysis: 'Gemini analysis',
        hypotheses:
            'These recommendations are hypotheses to test, not proof of why the competitor succeeds.',
        aiNotConfigured:
            'Storefront data is ready. Configure a Gemini key on the server and scan again to generate analysis.',
        aiUnavailable:
            'Data collection succeeded, but Gemini did not return analysis. Check credits, the API key and store budget, then scan again.',
        sources: 'Scan sources',
        firstSeen: 'Seen',
        lastScan: 'Last full scan: {time}',
        scanStats: '{added} new · {updated} updated · {changes} price changes',
        coverage: 'Monitoring coverage',
        coverageHint:
            'We collect published data from store URLs: Shopify catalogs or structured product data on public pages. Coverage depends on the site; social networks need a separate connection.',
        totals: {
            total: 'competitors',
            active: 'active',
            products: 'products tracked',
            posts: 'posts tracked',
        },
    },
};

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
        facebook: 'Facebook',
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
    postsHint: 'Competitors’ best posts on Instagram, TikTok and Facebook.',
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
        workspace: {
            back: 'All competitors',
            eyebrow: 'Competitor analysis',
            visit: 'Visit website',
            overview: 'Overview',
            products: 'Products',
            sources: 'Sources',
            posts: 'Posts',
            refresh: 'Refresh analysis',
            ready: 'Scan data ready',
            sampleProducts: 'Products in sample',
            merchantLabel: 'According to the store',
            storefrontLabel: 'On-site promotion',
            featured: 'Featured products',
            priceHint: 'Lowest and highest observed prices',
            aiTitle: 'Toog’s competitor insights',
            aiSubtitle: 'Ideas and recommendations based on observed products',
            aiWaiting: 'AI analysis is not available yet',
            aiWaitingHint:
                'You can explore the available products, prices and sources now.',
            signals: 'Products worth watching',
            signalsHint:
                'Explore the store’s best-seller labels and storefront features.',
            viewAll: 'View products',
            browse: 'Explore products',
            categories: 'Category breakdown',
            categoriesHint: 'Based on the products in this scan’s sample',
            search: 'Search by product name or category…',
            all: 'All products',
            best: 'Store-labelled best sellers',
            promoted: 'Featured on storefront',
            sort: 'Sort products',
            recommended: 'Store best sellers first',
            priceLow: 'Price: low to high',
            priceHigh: 'Price: high to low',
            newest: 'Recently observed',
            results: '{count} matching products',
            showing: 'Showing {from}–{to} of {total}',
            previous: 'Previous',
            next: 'Next',
            noMatches: 'No matching products',
            noMatchesHint: 'Try another name or view all products.',
            reset: 'Clear filters',
            openProduct: 'Open product',
            unknownPrice: 'Price unavailable',
            allLoaded: 'Showing {shown} of {total} sampled products.',
            coverageTitle: 'What did we read from the website?',
            coverageDescription:
                'Visit the original pages to verify information and classifications.',
            read: 'Read successfully',
            notRead: 'Could not read',
            scopeTitle: 'How to read these results',
            recordedAt: 'Data collected',
            emptyTitle: 'Start exploring this competitor',
            pendingTitle: 'Preparing your competitor report',
            noProducts: 'No products observed yet',
            noPosts: 'No posts observed yet',
            noPostsHint:
                'Choose “Scan now” to read this account’s latest public posts.',
            postEngagement: 'engagement',
            scanDetails: 'Scan stage details',
            visitAccount: 'Open account',
            postImage: 'Post image',
            noPostImage: 'No image for this post',
            noCaption: 'Post without text',
            likesHidden: 'Likes hidden',
            engagementUnknown:
                'No follower or view count to measure engagement against',
            basisFollowers: 'Engagement relative to followers',
            basisViews: 'Engagement relative to views',
            sortPosts: 'Sort posts',
            latestPosts: 'Latest',
            topPosts: 'Highest engagement',
            topPostsHint:
                'The posts with the highest engagement in this scan; look at the designs and formats they show.',
            postsRead: 'Posts read',
            since: 'Since {date}',
            avgEngagement: 'Average engagement',
            perWeek: 'Posts per week',
            perWeekHint: 'Posting rate over the period read',
            followers: 'Followers',
            followersHint: 'Follower count at scan time',
            totalViews: 'Views',
            totalViewsHint: 'Total views of the posts read',
            socialAiSubtitle:
                'Which designs and content the competitor’s audience engages with',
            socialAiWaitingHint:
                'You can browse the posts and their engagement now.',
            browsePosts: 'Browse posts',
            hashtags: 'Most used hashtags',
            formats: 'Content formats',
            postTypes: {
                image: 'Image',
                video: 'Video',
                carousel: 'Carousel',
                text: 'Text',
            },
        },
        scanProgress: {
            title: 'Competitor scan progress',
            starting: 'Submitting scan request…',
            queued: 'Waiting to start',
            running: 'Scanning',
            completed: 'Scan completed',
            failed: 'Scan could not finish',
            follow: 'Follow progress',
            retry: 'Try again',
            phaseNote:
                'Percentage reflects completed stages, not remaining time.',
            pages: '{count} pages read',
            products: '{count} products found',
            posts: '{count} posts read',
            elapsed: '{seconds} seconds since request',
            waitingLong:
                'Processing has not started yet. Server workers may be busy or stopped. You can leave and return to check progress.',
            reportPending:
                'The report will appear here automatically when data is available.',
            noReport:
                'This scan did not produce a new report. Review the reason above and try again.',
            previousReport:
                'The report below is from an earlier scan; the new scan has not completed.',
            requestFailed:
                'Could not confirm the scan request. Refresh the page to check its status before trying again.',
            updateFailed:
                'Could not refresh progress. We will try again; the scan may still be running on the server.',
            aiProcessing:
                'Storefront data is ready; waiting for Gemini analysis.',
            socialAiProcessing: 'Posts are ready; waiting for Gemini analysis.',
            stages: {
                rules: 'Checking website access',
                storefront: 'Reading the storefront',
                products: 'Collecting products and sources',
                saving: 'Saving products and prices',
                analysis: 'Preparing the report and analysis',
            },
            socialStages: {
                rules: 'Preparing the Apify request',
                storefront: 'Reading the account',
                products: 'Fetching public posts',
                saving: 'Saving posts, images and engagement',
                analysis: 'Analysing content and designs',
            },
            errors: {
                needs_apify:
                    'Reading social accounts needs an Apify token. Add APIFY_TOKEN to the server settings.',
                apify_unauthorized:
                    'Apify rejected the token. Check APIFY_TOKEN in the server settings.',
                apify_payment:
                    'The Apify account is out of credit. Add credit, then scan again.',
                apify_timeout:
                    'Apify did not finish reading the posts in time. Try again shortly.',
                apify_unavailable:
                    'Apify could not be reached or the reader could not run. Review the details below and try again.',
                no_posts:
                    'No public posts were found. Check the handle and that the account is public, not private.',
                fetch_failed:
                    'Could not read the store data. Check its URL and review the details below.',
                unsupported:
                    'This source needs a separate connection before it can be scanned automatically.',
                inactive: 'The store or this competitor monitor is inactive.',
                queue_failed:
                    'Background processing could not start. The server administrator needs to check job configuration.',
                queue_timeout:
                    'Processing did not start in time. Check the server worker, then try again.',
                timed_out:
                    'The scan exceeded its time limit. Saved data remains available; you can try again.',
                job_failed:
                    'Processing stopped before the scan finished. You can try again.',
            },
        },
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
        needsApi: 'Not supported yet',
        needsApify: 'Needs Apify',
        apifyMissing:
            'Instagram, TikTok and Facebook posts are read through Apify. Add APIFY_TOKEN to the server settings to start scanning this account.',
        handleHint:
            'Enter the account name as it appears in its link (e.g. abaya.store), or paste the profile link. The account must be public.',
        socialHypotheses:
            'These are conclusions from public posts worth testing in your own designs and content, not evidence of the competitor’s sales.',
        socialAiNotConfigured:
            'Posts are ready. Configure a Gemini key on the server and scan again to generate analysis.',
        socialEvidenceNote:
            'Public posts are read through Apify. Engagement is likes, comments and shares relative to followers or views: a sign of interest, not sales.',
        socialSampleNote:
            'This report covers the latest {count} posts in the period read, not the account’s full history.',
        socialWarnings: {
            followers_unavailable:
                'The follower count could not be read; engagement uses views where available.',
        },
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
            'Websites: we collect published data from store URLs (Shopify catalogs or structured product data on public pages). Instagram, TikTok and Facebook: public posts and their engagement are read through Apify.',
        totals: {
            total: 'competitors',
            active: 'active',
            products: 'products tracked',
            posts: 'posts tracked',
        },
    },
};

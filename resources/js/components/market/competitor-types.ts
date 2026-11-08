import type {
    CompetitorAnalysisData,
    ProductEvidence,
} from './competitor-analysis';
import type { CompetitorScanData } from './competitor-scan-progress';

export type Competitor = {
    id: number;
    name: string;
    platform: string;
    url: string | null;
    profile_url: string | null;
    handle: string | null;
    category: string | null;
    notes: string | null;
    is_active: boolean;
    is_social: boolean;
    auto_scan: boolean;
    scan_blocker: 'needs_apify' | 'needs_api_access' | 'needs_provider' | null;
    products_count: number;
    posts_count: number;
    last_checked_at: string | null;
    last_error: string | null;
    scan: CompetitorScanData | null;
};

export type CompetitorProduct = {
    id: number;
    name: string;
    category: string | null;
    price: number | null;
    previous_price: number | null;
    image_url: string | null;
    url: string | null;
    is_best_seller: boolean;
    evidence: ProductEvidence | null;
    colors: string[];
    pattern: string | null;
    first_seen_at: string | null;
};

export type SocialPost = {
    id: number;
    platform: string;
    caption: string | null;
    url: string | null;
    thumbnail_url: string | null;
    views: number;
    likes: number;
    comments: number;
    shares: number;
    /** Null when there were neither followers nor views to measure against. */
    engagement_rate: number | null;
    engagement_basis: 'followers' | 'views' | null;
    type: 'image' | 'video' | 'carousel' | 'text' | null;
    likes_hidden: boolean;
    posted_at: string | null;
};

/** Report for a social account, built from the posts Apify read. */
export type SocialAnalysisData = {
    kind: 'social';
    crawl: {
        observed_at: string;
        profile_url: string;
        since: string;
        limited: boolean;
        sources: { url: string; actor: string; items: number }[];
        warnings: { url: string; code: string; message: string }[];
    };
    facts: {
        posts: number;
        views: number;
        likes: number;
        comments: number;
        shares: number;
        followers: number | null;
        avg_engagement: number | null;
        engagement_basis: ('followers' | 'views')[];
        measured_posts: number;
        per_week: number | null;
        first_post_at: string | null;
        last_post_at: string | null;
        formats: Record<string, number>;
        hashtags: Record<string, number>;
        top_post_ids: number[];
    };
    ai: {
        status: string;
        summary: string | null;
        insights: { title: string; description: string; post_ids: number[] }[];
    };
};

export const isSocialAnalysis = (
    analysis: CompetitorAnalysisData | SocialAnalysisData | null,
): analysis is SocialAnalysisData =>
    (analysis as SocialAnalysisData | null)?.kind === 'social';

export type CompetitorDetails = {
    id: number;
    analysis: CompetitorAnalysisData | SocialAnalysisData | null;
    products: CompetitorProduct[];
    posts: SocialPost[];
};

export type ProductFilter = 'all' | 'best' | 'featured';

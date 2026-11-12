import type { GarmentKey, Prefill } from '@/components/studio/designer/types';

export type Days = 7 | 30 | 90;

export type PostTags = {
    format: 'video' | 'carousel' | 'image' | 'text';
    hooks: string[];
    garment: GarmentKey | null;
    colors: string[];
    pattern: string | null;
    style: string | null;
    occasion: string | null;
    flags: ('giveaway' | 'collab' | 'comments_only')[];
};

export type RadarPost = {
    id: number;
    competitor: string;
    competitor_id: number;
    brand: string;
    /** The exact store product the post names by its code, when found. */
    product: {
        id: number;
        name: string;
        price: number | null;
        currency: string | null;
        image_url: string | null;
        url: string | null;
        best_seller: boolean;
        published_sales: string | null;
        competitor_id: number;
    } | null;
    platform: string;
    url: string | null;
    image_url: string | null;
    caption: string;
    posted_at: string;
    age_days: number;
    likes: number;
    comments: number;
    shares: number;
    views: number;
    lift: number | null;
    baseline: number;
    metric: 'rate' | 'interactions' | 'comments' | null;
    small_sample: boolean;
    tier: 'exceptional' | 'strong' | 'above' | null;
    early: boolean;
    stale: boolean;
    score: number;
    tags: PostTags;
    look: string | null;
    prefill: Prefill;
};

export type Driver = {
    dimension: 'format' | 'hook' | 'garment' | 'color' | 'pattern' | 'occasion';
    key: string;
    effect: number;
    posts: number;
    brands: number;
    accounts: number;
    agree: number;
    conflicting: boolean;
    likely_format: boolean;
};

export type Idea = {
    look: string;
    label: string;
    garment: GarmentKey;
    brands: number;
    platforms: number;
    cross_platform_only: boolean;
    posts: number;
    products: number;
    avg_lift: number | null;
    images: string[];
    in_catalog: boolean | null;
    post_ids: number[];
    inspire: string | null;
    prefill: Prefill;
};

export type Recommendation = {
    kind: 'product' | 'post';
    id: number;
    name: string;
    competitor: string;
    competitor_id: number;
    platform: string | null;
    image_url: string | null;
    url: string | null;
    price: number | null;
    previous_price: number | null;
    currency: string | null;
    signals: string[];
    published_sales: string | null;
    drop_pct: number | null;
    watched: boolean;
    colors: string[];
    pattern: string | null;
    look: string;
    garment: GarmentKey;
    score: number;
    brands: number;
    social_lift: number | null;
    novelty: 'none' | 'close' | 'unknown' | 'similar';
    similar: {
        id: number;
        name: string;
        image_url: string | null;
        price: number | null;
        differs: 'colors' | 'pattern' | 'style' | null;
    } | null;
    new_garment: boolean;
    band: { min: number; max: number } | null;
    position: {
        status: 'within' | 'above' | 'below' | null;
        pct: number | null;
    };
    verb: 'add_now' | 'price_fit' | 'test_small' | 'restock' | 'watch';
    text: string;
    prefill: Prefill;
};

export type Activity = {
    id: number;
    name: string;
    competitor: string;
    image_url: string | null;
    url: string | null;
    price: number | null;
    previous_price: number | null;
    currency: string | null;
    signals: string[];
    drop_pct: number | null;
    watched: boolean;
    first_seen_at: string | null;
};

type Band = {
    p25: number;
    median: number;
    p75: number;
    min: number;
    max: number;
} | null;

export type Analysis = {
    competitors: {
        id: number;
        name: string;
        platform: string;
        products: number;
        mix: Record<string, number>;
        classified_pct: number | null;
        missing: string[];
        colors: string[];
        price: Band;
        new_30d: number | null;
        new_weeks: number[] | null;
        cuts: number;
        cut_median: number | null;
        signal_share: number | null;
        last_checked_at: string | null;
    }[];
    mine: {
        products: number;
        mix: Record<string, number>;
        classified_pct: number | null;
        colors: string[];
        bands: Record<string, { min: number; max: number }>;
    };
    market: {
        products: number;
        mix: Record<string, number>;
        classified_pct: number | null;
        colors: string[];
        missing: string[];
        colors_missing: string[];
    };
};

export type RadarAlert = {
    type: string;
    severity: 'danger' | 'warning' | 'info';
    text: string;
    focus: string | null;
    href: string | null;
    image_url: string | null;
    prefill: Prefill | null;
};

export type Advice = {
    action: 'add' | 'design' | 'content' | 'watch';
    title: string;
    body: string;
    focus: string | null;
    prefill: Prefill | null;
    image_url: string | null;
    href?: string;
};

export type Coverage = {
    days: Days;
    social_accounts: number;
    store_competitors: number;
    posts: number;
    eligible: number;
    brands: number;
    confidence: 'high' | 'medium' | 'low';
    thin_accounts: { id: number; name: string; posts: number }[];
    hidden_likes: boolean;
    with_images: number;
    demo: boolean;
    apify: boolean;
    last_social_scan: string | null;
    last_store_scan: string | null;
    wider: Days | null;
};

export type RadarData = {
    days: Days;
    hero:
        | { kind: 'idea'; idea: Idea }
        | { kind: 'post'; post: RadarPost }
        | { kind: 'recommendation'; recommendation: Recommendation }
        | null;
    posts: RadarPost[];
    drivers: Driver[];
    ideas: Idea[];
    recommendations: Recommendation[];
    activity: Activity[];
    analysis: Analysis;
    alerts: RadarAlert[];
    advice: Advice[];
    coverage: Coverage;
};

export type PostReading = {
    /** The post's lift when it was read; the analysis refreshes when it moves. */
    lift_at_read: number | null;
    read_at: string | null;
    product: string;
    why: string;
    design_takeaway: string;
    content_takeaway: string;
    product_visible: boolean;
    garment: GarmentKey | null;
    colors: string[];
    pattern: string | null;
    silhouette: string | null;
    presentation: string | null;
    confidence: 'high' | 'medium' | 'low';
};

export type Reading = {
    summary: string | null;
    insights: {
        title: string;
        body: string;
        kind: 'product' | 'content' | 'offer' | 'timing';
        post_ids: number[];
        single: boolean;
    }[];
    posts: Record<string, PostReading>;
    hit_ids: number[];
    images: number;
    available: boolean;
    from_model: boolean;
    created_at: string | null;
} | null;

/** "Design like this" opens the studio's AI designer with this as the inspiration. */
export function designHref(inspire: string): string {
    return `/store/studio?mode=designer&inspire=${encodeURIComponent(inspire)}`;
}

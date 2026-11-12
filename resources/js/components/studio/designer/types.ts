import type { Recommendation } from '@/components/evaluation/types';

type Label = { ar: string; en: string };

export type GarmentKey =
    | 'abaya'
    | 'dress_daily'
    | 'dress_evening'
    | 'jalabiya'
    | 'kaftan'
    | 'kimono'
    | 'coord_set'
    | 'prayer_set';
export type RenderKey = 'ghost' | 'on_model' | 'sketch' | 'flat';
export type RoleKey =
    | 'safe_bet'
    | 'trend_twist'
    | 'competitor_gap'
    | 'signature';
export type SourceKey =
    | 'own_sales'
    | 'competitors'
    | 'trend'
    | 'season'
    | 'creative';

export type FashionVocabulary = {
    garments: Record<GarmentKey, Label>;
    fabrics: Record<
        string,
        Label & {
            sheer: boolean;
            weight: string;
            drape_ar: string;
            drape_en: string;
            seasons: string[];
        }
    >;
    occasions: Record<string, Label & { season: string }>;
    silhouettes: Record<GarmentKey, Record<string, Label>>;
    details: Record<
        | 'neckline'
        | 'sleeve'
        | 'length'
        | 'closure'
        | 'embellishment'
        | 'placement',
        Record<string, Label>
    >;
    renders: Record<RenderKey, Label & { ratio: string }>;
    roles: Record<RoleKey, Label>;
};

export type Inspiration = {
    kind: 'bestseller' | 'rising' | 'competitor' | 'social' | 'gap' | 'season';
    id: string | number;
    label: string;
    image_url: string | null;
};

/** What an insight hands to the brief when the merchant picks "design this". */
export type Prefill = {
    garment?: GarmentKey | null;
    fabric?: string | null;
    colors?: string[];
    occasion?: string | null;
    pattern?: string | null;
    style?: string | null;
    notes?: string;
    inspiration?: Inspiration;
};

type ProductCard = {
    id: number;
    name: string;
    category: string | null;
    image_url: string | null;
    colors: string[];
    pattern: string | null;
    garment: GarmentKey | null;
    spark: number[];
    prefill: Prefill;
};

export type Insights = {
    window: { from: string; to: string };
    bestsellers: (ProductCard & {
        units: number;
        orders: number;
        return_pct: number;
        growth_pct: number | null;
        stock: number;
        months_of_stock: number | null;
    })[];
    dna: {
        attribute: string;
        value: string;
        label: string;
        index: number;
        products: number;
        units: number;
        hex: string | null;
    }[];
    rising: (ProductCard & {
        units: number;
        units_before: number;
        change_pct: number;
        stock: number;
    })[];
    fading: (ProductCard & {
        units: number;
        units_before: number;
        change_pct: number;
        stock: number;
    })[];
    competitor_hits: {
        id: number;
        name: string;
        competitor: string;
        image_url: string | null;
        url: string | null;
        category: string | null;
        price: number | null;
        currency: string | null;
        drop_pct: number | null;
        signals: (
            | 'best_seller_label'
            | 'published_sales'
            | 'homepage_featured'
            | 'price_drop'
            | 'new_arrival'
        )[];
        published_sales: string | null;
        echo: boolean;
        colors: string[];
        pattern: string | null;
        similar_to: { id: number; name: string } | null;
        prefill: Prefill;
    }[];
    social: {
        id: number;
        competitor: string;
        platform: string;
        url: string | null;
        thumbnail_url: string | null;
        caption: string;
        times_median: number;
        likes: number;
        comments: number;
        views: number;
        colors: string[];
        prefill: Prefill;
    }[];
    gaps: {
        attribute: string;
        value: string;
        label: string;
        source: 'competitors' | 'trend';
        market_pct: number | null;
        mine_pct: number;
        items: number | null;
        competitors: number | null;
        trend: { id: number; momentum: number; saturation: number } | null;
        hex: string | null;
        prefill: Prefill;
    }[];
    saturation: {
        kind: 'catalog' | 'market';
        category: string | null;
        share: number;
        products: number | null;
        look: string;
    }[];
    seasons: {
        id: number;
        key: string | null;
        name: string;
        starts_on: string;
        prep_starts_on: string;
        days_until: number;
        ready_products: number;
        status: 'ready' | 'upcoming' | 'at_risk' | 'late';
        palette: string[];
        prefill: Prefill;
    }[];
    coverage: {
        products: number;
        with_sales: number;
        features_pct: number | null;
        competitors: number;
        competitor_products: number;
        competitor_features_pct: number | null;
        trends: number;
    };
    alerts: {
        type: string;
        severity: 'danger' | 'warning' | 'info';
        text: string;
        prefill: Prefill | null;
        href: string | null;
        image_url: string | null;
    }[];
};

export type Briefing = {
    items: {
        title_ar: string;
        body_ar: string;
        title_en: string;
        body_en: string;
        refs: string[];
        action: string;
        prefill: Prefill | null;
        href: string | null;
    }[];
    from_model: boolean;
    created_at: string | null;
} | null;

export type Evidence = {
    id: string;
    kind: string;
    claim_ar: string;
    claim_en: string;
    href: string | null;
    image_url: string | null;
    caveat:
        | 'merchant_claim'
        | 'engagement_not_sales'
        | 'placement_not_ads'
        | null;
};

export type Concept = {
    index: number;
    role: RoleKey;
    source: SourceKey;
    name_ar: string;
    name_en: string;
    pitch_ar: string;
    pitch_en: string;
    silhouette: string;
    neckline: string;
    sleeve: string;
    length: string;
    closure: string;
    embellishments: string[];
    placement: string;
    colors: string[];
    pattern: string;
    style: string;
    fabric: string | null;
    lining: 'none' | 'partial' | 'full';
    rationale_ar: string;
    rationale_en: string;
    risk_ar: string;
    risk_en: string;
    finishing_ar: string;
    finishing_en: string;
    evidence: Evidence[];
    category: string;
    season: string;
    production: {
        meters_min: number;
        meters_max: number;
        complexity: 1 | 2 | 3;
    };
    price: { min: number; max: number; mid: number } | null;
    status: 'pending' | 'drawing' | 'ready' | 'failed';
    error: string | null;
    render?: RenderKey;
    design_id?: number;
    image_url: string | null;
    score?: number;
    recommendation?: Recommendation;
};

export type Brief = {
    garment: GarmentKey;
    fabric: string | null;
    colors: string[];
    occasion: string;
    age: 'young' | 'adult' | 'mature';
    modesty: 'strict' | 'standard';
    tier: 'economy' | 'mid' | 'premium' | 'luxury' | null;
    creativity: number;
    count: number;
    render: RenderKey;
    notes: string | null;
    inspiration: (Inspiration & { evidence_id: string }) | null;
    fabric_read?: {
        colors: string[];
        pattern: string | null;
        sheen: string | null;
        drape: string | null;
        fabric_guess: string | null;
        confidence: number | null;
    } | null;
};

export type Collection = {
    id: number;
    status: 'pending' | 'processing' | 'completed' | 'failed';
    error: string | null;
    brief: Brief;
    fabric_image_url: string | null;
    title_ar: string | null;
    title_en: string | null;
    story_ar: string | null;
    story_en: string | null;
    from_model: boolean;
    created_at: string;
    concepts: Concept[];
};

export type DesignerProps = {
    vocabulary: FashionVocabulary;
    briefing: Briefing;
    brand_colors: string[];
};

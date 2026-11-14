import type { GarmentKey } from '@/components/studio/designer/types';

export type AttrType = 'garment' | 'color' | 'pattern' | 'material' | 'style';

export type MarketExample = {
    id: number;
    name: string;
    image_url: string | null;
    url: string | null;
    competitor: string;
    published_sales: number | null;
    best_seller: boolean;
    price: number | null;
    currency: string | null;
    garment: GarmentKey | null;
};

export type MineExample = {
    id: number;
    name: string;
    image_url: string | null;
    units: number;
    garment: GarmentKey | null;
};

/** The value at the active competitors: catalog share, brands, posts. */
export type MarketSide = {
    pct: number | null;
    items: number;
    brands: number;
    demand: number;
    new: number;
    posts: number;
    post_lift: number | null;
    crowded: boolean;
    examples: MarketExample[];
};

/** The value in the store's own Zid sales: units now and before. */
export type MineSide = {
    units: number;
    units_before: number;
    unit_pct: number;
    change_pct: number | null;
    catalog_pct: number;
    products: number;
    in_stock: number;
    stock: number;
    examples: MineExample[];
};

export type TrendItem = {
    key: string;
    type: AttrType;
    value: string;
    label: string;
    hex: string | null;
    verdict: 'opportunity' | 'focus' | 'mine_rising' | 'cooling' | null;
    strong: boolean;
    heat: number;
    market: MarketSide | null;
    mine: MineSide | null;
    design_href: string;
    evaluate_href: string | null;
};

export type Board = {
    basis: 'live' | 'sample' | 'none';
    fashion: boolean;
    window: {
        from: string;
        to: string;
        before_from: string;
        before_to: string;
    };
    coverage: {
        brands: number;
        products: number;
        demand: number;
        excluded: number;
        posts: number;
        hot_posts: number;
        scanned_at: string | null;
        own_products: number;
        units: number;
    };
    opportunities: TrendItem[];
    focus: TrendItem[];
    mine_rising: TrendItem[];
    cooling: TrendItem[];
    pulse: TrendItem[];
    top_post: {
        id: number;
        competitor: string;
        platform: string;
        lift: number;
        image_url: string | null;
        url: string | null;
        garment: GarmentKey | null;
    } | null;
};

export type { CountForms } from '@/hooks/use-count';

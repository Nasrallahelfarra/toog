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
    handle: string | null;
    category: string | null;
    notes: string | null;
    is_active: boolean;
    is_social: boolean;
    auto_scan: boolean;
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

export type CompetitorDetails = {
    id: number;
    analysis: CompetitorAnalysisData | null;
    products: CompetitorProduct[];
    posts: {
        id: number;
        caption: string | null;
        url: string | null;
        views: number;
        likes: number;
        engagement_rate: number;
        posted_at: string | null;
    }[];
};

export type ProductFilter = 'all' | 'best' | 'featured';

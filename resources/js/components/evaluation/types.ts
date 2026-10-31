export type Recommendation = 'accept' | 'reject' | 'modify';
export type FinalDecision = 'approved' | 'rejected' | 'needs_changes';
export type EvaluationStatus = 'pending' | 'processing' | 'completed' | 'failed';
export type CriterionKey = 'success_similarity' | 'trend_fit' | 'saudi_fit' | 'failure_risk' | 'saturation' | 'price_fit';

export const CRITERIA: CriterionKey[] = ['success_similarity', 'trend_fit', 'saudi_fit', 'failure_risk', 'saturation', 'price_fit'];

export type Thresholds = { accept: number; reject: number };

export type Modification = {
    criterion: CriterionKey;
    type: string;
    change: Record<string, unknown>;
    params: Record<string, string | number | null>;
    expected_gain: number;
};

export type Factor = {
    criterion: CriterionKey;
    impact: 'positive' | 'negative';
    label_ar?: string;
    label_en?: string;
    detail_ar?: string;
    detail_en?: string;
};

export type CulturalFlag = { severity: 'low' | 'medium' | 'high'; issue_ar: string; issue_en: string };

export type Evidence = {
    similar_winners?: { name: string; similarity: number }[];
    similar_stagnant?: { name: string; similarity: number }[];
    matched_trends?: { name_ar: string; name_en: string; type: string; status: string; momentum: number; saturation: number }[];
    price_band?: { low: number; median: number; high: number };
    competitor_overlap?: { similar: number; total: number };
    self_repetition?: number;
    saudi_palette_matches?: string[];
    launch?: { event_ar: string; event_en: string; event_starts_on: string; date: string };
    season_mismatch?: boolean;
};

export type Breakdown = {
    criteria: Record<CriterionKey, { score: number; weight: number }>;
    evidence: Evidence;
    advice?: { ar: string; en: string }[];
    ai_written?: boolean;
    thresholds?: Thresholds;
};

export type EvaluationSummary = {
    id: number;
    name: string;
    category: string | null;
    price: string | null;
    colors: string[];
    image_url: string | null;
    status: EvaluationStatus;
    score: number | null;
    recommendation: Recommendation | null;
    final_decision: FinalDecision | null;
    override: boolean;
    version: number;
    source: 'manual' | 'batch' | 'design';
    expected_score: number | null;
    user: string | null;
    created_at: string;
};

export type EvaluationDetail = {
    id: number;
    name: string;
    category: string | null;
    price: string | null;
    material: string | null;
    colors: string[];
    pattern: string | null;
    style: string | null;
    season: string | null;
    description: string | null;
    image_url: string | null;
    status: EvaluationStatus;
    score: number | null;
    breakdown: Breakdown | null;
    recommendation: Recommendation | null;
    explanation_ar: string | null;
    explanation_en: string | null;
    top_factors: Factor[] | null;
    modifications: Modification[] | null;
    expected_score: number | null;
    cultural_flags: CulturalFlag[] | null;
    alternatives: { change: Record<string, unknown>; score: number }[] | null;
    suggested_launch: string | null;
    is_novel: boolean;
    final_decision: FinalDecision | null;
    override_reason: string | null;
    decided_at: string | null;
    version: number;
    parent_id: number | null;
    root_id: number | null;
    source: 'manual' | 'batch' | 'design';
    batch_id: number | null;
    design_id: number | null;
    error: string | null;
    created_at: string;
    user: string | null;
    decider: string | null;
    batch: { id: number; name: string } | null;
};

export type VersionRow = {
    id: number;
    version: number;
    score: number | null;
    recommendation: Recommendation | null;
    status: EvaluationStatus;
    final_decision: FinalDecision | null;
    created_at: string;
};

export type DesignItem = {
    id: number;
    title: string;
    image_url: string;
    output_type: string;
    aspect_ratio: string;
    palette: string[] | null;
    tags: string[] | null;
    status: 'draft' | 'in_review' | 'approved' | 'rejected';
    is_favorite: boolean;
    score: number | null;
    evaluation_id: number | null;
    design_request_id: number | null;
    provider: string | null;
    model: string | null;
    cost: string;
    prompt: string | null;
    created_at: string;
};

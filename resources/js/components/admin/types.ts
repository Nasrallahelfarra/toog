export type AdminStoreRow = {
    id: number;
    name: string;
    slug: string;
    domain: string | null;
    category: string | null;
    platform: string;
    status: 'active' | 'suspended';
    connection_status: string;
    owner: { id: number; name: string; email: string; phone: string | null; role: 'admin' | 'store_owner'; status: string } | null;
    products_count: number;
    evaluations_count: number;
    month_cost: number;
    ai_monthly_budget: number | null;
    budget_usage: number | null;
    show_revenue: boolean;
    last_synced_at: string | null;
    created_at: string | null;
};

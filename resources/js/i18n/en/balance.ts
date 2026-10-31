import type { balance as ar } from '../ar/balance';

export const balance: typeof ar = {
    title: 'Catalog balance',
    description: 'Keep the catalog fresh: variety in every category, a healthy mix of proven and new, and a deliberate launch pace.',
    changeTarget: 'Change the target ratio',

    diversity: 'Diversity index',
    diversityHint: 'How different the category’s products are from each other in colour, pattern, material, style, season and price.',
    ratio: 'Proven vs innovative',
    ratioHint: 'Of products launched or approved in the last 6 months.',
    proven: 'Proven',
    novel: 'Innovative',
    target: 'Target {proven}/{novel}',
    onTarget: 'On target',
    belowTarget: 'Below target',
    aboveTarget: 'Above target',
    recentLaunches: 'launches in 6 months',
    products: 'products in the catalog',
    noLaunches: 'No recent launches to compute the ratio',

    levels: {
        high: 'Great variety',
        medium: 'Acceptable variety',
        low: 'Highly repetitive',
    },

    alerts: 'Active alerts',
    noAlerts: 'The catalog is balanced — no alerts',
    alertText: {
        repetitive: '“{category}” is getting repetitive: diversity {diversity}/100, and the most repeated look covers {share}% of its products.',
        overloaded: '“{category}” is weighed down by slow movers ({stagnant} of {products}). Slow down launches there until turnover improves.',
        low_innovation: 'Only {novel}% of launches are innovative versus a {target}% target. Add new, trend-led ideas.',
        too_experimental: '{novel}% of launches are innovative, above the {target}% target. Risk is high — balance with proven products.',
    },
    alertAction: {
        repetitive: 'Suggest different designs',
        overloaded: 'Review category performance',
        low_innovation: 'Explore trends',
        too_experimental: 'Evaluate a proven product',
    },

    categories: 'Balance by category',
    categoriesHint: 'Suggested number of new products per month for each category, to avoid flooding or stagnation.',
    category: 'Category',
    topLook: 'Most repeated look',
    novelRatio: 'Innovative share',
    winners: 'Best sellers',
    stagnant: 'Slow movers',
    launches: 'Recent launches',
    recommended: 'Suggested / month',
    perMonth: '{count} per month',
    statusLabel: 'Status',
    status: {
        healthy: 'Healthy',
        repetitive: 'Repetitive',
        stale: 'No recent launches',
        overloaded: 'Too many slow movers',
    },

    pace: 'Suggested launch pace',
    paceHint: 'Suggested monthly launches compared with the actual monthly average over the last 6 months.',
    actualPace: 'Actual / month',
    suggestedPace: 'Suggested / month',
    empty: 'No classified products yet',
    emptyHint: 'Connect your store or import products to compute catalog balance.',
};

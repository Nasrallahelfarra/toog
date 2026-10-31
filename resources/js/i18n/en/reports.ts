import type { reports as ar } from '../ar/reports';

export const reports: typeof ar = {
    title: 'Reports',
    description: 'Ready-made reports you can export to Excel and PDF.',
    period: 'Period',
    presets: {
        '30': 'Last 30 days',
        '90': 'Last 90 days',
        '180': 'Last 6 months',
        '365': 'Last 12 months',
        custom: 'Custom range',
    },
    apply: 'Apply',
    excel: 'Excel',
    pdf: 'PDF',
    types: {
        performance: 'Store performance',
        market: 'Competitors & trends',
        evaluations: 'Evaluations & decisions',
        balance: 'Catalog balance',
    },
    descriptions: {
        performance: 'Product classification, monthly sales and performance by category.',
        market: 'Trends, competitor activity, their new products and customer sentiment.',
        evaluations: 'A complete log of every evaluation and decision, including overrides and their reasons.',
        balance: 'Diversity index, innovative share and launch pace per category.',
    },
    recent: 'Recent exports',
    noRecent: 'No reports exported yet',
    by: 'by {name}',
    print: {
        generated: 'Generated {date} by {name}',
        period: 'Period: {from} — {to}',
        summary: 'Summary',
        printNow: 'Print / save PDF',
        back: 'Back',
        rows: '{count} rows',
        empty: 'No data in this period',
        confidential: 'Confidential — store data belongs exclusively to the client',
    },
};

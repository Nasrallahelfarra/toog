export type ReportFormat = 'text' | 'number' | 'money' | 'percent' | 'date';

export type ReportMetric = { label_ar: string; label_en: string; value: number | null; format: ReportFormat };

export type ReportColumn = { key: string; ar: string; en: string; format: ReportFormat };

export type ReportSection = {
    key: string;
    title_ar: string;
    title_en: string;
    columns: ReportColumn[];
    rows: Record<string, string | number | null>[];
};

export type Report = {
    type: 'performance' | 'market' | 'evaluations' | 'balance';
    title_ar: string;
    title_en: string;
    summary: ReportMetric[];
    sections: ReportSection[];
};

<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Reports\ExcelExporter;
use App\Services\Reports\ReportBuilder;
use App\Support\Activity;
use App\Support\CurrentStore;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ready-made reports (FR-6.2), exported as Excel or as a printable page
 * the browser saves to PDF (keeps Arabic shaping correct).
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly CurrentStore $current,
        private readonly ReportBuilder $builder,
    ) {}

    public function index(Request $request): Response
    {
        $store = $this->current->require();
        [$from, $to] = $this->period($request);
        $locale = app()->getLocale();

        return Inertia::render('store/reports/index', [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'preset' => $request->query('preset', '90')],
            'reports' => collect(ReportBuilder::TYPES)->map(fn ($type) => $this->builder->build($type, $store, $from, $to, summaryOnly: true, locale: $locale)),
            'recentExports' => ActivityLog::query()
                ->with('user:id,name')
                ->where('store_id', $store->id)
                ->where('action', 'report.exported')
                ->latest('created_at')
                ->limit(6)
                ->get()
                ->map(fn (ActivityLog $log) => [
                    'id' => $log->id,
                    'report' => $log->properties['report'] ?? null,
                    'format' => $log->properties['format'] ?? null,
                    'user' => $log->user?->name,
                    'created_at' => $log->created_at?->toIso8601String(),
                ]),
        ]);
    }

    public function export(Request $request, ExcelExporter $excel, string $type): Response|StreamedResponse
    {
        $store = $this->current->require();
        [$from, $to] = $this->period($request);
        $format = $request->query('format') === 'pdf' ? 'pdf' : 'xlsx';
        $filters = $request->only(['recommendation', 'final_decision', 'category', 'q']);
        $locale = app()->getLocale();

        $report = $this->builder->build($type, $store, $from, $to, $filters, locale: $locale);

        Activity::log('report.exported', $store, ['report' => $type, 'format' => $format, 'from' => $from->toDateString(), 'to' => $to->toDateString()]);

        if ($format === 'xlsx') {
            return $excel->download($report, $store, $from, $to, $locale);
        }

        return Inertia::render('store/reports/print', [
            'report' => $report,
            'store' => ['name' => $store->name, 'domain' => $store->domain],
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'generatedAt' => now()->toIso8601String(),
            'generatedBy' => $request->user()->name,
        ]);
    }

    /**
     * Period from ?from&to, or a preset number of days (default 90).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(Request $request): array
    {
        $to = $request->date('to') ? CarbonImmutable::parse($request->date('to'))->endOfDay() : CarbonImmutable::now()->endOfDay();

        $from = $request->date('from')
            ? CarbonImmutable::parse($request->date('from'))->startOfDay()
            : $to->subDays(max(7, min(730, (int) $request->query('preset', 90))))->startOfDay();

        return $from->greaterThan($to) ? [$to->startOfDay(), $from->endOfDay()] : [$from, $to];
    }
}

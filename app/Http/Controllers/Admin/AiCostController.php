<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AdminData;
use App\Http\Controllers\Controller;
use App\Models\AiUsage;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiCostController extends Controller
{
    use AdminData;

    private const PERIODS = ['7', '30', '90', 'month'];

    public function index(Request $request): Response
    {
        [$period, $from] = $this->period($request);
        $base = AiUsage::query()->where('created_at', '>=', $from);
        $monthStart = now()->startOfMonth();

        $totals = (clone $base)->selectRaw(
            'COUNT(*) as calls, COALESCE(SUM(cost), 0) as cost, COALESCE(SUM(input_tokens), 0) as input_tokens, '
            .'COALESCE(SUM(output_tokens), 0) as output_tokens, COALESCE(SUM(images), 0) as images, '
            .'SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failures, COALESCE(AVG(latency_ms), 0) as latency'
        )->first();

        $evaluationCalls = (clone $base)->where('operation', 'evaluation')->where('success', true);
        $evaluationCount = (clone $evaluationCalls)->count();

        $monthSpend = AiUsage::query()->where('created_at', '>=', $monthStart)
            ->selectRaw('store_id, SUM(cost) as cost')->groupBy('store_id')->pluck('cost', 'store_id');

        $byStore = (clone $base)
            ->selectRaw('store_id, SUM(cost) as cost, COUNT(*) as calls, SUM(images) as images, SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failures')
            ->groupBy('store_id')
            ->orderByDesc('cost')
            ->get();

        $stores = Store::query()->whereIn('id', $byStore->pluck('store_id')->filter())->get(['id', 'name', 'status', 'ai_monthly_budget'])->keyBy('id');

        return Inertia::render('admin/ai-costs', [
            'period' => $period,
            'from' => $from->toDateString(),
            'totals' => [
                'cost' => round((float) $totals->cost, 4),
                'calls' => (int) $totals->calls,
                'input_tokens' => (int) $totals->input_tokens,
                'output_tokens' => (int) $totals->output_tokens,
                'images' => (int) $totals->images,
                'failures' => (int) $totals->failures,
                'failure_rate' => $totals->calls ? round($totals->failures / $totals->calls * 100, 1) : 0,
                'avg_latency_ms' => (int) round((float) $totals->latency),
                'avg_evaluation_cost' => $evaluationCount ? round((float) $evaluationCalls->sum('cost') / $evaluationCount, 5) : 0,
            ],
            'daily' => $this->dailyAiCost($from, byOperation: true),
            'operations' => AiUsage::OPERATIONS,
            'byStore' => $byStore->map(function ($row) use ($stores, $monthSpend) {
                $store = $row->store_id ? $stores->get($row->store_id) : null;
                $budget = $store?->ai_monthly_budget !== null ? (float) $store?->ai_monthly_budget : null;
                $month = (float) ($monthSpend[$row->store_id] ?? 0);

                return [
                    'store' => $store ? ['id' => $store->id, 'name' => $store->name, 'status' => $store->status] : null,
                    'cost' => round((float) $row->cost, 4),
                    'calls' => (int) $row->calls,
                    'images' => (int) $row->images,
                    'failures' => (int) $row->failures,
                    'month_cost' => round($month, 4),
                    'budget' => $budget,
                    'budget_usage' => $budget ? (int) round($month / $budget * 100) : null,
                ];
            }),
            'byModel' => (clone $base)
                ->selectRaw('provider, model, SUM(cost) as cost, COUNT(*) as calls, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens, SUM(images) as images')
                ->groupBy('provider', 'model')->orderByDesc('cost')->get(),
            'byOperation' => (clone $base)
                ->selectRaw('operation, SUM(cost) as cost, COUNT(*) as calls, AVG(latency_ms) as latency, SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failures')
                ->groupBy('operation')->orderByDesc('cost')->get(),
            'failures' => (clone $base)->where('success', false)->with('store:id,name')->latest('created_at')->limit(10)
                ->get(['id', 'store_id', 'provider', 'model', 'operation', 'error', 'latency_ms', 'created_at']),
        ]);
    }

    /**
     * CSV of every AI call in the period (UTF-8 with BOM so Excel shows Arabic).
     */
    public function export(Request $request): StreamedResponse
    {
        [$period, $from] = $this->period($request);

        return response()->streamDownload(function () use ($from) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, app()->getLocale() === 'ar'
                ? ['التاريخ', 'المتجر', 'المستخدم', 'المزوّد', 'النموذج', 'العملية', 'رموز الإدخال', 'رموز الإخراج', 'الصور', 'التكلفة (دولار)', 'نجح', 'الخطأ', 'زمن الاستجابة (مللي ثانية)']
                : ['Date', 'Store', 'User', 'Provider', 'Model', 'Operation', 'Input tokens', 'Output tokens', 'Images', 'Cost (USD)', 'Success', 'Error', 'Latency (ms)']);

            AiUsage::query()
                ->where('created_at', '>=', $from)
                ->with(['store:id,name', 'user:id,name'])
                ->orderBy('created_at')
                ->chunk(500, function ($rows) use ($out) {
                    foreach ($rows as $u) {
                        fputcsv($out, [
                            $u->created_at?->toDateTimeString(), $u->store?->name, $u->user?->name, $u->provider, $u->model,
                            $u->operation, $u->input_tokens, $u->output_tokens, $u->images, $u->cost, $u->success ? 1 : 0,
                            $u->error, $u->latency_ms,
                        ]);
                    }
                });

            fclose($out);
        }, "toog-ai-costs-{$period}-".now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{0: string, 1: CarbonImmutable}
     */
    private function period(Request $request): array
    {
        $period = in_array($request->query('period'), self::PERIODS, true) ? $request->query('period') : '30';

        $from = $period === 'month'
            ? now()->startOfMonth()
            : now()->subDays((int) $period - 1)->startOfDay();

        return [$period, $from];
    }
}

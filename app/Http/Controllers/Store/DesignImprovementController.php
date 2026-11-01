<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateImprovedDesign;
use App\Jobs\ReviewImprovedDesign;
use App\Models\DesignImprovement;
use App\Models\Evaluation;
use App\Services\Ai\Ai;
use App\Support\Activity;
use App\Support\CurrentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class DesignImprovementController extends Controller
{
    public function __construct(private readonly CurrentStore $current) {}

    public function store(Request $request, Evaluation $evaluation, Ai $ai): RedirectResponse
    {
        abort_unless($evaluation->store_id === $this->current->id(), 404);
        $data = $request->validate(['request_key' => 'required|uuid', 'review_version' => 'required|uuid', 'recommendation_ids' => 'required|array|min:1|max:6', 'recommendation_ids.*' => 'required|string|distinct|max:20']);
        $this->available($ai);
        $run = DB::transaction(function () use ($request, $evaluation, $data) {
            $evaluation = Evaluation::query()->lockForUpdate()->findOrFail($evaluation->id);
            $existing = DesignImprovement::query()->where('evaluation_id', $evaluation->id)
                ->where(fn ($q) => $q->where('request_key', $data['request_key'])->orWhereIn('status', DesignImprovement::ACTIVE))->first();
            if ($existing) {
                return $existing;
            }
            $review = $evaluation->specialist_review;
            if ($evaluation->status !== 'completed' || ($review['status'] ?? '') !== 'available' || ($review['version'] ?? '') !== $data['review_version']) {
                $this->invalid('المراجعة تغيّرت أو غير متاحة. حدّث الصفحة وأعد الاختيار.', 'The review changed or is unavailable. Refresh and select again.');
            }
            $selected = collect($review['recommendations'])->where('kind', 'visual')->whereIn('id', $data['recommendation_ids'])->values()->all();
            if (count($selected) !== count($data['recommendation_ids']) || ! $evaluation->image_path || ! Storage::disk('public')->exists($evaluation->image_path)) {
                $this->invalid('اختر تحسينات بصرية من هذه المراجعة وتأكد من توفر الصورة الأصلية.', 'Choose visual recommendations from this review and supply the original image.');
            }

            return DesignImprovement::query()->create([
                'store_id' => $evaluation->store_id, 'evaluation_id' => $evaluation->id, 'user_id' => $request->user()->id,
                'request_key' => $data['request_key'], 'status' => 'queued', 'recommendations' => $selected,
                'source_image_path' => $evaluation->image_path,
                'source_snapshot' => $evaluation->only(['name', 'category', 'price', 'material', 'season', 'description', 'target_audience']),
            ]);
        });
        if ($run->wasRecentlyCreated) {
            $this->dispatch($run);
            Activity::log('design.improvement_requested', $run, ['evaluation_id' => $evaluation->id, 'recommendations' => $data['recommendation_ids']]);
        }

        return to_route('store.evaluations.show', $evaluation);
    }

    public function retry(DesignImprovement $improvement, Ai $ai): RedirectResponse
    {
        abort_unless($improvement->store_id === $this->current->id(), 404);
        $this->available($ai);
        $status = $improvement->design_id ? 'review_queued' : 'queued';
        $changed = DB::transaction(function () use ($improvement, $status) {
            Evaluation::query()->whereKey($improvement->evaluation_id)->lockForUpdate()->firstOrFail();
            if (DesignImprovement::query()->where('evaluation_id', $improvement->evaluation_id)->whereKeyNot($improvement->id)->whereIn('status', DesignImprovement::ACTIVE)->exists()) {
                $this->invalid('انتظر اكتمال التحسين الجاري أولًا.', 'Wait for the active improvement to finish first.');
            }

            return DesignImprovement::query()->whereKey($improvement->id)->where('status', 'failed')->update(['status' => $status, 'error' => null, 'updated_at' => now()]);
        });
        if ($changed) {
            $improvement->refresh();
            $this->dispatch($improvement);
        }

        return to_route('store.evaluations.show', $improvement->evaluation_id);
    }

    public function approve(Request $request, DesignImprovement $improvement): RedirectResponse
    {
        abort_unless($improvement->store_id === $this->current->id(), 404);
        $request->validate(['acknowledge_issues' => 'sometimes|boolean']);
        if ($improvement->status !== 'completed' || ! $improvement->result_evaluation_id || ! $improvement->design) {
            $this->invalid('انتظر اكتمال مراجعة النسخة.', 'Wait for the version review to finish.');
        }
        if (($improvement->verification['status'] ?? '') !== 'passed' && ! $request->boolean('acknowledge_issues')) {
            $this->invalid('راجع الملاحظات وأكّد قبول النسخة رغم وجودها.', 'Review and acknowledge the outstanding issues before approval.');
        }
        DB::transaction(function () use ($improvement) {
            $improvement->update(['approved_at' => now()]);
            $improvement->design->update(['status' => 'approved']);
            // Approval here chooses the visual version. The product launch decision
            // remains an explicit action in the evaluation's decision panel.
            Activity::log('design.improvement_approved', $improvement);
        });

        return back();
    }

    private function available(Ai $ai): void
    {
        if (! $ai->isLive()) {
            $this->invalid('يلزم تفعيل جمني لتطبيق التحسينات وفحص الصورة.', 'Configure Gemini to apply and verify image edits.');
        }
        if ($this->current->require()->aiBudgetExceeded()) {
            $this->invalid('تم الوصول إلى ميزانية الذكاء الاصطناعي للمتجر.', 'The store AI budget has been reached.');
        }
        $driver = config('queue.connections.'.config('queue.default').'.driver');
        if (in_array($driver, ['sync', 'null', 'deferred'], true)) {
            $this->invalid('يلزم تشغيل مهام الخلفية لتطبيق التحسينات.', 'A background queue is required for image improvements.');
        }
    }

    private function dispatch(DesignImprovement $improvement): void
    {
        try {
            $improvement->design_id ? ReviewImprovedDesign::dispatch($improvement->id) : GenerateImprovedDesign::dispatch($improvement->id);
        } catch (Throwable $e) {
            report($e);
            $improvement->update(['status' => 'failed', 'error' => 'queue_failed']);
        }
    }

    private function invalid(string $ar, string $en): never
    {
        throw ValidationException::withMessages(['improvement' => app()->getLocale() === 'ar' ? $ar : $en]);
    }
}

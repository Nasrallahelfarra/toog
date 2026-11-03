<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Jobs\RecommendStagnantProduct;
use App\Models\Product;
use App\Models\ProductRecommendation;
use App\Services\Ai\Ai;
use App\Services\Catalog\StagnantProducts;
use App\Support\CurrentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class StagnantProductController extends Controller
{
    public function __construct(private readonly CurrentStore $current) {}

    public function index(Request $request, StagnantProducts $catalog): Response
    {
        $store = $this->current->require();
        $filters = $request->validate(['search' => 'nullable|string|max:150', 'category' => 'nullable|string|max:80']);
        $products = $catalog->query($store)->with('recovery')
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")))
            ->when($filters['category'] ?? null, fn ($q, $category) => $q->where('category', $category))
            ->orderByDesc('stock')->orderBy('performance_score')->orderBy('id')->paginate(12)->withQueryString()
            ->through(fn (Product $p) => $catalog->product($p));

        return Inertia::render('store/stagnant', [
            'products' => $products, 'summary' => fn () => $catalog->summary($store), 'filters' => $filters,
            'categories' => fn () => $catalog->query($store)->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
        ]);
    }

    public function recommend(Request $request, Product $product, Ai $ai): RedirectResponse
    {
        $store = $this->current->require();
        abort_unless($product->store_id === $store->id, 404);
        abort_unless($product->performance === Product::STAGNANT, 422);

        $run = DB::transaction(function () use ($product, $request) {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            $latest = $locked->recovery;
            if ($latest && ! $latest->expired() && $latest->status !== 'failed') {
                return null;
            }
            if ($latest?->expired()) {
                $latest->update(['status' => 'failed', 'error' => 'timed_out']);
            }

            return ProductRecommendation::query()->create([
                'store_id' => $product->store_id, 'product_id' => $product->id, 'user_id' => $request->user()->id,
                'status' => 'pending', 'snapshot' => $locked->only(['name', 'category', 'price', 'image_url', 'features', 'stock', 'units_sold', 'revenue', 'views', 'add_to_cart_rate', 'return_rate', 'turnover_days', 'performance_score']),
            ]);
        });
        if ($run) {
            if (! $ai->isLive() || $store->aiBudgetExceeded()) {
                $run->update(['status' => 'failed', 'error' => $ai->isLive() ? 'budget_exceeded' : 'not_configured']);

                return back();
            }
            try {
                RecommendStagnantProduct::dispatch($run->id);
            } catch (Throwable $e) {
                report($e);
                $run->update(['status' => 'failed', 'error' => 'queue_failed']);
            }
        }

        return back();
    }
}

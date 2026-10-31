<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\Imports\CatalogImporter;
use App\Support\Activity;
use App\Support\CurrentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Products and sales file import — the alternative to the Zid connection.
 */
class ImportController extends Controller
{
    public function __construct(private readonly CurrentStore $current) {}

    public function store(Request $request, CatalogImporter $importer, string $type): RedirectResponse
    {
        $store = $this->current->require();

        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,xlsx,xls'],
        ]);

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());

        $report = $type === 'sales'
            ? $importer->sales($store, $file->getRealPath(), $extension)
            : $importer->products($store, $file->getRealPath(), $extension);

        Activity::log('data.imported', $store, [
            'file' => $type,
            'name' => $file->getClientOriginalName(),
            'imported' => $report['imported'],
            'skipped' => $report['skipped'],
        ]);

        Inertia::flash('toast', $report['imported'] > 0
            ? ['type' => 'success', 'message' => __('store.import_summary', ['imported' => $report['imported'], 'skipped' => $report['skipped']])]
            : ['type' => 'error', 'message' => __('store.import_failed')]);

        return back()->with('importReport', $report);
    }

    public function template(CatalogImporter $importer, string $type): Response
    {
        return response($importer->template($type), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"mojo-{$type}-template.csv\"",
        ]);
    }
}

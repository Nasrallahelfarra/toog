<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\DesignRequestImage;
use App\Support\CurrentStore;
use App\Support\UploadedPicture;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The merchant's sketches and fabric photos for a look, uploaded one by
 * one as they are added (a phone on a weak network retries one photo,
 * not all of them) and attached to the request when it is sent.
 */
class StudioUploadController extends Controller
{
    public function __construct(private readonly CurrentStore $current) {}

    public function store(Request $request): JsonResponse
    {
        $store = $this->current->require();

        $data = $request->validate([
            // 15 MB before the browser shrinks it; HEIC is turned into JPEG on the phone.
            'file' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:15360'],
            'role' => ['required', 'string', Rule::in(array_keys(DesignRequestImage::ROLES))],
        ], [
            'file.mimetypes' => __('mojo.look_upload_format'),
        ]);

        $picture = UploadedPicture::normalize((string) file_get_contents($request->file('file')->getRealPath()));
        if ($picture === null) {
            throw ValidationException::withMessages(['file' => __('mojo.look_upload_format')]);
        }

        $path = "design-sources/{$store->id}/look/".Str::ulid().'.jpg';
        Storage::disk('public')->put($path, $picture['bytes']);

        $image = DesignRequestImage::query()->create([
            'store_id' => $store->id,
            'user_id' => $request->user()->id,
            'role' => $data['role'],
            'path' => $path,
            'width' => $picture['width'],
            'height' => $picture['height'],
            'original_name' => Str::limit((string) $request->file('file')->getClientOriginalName(), 150, ''),
        ]);

        return response()->json($image->present());
    }

    /**
     * Remove a picture the merchant took out before sending. One already
     * sent with a request belongs to that request and stays.
     */
    public function destroy(Request $request, int $image): Response
    {
        $picture = DesignRequestImage::query()
            ->where('store_id', $this->current->id())
            ->where('user_id', $request->user()->id)
            ->whereNull('design_request_id')
            ->findOrFail($image);

        Storage::disk('public')->delete($picture->path);
        $picture->delete();

        return response()->noContent();
    }
}

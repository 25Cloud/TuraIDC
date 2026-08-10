<?php

namespace App\Http\Controllers;

use App\Http\Requests\Site\ShowSecureAssetRequest;
use App\Support\SecureAsset;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class SecureAssetController extends Controller
{
    public function show(ShowSecureAssetRequest $request)
    {
        $data = $request->validated();

        try {
            $path = SecureAsset::normalizePath((string) $data['path']);
            $absolutePath = SecureAsset::absolutePath($path);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        abort_unless(File::exists($absolutePath), 404);
        abort_unless(str_starts_with((string) (File::mimeType($absolutePath) ?: ''), 'image/'), 404);

        return response()->file($absolutePath, [
            'Cache-Control' => 'private, max-age=300, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="'.basename($absolutePath).'"',
        ]);
    }
}

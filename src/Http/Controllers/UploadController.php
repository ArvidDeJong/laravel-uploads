<?php

namespace Darvis\Uploads\Http\Controllers;

use Darvis\Uploads\Models\Upload;
use Darvis\Uploads\Support\UploadsConfig;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serve a private upload, one of its conversions or a rendered page through a signed link.
 */
class UploadController extends Controller
{
    public function __invoke(Request $request, Upload $upload, UploadsConfig $config): RedirectResponse|StreamedResponse
    {
        Gate::authorize('view', $upload);

        $path = $this->pathFor($request, $upload);
        $disk = Storage::disk($upload->disk);

        // A disk such as S3 hands out its own short lived link, so the file does not pass through PHP.
        // A local disk is streamed here instead, because its temporary URLs need Laravel's own serve route.
        if (! $this->isLocal($disk) && $disk->providesTemporaryUrls()) {
            return redirect()->away($disk->temporaryUrl($path, now()->addMinutes($config->temporaryUrlMinutes())));
        }

        $name = $path === $upload->path ? $upload->name : basename($path);

        // Shown in the browser by default; `?download=1` forces a download with the original name.
        return $request->boolean('download') ? $disk->download($path, $name) : $disk->response($path, $name);
    }

    /**
     * Asked of the Flysystem adapter, because Illuminate's own LocalFilesystemAdapter only
     * exists since Laravel 11.30, and a local disk can have a temporary URL callback too.
     */
    protected function isLocal(FilesystemAdapter $disk): bool
    {
        return $disk->getAdapter() instanceof LocalFilesystemAdapter;
    }

    /**
     * The original by default, a conversion with `?conversion=thumb`, a page with `?page=2`.
     */
    protected function pathFor(Request $request, Upload $upload): string
    {
        $conversion = $request->query('conversion');

        if (is_string($conversion) && $conversion !== '') {
            return $upload->conversionPath($conversion) ?? abort(404);
        }

        $page = $request->query('page');

        if (is_numeric($page)) {
            return $upload->pagePaths()[(int) $page - 1] ?? abort(404);
        }

        return $upload->path;
    }
}

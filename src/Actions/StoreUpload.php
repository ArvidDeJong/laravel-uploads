<?php

namespace Darvis\Uploads\Actions;

use Darvis\Uploads\Jobs\ProcessUpload;
use Darvis\Uploads\Models\Upload;
use Darvis\Uploads\Support\UploadsConfig;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Store a file on the right disk, record it as an Upload and queue the processing.
 */
class StoreUpload
{
    public function __construct(
        protected UploadsConfig $config,
    ) {}

    /**
     * @param  array<string, mixed>  $meta  Extra data kept with the upload, such as a caption.
     */
    public function handle(
        UploadedFile $file,
        string $collection = 'files',
        bool $public = false,
        array $meta = [],
        ?Model $owner = null,
        ?int $userId = null,
    ): Upload {
        $disk = $this->config->diskFor($public);
        $directory = $this->directoryFor($owner, $collection);
        $filename = (string) Str::uuid().$this->extensionOf($file);

        // storeAs streams the file to the disk, so a Livewire temporary file on S3 is copied
        // without passing through the web server's memory.
        $path = $file->storeAs($directory, $filename, ['disk' => $disk]);

        if (! is_string($path)) {
            throw new \RuntimeException("Upload [{$file->getClientOriginalName()}] could not be stored on disk [{$disk}].");
        }

        $upload = Upload::create([
            'uploadable_type' => $owner?->getMorphClass(),
            'uploadable_id' => $owner?->getKey(),
            'user_id' => $userId ?? auth()->id(),
            'collection' => $collection,
            'disk' => $disk,
            'path' => $path,
            'name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?: $file->getClientMimeType(),
            'size' => (int) $file->getSize(),
            'is_public' => $public,
            'sort_order' => $this->nextSortOrder($owner, $collection),
            'meta' => $meta === [] ? null : $meta,
        ]);

        ProcessUpload::dispatchFor($upload);

        return $upload;
    }

    /**
     * `<prefix>/<models>/<id>/<collection>` for an owned upload, `<prefix>/uploads/<collection>` otherwise.
     */
    protected function directoryFor(?Model $owner, string $collection): string
    {
        $segments = array_filter([
            $this->config->pathPrefix(),
            $owner ? Str::plural(Str::kebab(class_basename($owner))) : 'uploads',
            $owner ? (string) $owner->getKey() : null,
            Str::slug($collection) ?: 'files',
        ], fn (?string $segment): bool => $segment !== null && $segment !== '');

        return implode('/', $segments);
    }

    /**
     * The lower-cased extension with its dot, or an empty string when the file has none.
     */
    protected function extensionOf(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: (string) $file->guessExtension());

        return $extension === '' ? '' : '.'.$extension;
    }

    protected function nextSortOrder(?Model $owner, string $collection): int
    {
        if ($owner === null) {
            return 0;
        }

        return Upload::query()
            ->where('uploadable_type', $owner->getMorphClass())
            ->where('uploadable_id', $owner->getKey())
            ->collection($collection)
            ->count();
    }
}

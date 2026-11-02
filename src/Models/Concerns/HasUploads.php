<?php

namespace Darvis\Uploads\Models\Concerns;

use Darvis\Uploads\Actions\StoreUpload;
use Darvis\Uploads\Models\Upload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Give a model named collections of uploads.
 *
 * @mixin Model
 */
trait HasUploads
{
    /**
     * Every upload of this model, in display order. Soft deleted uploads are left out.
     *
     * @return MorphMany<Upload, $this>
     */
    public function uploads(): MorphMany
    {
        return $this->morphMany(Upload::class, 'uploadable')->ordered();
    }

    /**
     * The uploads of one collection, in display order.
     *
     * @return MorphMany<Upload, $this>
     */
    public function uploadsIn(string $collection): MorphMany
    {
        return $this->uploads()->collection($collection);
    }

    /**
     * The first upload of a collection, for example the cover photo.
     */
    public function firstUpload(string $collection = 'files'): ?Upload
    {
        return $this->uploadsIn($collection)->first();
    }

    /**
     * The folder of this model's uploads, the `{module}` of the path pattern. Override it
     * to store, for example, the uploads of `NewsItem` under `news` instead of `news-items`.
     *
     * @return string
     */
    public function uploadsModule()
    {
        return Str::plural(Str::kebab(class_basename($this)));
    }

    /**
     * Store a file for this model and queue its processing.
     *
     * @param  array<string, mixed>  $meta  Extra data kept with the upload, such as a caption.
     */
    public function addUpload(UploadedFile $file, string $collection = 'files', bool $public = false, array $meta = []): Upload
    {
        return app(StoreUpload::class)->handle($file, $collection, $public, $meta, $this);
    }

    /**
     * Store file contents for this model, such as a generated image, and queue its processing.
     * The name is the original file name; without a mime type it is detected from the contents.
     *
     * @param  array<string, mixed>  $meta  Extra data kept with the upload, such as a caption.
     */
    public function addUploadFromContents(string $contents, string $name, string $collection = 'files', bool $public = false, array $meta = [], ?string $mimeType = null): Upload
    {
        return app(StoreUpload::class)->handleContents($contents, $name, $collection, $public, $meta, $this, mimeType: $mimeType);
    }

    /**
     * Put the uploads of a collection in the given order of ids. Ids that are not
     * in the list keep their place after the ordered ones.
     *
     * @param  array<int, int|string>  $ids
     */
    public function reorderUploads(string $collection, array $ids): void
    {
        $position = 0;

        foreach ($ids as $id) {
            $this->uploadsIn($collection)->whereKey($id)->update(['sort_order' => $position++]);
        }

        $this->uploadsIn($collection)
            ->whereKeyNot($ids)
            ->get()
            ->each(fn (Upload $upload) => $upload->update(['sort_order' => $position++]));
    }
}

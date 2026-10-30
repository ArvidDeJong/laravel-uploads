<?php

namespace Darvis\Uploads\Contracts;

use Darvis\Uploads\Models\Upload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\UploadedFile;

/**
 * What the HasUploads trait provides. Implement it on a model that uses the trait
 * when you want to type hint "a model with uploads"; the trait satisfies it.
 *
 * @phpstan-require-extends Model
 */
interface UploadOwner
{
    /**
     * @return MorphMany<Upload, covariant Model>
     */
    public function uploads(): MorphMany;

    /**
     * @return MorphMany<Upload, covariant Model>
     */
    public function uploadsIn(string $collection): MorphMany;

    public function firstUpload(string $collection = 'files'): ?Upload;

    /**
     * @param  array<string, mixed>  $meta
     */
    public function addUpload(UploadedFile $file, string $collection = 'files', bool $public = false, array $meta = []): Upload;

    /**
     * @param  array<int, int|string>  $ids
     */
    public function reorderUploads(string $collection, array $ids): void;
}

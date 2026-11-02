<?php

namespace Darvis\Uploads\Livewire;

use Darvis\Uploads\Contracts\UploadOwner;
use Darvis\Uploads\Models\Upload;
use Darvis\Uploads\Support\UploadsConfig;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Upload, caption, sort and remove the uploads of one collection of a model.
 *
 * <livewire:uploads.upload-manager :model="$product" collection="photos" :public="true" accept="jpg,jpeg,png,webp" />
 *
 * With `:crop="true"` every image gets a Crop button that opens Cropper.js; `aspect-ratio="16/9"`
 * fixes the shape of the crop area.
 */
class UploadManager extends Component
{
    use WithFileUploads;

    /**
     * The model that owns the uploads; it uses the HasUploads trait.
     *
     * @var Model&UploadOwner
     */
    public Model $model;

    public string $collection = 'files';

    public bool $public = false;

    /**
     * Comma separated extensions the file input accepts; empty for any file.
     */
    public string $accept = '';

    /**
     * Ability on the model that is checked before anything changes; empty to skip the check.
     */
    public string $ability = 'update';

    /**
     * Whether images can be cropped with Cropper.js.
     */
    public bool $crop = false;

    /**
     * Fixed shape of the crop area, such as `16/9`, `4:3` or `1`; empty for a free shape.
     */
    public string $aspectRatio = '';

    /**
     * Files picked in the file input, stored on save.
     *
     * @var array<int, TemporaryUploadedFile>
     */
    public array $files = [];

    /**
     * Captions by upload id, bound to the inputs in the list.
     *
     * @var array<int|string, string|null>
     */
    public array $captions = [];

    public function mount(): void
    {
        $this->authorizeModel();

        foreach ($this->uploads() as $upload) {
            $this->captions[$upload->getKey()] = (string) $upload->meta('caption', '');
        }
    }

    /**
     * @return array<string, string>
     */
    protected function rules(): array
    {
        $rules = 'file|max:'.app(UploadsConfig::class)->maxKilobytes();

        if ($this->accept !== '') {
            $rules .= '|extensions:'.str_replace(' ', '', strtolower($this->accept));
        }

        return ['files.*' => $rules];
    }

    public function save(): void
    {
        $this->authorizeModel();
        $this->validate();

        foreach ($this->files as $file) {
            $upload = $this->model->addUpload($file, $this->collection, $this->public);
            $this->captions[$upload->getKey()] = '';
        }

        $this->reset('files');
        $this->dispatch('uploads-changed', collection: $this->collection);
    }

    public function updatedCaptions(mixed $value, string $key): void
    {
        $this->authorizeModel();

        $upload = $this->uploads()->firstWhere((new Upload)->getKeyName(), (int) $key);

        $upload?->setMeta('caption', is_string($value) && $value !== '' ? $value : null);
    }

    /**
     * @param  array<int, int|string>  $ids
     */
    public function reorder(array $ids): void
    {
        $this->authorizeModel();
        $this->model->reorderUploads($this->collection, $ids);
    }

    /**
     * Keep the given area of an image for its conversions; the original stays as it is.
     *
     * @param  array<string, mixed>  $area  `x`, `y`, `width` and `height` in pixels of the original.
     */
    public function saveCrop(int $id, array $area): void
    {
        $this->authorizeModel();

        $upload = $this->croppable($id);

        try {
            $upload?->setCropArea($area);
        } catch (\InvalidArgumentException) {
            $this->addError('crop', __('The crop area is not valid.'));

            return;
        }

        $this->dispatch('uploads-changed', collection: $this->collection);
    }

    /**
     * Use the whole image again for the conversions.
     */
    public function resetCrop(int $id): void
    {
        $this->authorizeModel();

        $this->croppable($id)?->setCropArea(null);

        $this->dispatch('uploads-changed', collection: $this->collection);
    }

    public function delete(int $id): void
    {
        $this->authorizeModel();

        $this->uploads()->firstWhere((new Upload)->getKeyName(), $id)?->delete();
        unset($this->captions[$id]);

        $this->dispatch('uploads-changed', collection: $this->collection);
    }

    public function render(): View
    {
        $uploads = $this->uploads();

        $config = app(UploadsConfig::class);

        return view('uploads::livewire.upload-manager', [
            'uploads' => $uploads,
            'processing' => $uploads->contains(fn (Upload $upload): bool => ! $upload->isProcessed()),
            'cropRatio' => $this->cropRatio(),
            'cropperScript' => $config->cropperScript(),
            'cropperStyle' => $config->cropperStyle(),
        ]);
    }

    /**
     * @return Collection<int, Upload>
     */
    protected function uploads()
    {
        return $this->model->uploadsIn($this->collection)->get();
    }

    /**
     * An image of this collection when cropping is on; null otherwise.
     */
    protected function croppable(int $id): ?Upload
    {
        if (! $this->crop) {
            return null;
        }

        $upload = $this->uploads()->firstWhere((new Upload)->getKeyName(), $id);

        return $upload?->isImage() ? $upload : null;
    }

    /**
     * The aspect ratio as a number, from `16/9`, `16:9` or `1.5`; null for a free shape.
     */
    protected function cropRatio(): ?float
    {
        $parts = preg_split('#\s*[/:]\s*#', trim($this->aspectRatio)) ?: [];

        if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1]) && (float) $parts[1] > 0) {
            $ratio = (float) $parts[0] / (float) $parts[1];
        } elseif (count($parts) === 1 && is_numeric($parts[0])) {
            $ratio = (float) $parts[0];
        } else {
            return null;
        }

        return $ratio > 0 ? $ratio : null;
    }

    protected function authorizeModel(): void
    {
        if ($this->ability !== '' && Gate::getPolicyFor($this->model) !== null) {
            Gate::authorize($this->ability, $this->model);
        }
    }
}

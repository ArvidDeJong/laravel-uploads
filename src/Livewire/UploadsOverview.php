<?php

namespace Darvis\Uploads\Livewire;

use Darvis\Uploads\Models\Upload;
use Darvis\Uploads\Support\UploadStatistics;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The administration of every upload: totals per disk and collection, a filterable
 * list, the trash, and delete, restore and hard delete per upload.
 *
 * <livewire:uploads.uploads-overview />
 */
class UploadsOverview extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $collection = '';

    #[Url]
    public string $disk = '';

    #[Url]
    public bool $trashed = false;

    public int $perPage = 25;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCollection(): void
    {
        $this->resetPage();
    }

    public function updatedDisk(): void
    {
        $this->resetPage();
    }

    public function updatedTrashed(): void
    {
        $this->resetPage();
    }

    /**
     * Soft delete: the row goes to the trash and the files stay until `uploads:prune`.
     */
    public function delete(int $id): void
    {
        $upload = Upload::query()->findOrFail($id);
        Gate::authorize('delete', $upload);

        $upload->delete();
    }

    public function restore(int $id): void
    {
        $upload = Upload::onlyTrashed()->findOrFail($id);
        Gate::authorize('delete', $upload);

        $upload->restore();
    }

    /**
     * Hard delete: the row and every file are removed for good.
     */
    public function forceDelete(int $id): void
    {
        $upload = Upload::withTrashed()->findOrFail($id);
        Gate::authorize('delete', $upload);

        $upload->forceDelete();
    }

    public function render(UploadStatistics $statistics): View
    {
        return view('uploads::livewire.uploads-overview', [
            'uploads' => $this->query()->paginate($this->perPage),
            'collections' => Upload::withTrashed()->distinct()->orderBy('collection')->pluck('collection'),
            'disks' => Upload::withTrashed()->distinct()->orderBy('disk')->pluck('disk'),
            'perDisk' => $statistics->perDisk(),
            'perCollection' => $statistics->perCollection(),
            'total' => $statistics->total(),
            'inTrash' => $statistics->trashed(),
        ]);
    }

    /**
     * @return Builder<Upload>
     */
    protected function query(): Builder
    {
        $query = $this->trashed ? Upload::onlyTrashed() : Upload::query();

        return $query
            ->with(['uploadable', 'user'])
            ->when($this->search !== '', fn (Builder $query) => $query->where('name', 'like', '%'.$this->search.'%'))
            ->when($this->collection !== '', fn (Builder $query) => $query->collection($this->collection))
            ->when($this->disk !== '', fn (Builder $query) => $query->where('disk', $this->disk))
            ->latest('id');
    }
}

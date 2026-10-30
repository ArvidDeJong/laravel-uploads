<?php

use Darvis\Uploads\Livewire\UploadManager;
use Darvis\Uploads\Livewire\UploadsOverview;
use Darvis\Uploads\Models\Upload;
use Darvis\Uploads\Tests\Fixtures\ClosedProductPolicy;
use Darvis\Uploads\Tests\Fixtures\Product;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('uploads files through the manager and lists them', function () {
    $product = $this->createProduct();

    Livewire::test(UploadManager::class, ['model' => $product, 'collection' => 'photos', 'public' => true])
        ->assertSee('No files yet.')
        ->set('files', [UploadedFile::fake()->image('one.png', 400, 300), UploadedFile::fake()->image('two.png', 400, 300)])
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('uploads-changed')
        ->assertSee('one.png')
        ->assertSee('two.png');

    expect($product->uploadsIn('photos')->count())->toBe(2)
        ->and($product->uploadsIn('photos')->first()?->is_public)->toBeTrue();
});

it('refuses files that are too large or of the wrong type', function () {
    config()->set('uploads.max_kilobytes', 10);
    $product = $this->createProduct();

    Livewire::test(UploadManager::class, ['model' => $product, 'accept' => 'jpg, png'])
        ->set('files', [UploadedFile::fake()->create('big.png', 20)])
        ->call('save')
        ->assertHasErrors(['files.0']);

    Livewire::test(UploadManager::class, ['model' => $product, 'accept' => 'jpg, png'])
        ->set('files', [UploadedFile::fake()->create('notes.txt', 1)])
        ->call('save')
        ->assertHasErrors(['files.0']);

    expect($product->uploads()->count())->toBe(0);
});

it('saves a caption, reorders and soft deletes from the manager', function () {
    $product = $this->createProduct();
    $a = $product->addUpload(UploadedFile::fake()->create('a.txt', 1));
    $b = $product->addUpload(UploadedFile::fake()->create('b.txt', 1));

    Livewire::test(UploadManager::class, ['model' => $product])
        ->set('captions.'.$a->id, 'Front view')
        ->call('reorder', [$b->id, $a->id])
        ->call('delete', $b->id)
        ->assertDispatched('uploads-changed');

    expect($a->fresh()->meta('caption'))->toBe('Front view')
        ->and($a->fresh()->sort_order)->toBe(1)
        ->and(Upload::find($b->id))->toBeNull()
        ->and(Upload::withTrashed()->find($b->id)?->trashed())->toBeTrue();
    Storage::disk('local')->assertExists($b->path);
});

it('checks the ability on the model when a policy exists', function () {
    Gate::policy(Product::class, ClosedProductPolicy::class);
    $product = $this->createProduct();

    $this->withoutExceptionHandling();
    Livewire::test(UploadManager::class, ['model' => $product]);
})->throws(AuthorizationException::class);

it('shows totals, filters and manages the trash in the overview', function () {
    $this->actingAs($this->createUser());
    $product = $this->createProduct();
    $photo = $product->addUpload(UploadedFile::fake()->image('photo.png', 400, 300), 'photos', public: true);
    $file = $product->addUpload(UploadedFile::fake()->create('order.3mf', 4), 'files');

    $component = Livewire::test(UploadsOverview::class)
        ->assertSee('photo.png')
        ->assertSee('order.3mf')
        ->assertSee('2 files')
        ->set('collection', 'photos')
        ->assertSee('photo.png')
        ->assertDontSee('order.3mf')
        ->set('collection', '')
        ->set('search', 'order')
        ->assertDontSee('photo.png')
        ->assertSee('order.3mf')
        ->set('search', '')
        ->call('delete', $file->id)
        ->assertDontSee('order.3mf')
        ->set('trashed', true)
        ->assertSee('order.3mf')
        ->assertDontSee('photo.png')
        ->call('restore', $file->id)
        ->assertDontSee('order.3mf');

    expect($file->fresh()?->trashed())->toBeFalse();

    $component->set('trashed', false)
        ->call('delete', $file->id)
        ->set('trashed', true)
        ->call('forceDelete', $file->id);

    expect(Upload::withTrashed()->find($file->id))->toBeNull();
    Storage::disk('local')->assertMissing($file->path);
    expect($photo->fresh())->not->toBeNull();
});

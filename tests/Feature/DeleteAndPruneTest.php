<?php

use Darvis\Uploads\Models\Upload;
use Darvis\Uploads\Support\UploadStatistics;
use Darvis\Uploads\UploadsServiceProvider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('soft deletes an upload and keeps the files so it can be restored', function () {
    $product = $this->createProduct();
    $upload = $product->addUpload($this->fakeImage(), 'photos', public: true)->fresh();
    $thumb = $upload->conversionPath('thumb');

    $upload->delete();

    expect($upload->trashed())->toBeTrue()
        ->and($product->uploads()->count())->toBe(0)
        ->and(Upload::withTrashed()->count())->toBe(1);
    Storage::disk('public')->assertExists($upload->path);
    Storage::disk('public')->assertExists($thumb);

    $upload->restore();

    expect($product->uploads()->count())->toBe(1);
});

it('hard deletes an upload together with its conversions and pages', function () {
    $product = $this->createProduct();
    $upload = $product->addUpload($this->fakeImage(), 'photos', public: true)->fresh();
    $paths = array_merge([$upload->path], array_values(array_filter($upload->conversions ?? [], 'is_string')));

    expect($paths)->toHaveCount(3);

    $upload->forceDelete();

    foreach ($paths as $path) {
        Storage::disk('public')->assertMissing($path);
    }
    expect(Upload::withTrashed()->count())->toBe(0);
});

it('prunes uploads that have been in the trash longer than the configured days', function () {
    config()->set('uploads.prune_after_days', 7);
    $product = $this->createProduct();
    $old = $product->addUpload(UploadedFile::fake()->create('old.txt', 1))->fresh();
    $recent = $product->addUpload(UploadedFile::fake()->create('recent.txt', 1))->fresh();
    $kept = $product->addUpload(UploadedFile::fake()->create('kept.txt', 1))->fresh();

    $old->delete();
    $recent->delete();
    Upload::withTrashed()->whereKey($old->id)->update(['deleted_at' => now()->subDays(8)]);

    $this->artisan('uploads:prune')
        ->expectsOutputToContain('Pruned 1 upload(s)')
        ->assertSuccessful();

    Storage::disk('local')->assertMissing($old->path);
    Storage::disk('local')->assertExists($recent->path);
    Storage::disk('local')->assertExists($kept->path);
    expect(Upload::withTrashed()->pluck('id')->all())->toBe([$recent->id, $kept->id]);
});

it('prunes with the days option and through model:prune', function () {
    $product = $this->createProduct();
    $upload = $product->addUpload(UploadedFile::fake()->create('old.txt', 1))->fresh();
    $upload->delete();
    Upload::withTrashed()->whereKey($upload->id)->update(['deleted_at' => now()->subDays(2)]);

    $this->artisan('uploads:prune', ['--days' => 1])->assertSuccessful();

    Storage::disk('local')->assertMissing($upload->path);
    expect(Upload::withTrashed()->count())->toBe(0);
});

it('counts files and bytes per disk and collection, trash apart', function () {
    $product = $this->createProduct();
    $product->addUpload(UploadedFile::fake()->create('a.txt', 2), 'files');
    $product->addUpload(UploadedFile::fake()->create('b.txt', 3), 'files', public: true);
    $trashed = $product->addUpload(UploadedFile::fake()->create('c.txt', 5), 'photos');
    $trashed->delete();

    $statistics = app(UploadStatistics::class);

    expect($statistics->total())->toBe(['files' => 2, 'bytes' => 5 * 1024])
        ->and($statistics->trashed())->toBe(['files' => 1, 'bytes' => 5 * 1024])
        ->and($statistics->perDisk()->all())->toBe([
            ['disk' => 'local', 'files' => 1, 'bytes' => 2 * 1024],
            ['disk' => 'public', 'files' => 1, 'bytes' => 3 * 1024],
        ])
        ->and($statistics->perCollection()->all())->toBe([
            ['collection' => 'files', 'files' => 2, 'bytes' => 5 * 1024],
        ]);
});

it('reads and writes meta', function () {
    $product = $this->createProduct();
    $upload = $product->addUpload(UploadedFile::fake()->create('a.txt', 1), meta: ['caption' => 'Old', 'print' => ['layer_height' => 0.2]]);

    expect($upload->meta('caption'))->toBe('Old')
        ->and($upload->meta('print.layer_height'))->toBe(0.2)
        ->and($upload->meta('missing', 'default'))->toBe('default');

    $upload->setMeta('caption', 'New');
    $upload->setMeta('print.infill', 20);

    expect($upload->fresh()->meta)->toBe(['caption' => 'New', 'print' => ['layer_height' => 0.2, 'infill' => 20]])
        ->and($upload->humanSize())->toBe('1.0 KB');
});

it('publishes the config and the views', function () {
    $paths = UploadsServiceProvider::pathsToPublish(null, 'uploads-config');
    expect(array_values($paths))->toBe([config_path('uploads.php')]);

    $views = UploadsServiceProvider::pathsToPublish(null, 'uploads-views');
    expect(array_values($views))->toBe([resource_path('views/vendor/uploads')]);
});

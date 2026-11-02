<?php

use Darvis\Uploads\Jobs\ProcessUpload;
use Darvis\Uploads\Livewire\UploadManager;
use Darvis\Uploads\Models\Upload;
use Darvis\Uploads\Processing\ImageConverter;
use Darvis\Uploads\Support\UploadsConfig;
use Darvis\Uploads\Tests\Fixtures\ClosedProductPolicy;
use Darvis\Uploads\Tests\Fixtures\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Width and height of a generated file of the upload.
 *
 * @return array{0: int, 1: int}
 */
function cropTestSize(Upload $upload, string $conversion): array
{
    [$width, $height] = getimagesizefromstring((string) Storage::disk($upload->disk)->get((string) $upload->conversionPath($conversion)));

    return [$width, $height];
}

it('makes a conversion from the crop area only', function () {
    $source = $this->fakeImage('photo.png', 800, 600)->getRealPath();

    [$binary] = app(ImageConverter::class)->make($source, ['format' => 'png', 'crop' => ['x' => 100, 'y' => 50, 'width' => 300, 'height' => 200]]);
    [$width, $height] = getimagesizefromstring($binary);

    expect([$width, $height])->toBe([300, 200]);
});

it('keeps a crop area that reaches past the edge inside the image', function () {
    $source = $this->fakeImage('photo.png', 800, 600)->getRealPath();

    [$binary] = app(ImageConverter::class)->make($source, ['format' => 'png', 'crop' => ['x' => 700, 'y' => 500, 'width' => 400, 'height' => 400]]);
    [$width, $height] = getimagesizefromstring($binary);

    expect([$width, $height])->toBe([100, 100]);
});

it('stores the crop area, makes the conversions again and keeps the original', function () {
    $product = $this->createProduct();
    $upload = $product->addUpload($this->fakeImage('photo.png', 800, 600), 'photos', public: true)->fresh();
    $original = Storage::disk('public')->get($upload->path);

    expect(cropTestSize($upload, 'preview'))->toBe([800, 600]);

    $upload->setCropArea(['x' => 0, 'y' => 0, 'width' => 400.4, 'height' => '200']);
    $upload = $upload->fresh();

    expect($upload->cropArea())->toBe(['x' => 0, 'y' => 0, 'width' => 400, 'height' => 200])
        ->and($upload->meta(Upload::CROP))->toBe(['x' => 0, 'y' => 0, 'width' => 400, 'height' => 200])
        ->and($upload->isProcessed())->toBeTrue()
        ->and(cropTestSize($upload, 'preview'))->toBe([400, 200])
        ->and(Storage::disk('public')->get($upload->path))->toBe($original);

    $upload->setCropArea(null);

    expect($upload->fresh()->cropArea())->toBeNull()
        ->and(cropTestSize($upload->fresh(), 'preview'))->toBe([800, 600]);
});

it('marks the upload as not processed and queues the job when the crop changes', function () {
    $product = $this->createProduct();
    $upload = $product->addUpload($this->fakeImage(), 'photos', public: true)->fresh();
    Queue::fake();

    $upload->setCropArea(['x' => 10, 'y' => 10, 'width' => 100, 'height' => 100]);

    expect($upload->fresh()->isProcessed())->toBeFalse();
    Queue::assertPushed(ProcessUpload::class, fn (ProcessUpload $job) => $job->upload->is($upload));
});

it('refuses a crop area that is not valid or a file that is not an image', function () {
    Queue::fake();
    $product = $this->createProduct();
    $image = $product->addUpload($this->fakeImage(), 'photos');
    $text = $product->addUpload(UploadedFile::fake()->create('notes.txt', 1));

    expect(fn () => $image->setCropArea(['x' => 0, 'y' => 0, 'width' => 0, 'height' => 10]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $image->setCropArea(['x' => -1, 'y' => 0, 'width' => 10, 'height' => 10]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $image->setCropArea(['x' => 0, 'width' => 10, 'height' => 10]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $text->setCropArea(['x' => 0, 'y' => 0, 'width' => 10, 'height' => 10]))->toThrow(InvalidArgumentException::class)
        ->and($image->fresh()->cropArea())->toBeNull();
});

it('adds the processing time to public conversion URLs only', function () {
    $product = $this->createProduct();
    $upload = $product->addUpload($this->fakeImage(), 'photos', public: true)->fresh();
    $version = '?v='.$upload->processed_at->getTimestamp();

    expect($upload->thumbnailUrl())->toEndWith($version)
        ->and($upload->url())->not->toContain('?v=')
        ->and($upload->conversionUrl('missing'))->not->toContain('?v=');
});

it('crops an image from the manager when cropping is on', function () {
    $product = $this->createProduct();
    $upload = $product->addUpload($this->fakeImage('photo.png', 800, 600), 'photos', public: true);

    Livewire::test(UploadManager::class, ['model' => $product, 'collection' => 'photos', 'crop' => true, 'aspect-ratio' => '16/9'])
        ->assertSet('aspectRatio', '16/9')
        ->assertSee('Crop the image')
        ->assertSee('openCrop('.$upload->id, false)
        ->call('saveCrop', $upload->id, ['x' => 0, 'y' => 0, 'width' => 320, 'height' => 180])
        ->assertHasNoErrors()
        ->assertDispatched('uploads-changed')
        ->assertSee('Cropped');

    expect($upload->fresh()->cropArea())->toBe(['x' => 0, 'y' => 0, 'width' => 320, 'height' => 180])
        ->and(cropTestSize($upload->fresh(), 'preview'))->toBe([320, 180]);

    Livewire::test(UploadManager::class, ['model' => $product, 'collection' => 'photos', 'crop' => true])
        ->call('saveCrop', $upload->id, ['x' => 0, 'y' => 0, 'width' => 0, 'height' => 0])
        ->assertHasErrors(['crop'])
        ->call('resetCrop', $upload->id);

    expect($upload->fresh()->cropArea())->toBeNull();
});

it('does not crop when cropping is off, outside the collection or for other files', function () {
    $product = $this->createProduct();
    $photo = $product->addUpload($this->fakeImage(), 'photos');
    $other = $product->addUpload($this->fakeImage(), 'logos');
    $text = $product->addUpload(UploadedFile::fake()->create('notes.txt', 1), 'photos');
    $area = ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100];

    Livewire::test(UploadManager::class, ['model' => $product, 'collection' => 'photos'])
        ->assertDontSee('Crop the image')
        ->call('saveCrop', $photo->id, $area);

    Livewire::test(UploadManager::class, ['model' => $product, 'collection' => 'photos', 'crop' => true])
        ->call('saveCrop', $other->id, $area)
        ->call('saveCrop', $text->id, $area)
        ->assertHasNoErrors();

    expect($photo->fresh()->cropArea())->toBeNull()
        ->and($other->fresh()->cropArea())->toBeNull()
        ->and($text->fresh()->cropArea())->toBeNull();
});

it('checks the policy of the model before it crops', function () {
    $product = $this->createProduct();
    $photo = $product->addUpload($this->fakeImage(), 'photos');
    $manager = Livewire::test(UploadManager::class, ['model' => $product, 'collection' => 'photos', 'crop' => true]);
    Gate::policy(Product::class, ClosedProductPolicy::class);

    $manager->call('saveCrop', $photo->id, ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100])->assertForbidden();

    expect($photo->fresh()->cropArea())->toBeNull();
});

it('reads the Cropper.js URLs and lets the app load it itself', function () {
    $config = app(UploadsConfig::class);

    expect($config->cropperScript())->toBe('https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.js')
        ->and($config->cropperStyle())->toBe('https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.css');

    config()->set('uploads.cropper', ['script' => '', 'style' => '']);

    expect($config->cropperScript())->toBeNull()->and($config->cropperStyle())->toBeNull();
});

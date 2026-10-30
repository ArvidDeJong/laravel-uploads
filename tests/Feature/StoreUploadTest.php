<?php

use Darvis\Uploads\Actions\StoreUpload;
use Darvis\Uploads\Events\UploadProcessed;
use Darvis\Uploads\Exceptions\PdfRenderingUnavailable;
use Darvis\Uploads\Jobs\ProcessUpload;
use Darvis\Uploads\Models\Upload;
use Darvis\Uploads\Processing\PdfRasterizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

it('stores a public upload on the public disk under the model path', function () {
    Queue::fake();
    $product = $this->createProduct();

    $upload = $product->addUpload($this->fakeImage('IMG_0001.png'), 'photos', public: true, meta: ['caption' => 'Front']);

    expect($upload)->toBeInstanceOf(Upload::class)
        ->and($upload->disk)->toBe('public')
        ->and($upload->is_public)->toBeTrue()
        ->and($upload->collection)->toBe('photos')
        ->and($upload->name)->toBe('IMG_0001.png')
        ->and($upload->mime_type)->toBe('image/png')
        ->and($upload->size)->toBeGreaterThan(0)
        ->and($upload->meta('caption'))->toBe('Front')
        ->and($upload->uploadable)->toBeInstanceOf(get_class($product))
        ->and($upload->path)->toMatch('#^products/'.$product->id.'/photos/[0-9a-f-]{36}\.png$#');

    Storage::disk('public')->assertExists($upload->path);
    Queue::assertPushed(ProcessUpload::class, fn (ProcessUpload $job) => $job->upload->is($upload));
});

it('stores a private upload on the private disk', function () {
    Queue::fake();
    $product = $this->createProduct();

    $upload = $product->addUpload(UploadedFile::fake()->create('order.3mf', 10), 'files');

    expect($upload->disk)->toBe('local')->and($upload->is_public)->toBeFalse();
    Storage::disk('local')->assertExists($upload->path);
    Storage::disk('public')->assertMissing($upload->path);
});

it('stores an upload without an owner under the uploads folder', function () {
    Queue::fake();

    $upload = app(StoreUpload::class)->handle(UploadedFile::fake()->create('note.txt', 1), 'attachments');

    expect($upload->uploadable_type)->toBeNull()
        ->and($upload->path)->toStartWith('uploads/attachments/');

    config()->set('uploads.path_prefix', 'media');
    $prefixed = app(StoreUpload::class)->handle(UploadedFile::fake()->create('note.txt', 1), 'attachments');

    expect($prefixed->path)->toStartWith('media/uploads/attachments/');
});

it('honours the path prefix for owned uploads', function () {
    Queue::fake();
    config()->set('uploads.path_prefix', '/media/');
    $product = $this->createProduct();

    $upload = $product->addUpload(UploadedFile::fake()->create('a.txt', 1));

    expect($upload->path)->toStartWith('media/products/'.$product->id.'/files/');
});

it('records the uploader and gives each file the next sort order in its collection', function () {
    Queue::fake();
    $user = $this->createUser();
    $this->actingAs($user);
    $product = $this->createProduct();

    $first = $product->addUpload(UploadedFile::fake()->create('a.txt', 1), 'files');
    $second = $product->addUpload(UploadedFile::fake()->create('b.txt', 1), 'files');
    $other = $product->addUpload(UploadedFile::fake()->create('c.txt', 1), 'photos');

    expect($first->user_id)->toBe($user->id)
        ->and($first->sort_order)->toBe(0)
        ->and($second->sort_order)->toBe(1)
        ->and($other->sort_order)->toBe(0)
        ->and($product->uploadsIn('files')->pluck('id')->all())->toBe([$first->id, $second->id])
        ->and($product->firstUpload('files')?->id)->toBe($first->id);
});

it('reorders a collection and keeps unknown ids after the ordered ones', function () {
    Queue::fake();
    $product = $this->createProduct();
    $a = $product->addUpload(UploadedFile::fake()->create('a.txt', 1));
    $b = $product->addUpload(UploadedFile::fake()->create('b.txt', 1));
    $c = $product->addUpload(UploadedFile::fake()->create('c.txt', 1));

    $product->reorderUploads('files', [$c->id, $a->id]);

    expect($product->uploadsIn('files')->pluck('id')->all())->toBe([$c->id, $a->id, $b->id]);
});

it('processes an image through the job and dispatches an event', function () {
    Event::fake([UploadProcessed::class]);
    $product = $this->createProduct();

    $upload = $product->addUpload($this->fakeImage(), 'photos', public: true)->fresh();

    expect($upload->isProcessed())->toBeTrue()
        ->and($upload->hasConversion('thumb'))->toBeTrue()
        ->and($upload->hasConversion('preview'))->toBeTrue();

    Storage::disk('public')->assertExists($upload->conversionPath('thumb'));
    [$width, $height] = getimagesizefromstring(Storage::disk('public')->get($upload->conversionPath('thumb')));
    expect($width)->toBe(300)->and($height)->toBe(300);

    [$width, $height] = getimagesizefromstring(Storage::disk('public')->get($upload->conversionPath('preview')));
    expect($width)->toBe(800)->and($height)->toBe(600);

    Event::assertDispatched(UploadProcessed::class, fn (UploadProcessed $event) => $event->upload->is($upload));
});

it('generates conversions from the config and writes them next to the original', function () {
    config()->set('uploads.conversions', [
        'small' => ['width' => 100, 'height' => 50, 'fit' => 'contain', 'format' => 'jpg', 'quality' => 70],
    ]);
    $product = $this->createProduct();

    $upload = $product->addUpload($this->fakeImage(), 'photos', public: true)->fresh();

    expect($upload->conversions)->toHaveKeys(['small'])
        ->and($upload->conversionPath('small'))->toBe(preg_replace('/\.png$/', '-small.jpg', $upload->path));

    [$width, $height] = getimagesizefromstring(Storage::disk('public')->get($upload->conversionPath('small')));
    expect($width)->toBe(67)->and($height)->toBe(50);
});

it('marks a file that is neither an image nor a PDF as processed without conversions', function () {
    $product = $this->createProduct();

    $upload = $product->addUpload(UploadedFile::fake()->create('print.gcode', 5, 'text/plain'), 'files')->fresh();

    expect($upload->isProcessed())->toBeTrue()->and($upload->conversions)->toBeNull();
});

it('renders the pages of a PDF and makes the conversions from the first page', function () {
    $product = $this->createProduct();

    $upload = $product->addUpload($this->fakePdf('manual.pdf', 2), 'documents')->fresh();

    expect($upload->isProcessed())->toBeTrue()
        ->and($upload->pageCount())->toBe(2)
        ->and($upload->hasConversion('thumb'))->toBeTrue();

    foreach ($upload->pagePaths() as $page) {
        Storage::disk('local')->assertExists($page);
        expect($page)->toEndWith('.jpg');
    }

    expect(getimagesizefromstring(Storage::disk('local')->get($upload->pagePaths()[0]))[2])->toBe(IMAGETYPE_JPEG);
})->skip(fn () => ! app(PdfRasterizer::class)->isAvailable(), 'No PDF driver on this machine.');

it('limits the rendered pages to max_pages', function () {
    config()->set('uploads.pdf.max_pages', 1);
    $product = $this->createProduct();

    $upload = $product->addUpload($this->fakePdf('manual.pdf', 3), 'documents')->fresh();

    expect($upload->pageCount())->toBe(1);
})->skip(fn () => ! app(PdfRasterizer::class)->isAvailable(), 'No PDF driver on this machine.');

it('fails the job for a PDF when no driver is available', function () {
    config()->set('uploads.pdf.driver', 'pdftoppm');
    config()->set('uploads.pdf.pdftoppm_path', '/nonexistent/pdftoppm');
    $product = $this->createProduct();

    $product->addUpload($this->fakePdf(), 'documents');
})->throws(PdfRenderingUnavailable::class);

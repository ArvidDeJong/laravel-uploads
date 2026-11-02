<?php

use Darvis\Uploads\Actions\StoreUpload;
use Darvis\Uploads\Events\UploadProcessed;
use Darvis\Uploads\Exceptions\PdfRenderingUnavailable;
use Darvis\Uploads\Jobs\ProcessUpload;
use Darvis\Uploads\Models\Upload;
use Darvis\Uploads\Tests\Fixtures\NewsItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

it('stores a public upload on the public disk under the module, year and month', function () {
    Queue::fake();
    $this->travelTo(now()->setDate(2026, 3, 14));
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
        ->and($upload->path)->toBe('uploads/products/2026/03/img-0001.png');

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

it('uses the collection as module for an upload without an owner', function () {
    Queue::fake();
    $this->travelTo(now()->setDate(2026, 11, 2));

    $upload = app(StoreUpload::class)->handle(UploadedFile::fake()->create('note.txt', 1), 'attachments');

    expect($upload->uploadable_type)->toBeNull()
        ->and($upload->path)->toStartWith('uploads/attachments/2026/11/');
});

it('lets the owner name its module', function () {
    Queue::fake();
    $this->travelTo(now()->setDate(2026, 11, 2));
    $item = NewsItem::create(['name' => 'Opening']);

    $upload = $item->addUpload(UploadedFile::fake()->create('a.txt', 1));

    expect($upload->path)->toStartWith('uploads/news/2026/11/');
});

it('honours the path prefix and the path pattern', function () {
    Queue::fake();
    $this->travelTo(now()->setDate(2026, 1, 5));
    config()->set('uploads.path_prefix', '/media/');
    config()->set('uploads.path_pattern', '/{module}/{id}/{collection}/{year}-{month}-{day}/');
    $product = $this->createProduct();

    $upload = $product->addUpload(UploadedFile::fake()->create('a.txt', 1), 'Floor plans');

    expect($upload->path)->toStartWith('media/products/'.$product->id.'/floor-plans/2026-01-05/');

    config()->set('uploads.path_prefix', '');
    config()->set('uploads.path_pattern', '{module}/{id}/{collection}');
    $loose = app(StoreUpload::class)->handle(UploadedFile::fake()->create('a.txt', 1), 'attachments');

    expect($loose->path)->toBe('attachments/attachments/a.txt');
});

it('gives a random lower-case name of the configured length', function () {
    Queue::fake();
    config()->set('uploads.file_names.strategy', 'random');
    config()->set('uploads.file_names.max_length', 12);
    $product = $this->createProduct();

    $upload = $product->addUpload(UploadedFile::fake()->create('Offerte.PDF', 1));

    expect(basename($upload->path))->toMatch('/^[a-z0-9]{12}\.pdf$/')
        ->and($upload->name)->toBe('Offerte.PDF');

    config()->set('uploads.file_names.max_length', 3);

    expect(basename($product->addUpload(UploadedFile::fake()->create('a.txt', 1))->path))
        ->toMatch('/^[a-z0-9]{8}\.txt$/');
});

it('keeps the original name as a slug, cut to the maximum length', function () {
    Queue::fake();
    $product = $this->createProduct();

    $short = $product->addUpload(UploadedFile::fake()->create('Foto gevel.JPG', 1));
    $long = $product->addUpload(UploadedFile::fake()->create('Plattegrond begane grond versie 3.pdf', 1));
    $foreign = $product->addUpload(UploadedFile::fake()->create('写真.png', 1));

    expect(basename($short->path))->toBe('foto-gevel.jpg')
        ->and(basename($long->path))->toBe('plattegrond-begane.pdf')
        ->and(basename($foreign->path))->toMatch('/^[a-z0-9]{20}\.png$/')
        ->and($short->name)->toBe('Foto gevel.JPG');
});

it('numbers an original name that is taken in the folder, with any extension or in the trash', function () {
    Queue::fake();
    config()->set('uploads.file_names.max_length', 10);
    $product = $this->createProduct();

    $first = $product->addUpload(UploadedFile::fake()->create('schedule.jpg', 1));
    $other = $product->addUpload(UploadedFile::fake()->create('schedule.png', 1));
    $first->delete();
    $third = $product->addUpload(UploadedFile::fake()->create('schedule.jpg', 1));
    $long = $product->addUpload(UploadedFile::fake()->create('appointments.jpg', 1));
    $longAgain = $product->addUpload(UploadedFile::fake()->create('appointments.jpg', 1));

    expect(basename($first->path))->toBe('schedule.jpg')
        ->and(basename($other->path))->toBe('schedule-2.png')
        ->and(basename($third->path))->toBe('schedule-3.jpg')
        ->and(basename($long->path))->toBe('appointmen.jpg')
        ->and(basename($longAgain->path))->toBe('appointm-2.jpg');

    Storage::disk('local')->assertExists($first->path);
});

it('numbers an original name when a file of that name is already on the disk', function () {
    Queue::fake();
    $this->travelTo(now()->setDate(2026, 11, 2));
    Storage::disk('local')->put('uploads/products/2026/11/legacy.pdf', 'old');
    $product = $this->createProduct();

    $upload = $product->addUpload(UploadedFile::fake()->create('legacy.pdf', 1));

    expect($upload->path)->toBe('uploads/products/2026/11/legacy-2.pdf');
    expect(Storage::disk('local')->get('uploads/products/2026/11/legacy.pdf'))->toBe('old');
});

it('never gives an original the name of a generated file', function () {
    Queue::fake();
    $product = $this->createProduct();

    $thumb = $product->addUpload(UploadedFile::fake()->create('logo-thumb.webp', 1));
    $page = $product->addUpload(UploadedFile::fake()->create('manual-page-2.jpg', 1));

    expect(basename($thumb->path))->toBe('logo-thumb-2.webp')
        ->and(basename($page->path))->toBe('manual-page-2-2.jpg');
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
})->skip(fn () => ! $this->pdfRenderingWorks(), 'No working PDF driver on this machine.');

it('limits the rendered pages to max_pages', function () {
    config()->set('uploads.pdf.max_pages', 1);
    $product = $this->createProduct();

    $upload = $product->addUpload($this->fakePdf('manual.pdf', 3), 'documents')->fresh();

    expect($upload->pageCount())->toBe(1);
})->skip(fn () => ! $this->pdfRenderingWorks(), 'No working PDF driver on this machine.');

it('fails the job for a PDF when no driver is available', function () {
    config()->set('uploads.pdf.driver', 'pdftoppm');
    config()->set('uploads.pdf.pdftoppm_path', '/nonexistent/pdftoppm');
    $product = $this->createProduct();

    $product->addUpload($this->fakePdf(), 'documents');
})->throws(PdfRenderingUnavailable::class);

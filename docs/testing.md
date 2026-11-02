---
title: "Testing"
description: "Test your own code around uploads: fake the disks, run the conversions in the test or fake the queue, make a real image with GD, assert on conversions and signed links, and test the Livewire components."
nav_order: 12
---

# Testing

## Fake the disks and decide about the job

```php
use Darvis\Uploads\Jobs\ProcessUpload;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
});
```

With the queue connection on `sync` (the default in Laravel's `phpunit.xml`) the job runs inside `addUpload()` and the conversions exist right after it. When a test does not care about conversions, `Queue::fake()` skips the job and keeps the test fast; `Queue::assertPushed(ProcessUpload::class)` then proves it was dispatched.

## A real image

`UploadedFile::fake()->image('photo.png', 800, 600)` draws a real PNG with GD, which the converter can resize. `UploadedFile::fake()->create('a.png', 10)` makes a file of zeros that the job cannot decode, so use `image()` for everything that goes through the conversions.

```php
it('makes a thumbnail of a product photo', function () {
    $product = Product::factory()->create();

    $upload = $product->addUpload(UploadedFile::fake()->image('photo.png', 800, 600), 'photos', public: true)->fresh();

    expect($upload->isProcessed())->toBeTrue()
        ->and($upload->hasConversion('thumb'))->toBeTrue();

    Storage::disk('public')->assertExists($upload->path);
    Storage::disk('public')->assertExists($upload->conversionPath('thumb'));

    [$width, $height] = getimagesizefromstring(Storage::disk('public')->get($upload->conversionPath('thumb')));
    expect($width)->toBe(300)->and($height)->toBe(300);
});
```

Call `fresh()` after `addUpload()` when the job ran synchronously: the job updates its own copy of the model.

## Rows without a file

When a test only needs the row, for example to check an ordering or a policy, `Upload::factory()` makes one without writing a file. It uses the disks from the config; the states are `public()`, `pdf()`, `video()` and `processed()`:

```php
use Darvis\Uploads\Models\Upload;

$photo = Upload::factory()->for($product, 'uploadable')->public()->processed()->create(['collection' => 'photos']);
```

Use `addUpload()` with a fake file when the test needs the file on the disk or its conversions.

## Private links

```php
it('lets the customer open the attachment of their own inquiry', function () {
    $customer = User::factory()->create();
    $inquiry = Inquiry::factory()->for($customer)->create();
    $upload = $inquiry->addUpload(UploadedFile::fake()->create('order.3mf', 10), 'attachments');

    $this->actingAs($customer)->get($upload->url())->assertOk();
    $this->actingAs(User::factory()->create())->get($upload->url())->assertForbidden();

    $this->travel(31)->minutes();
    $this->actingAs($customer)->get($upload->url())->assertForbidden();
});
```

A link without a signature, `route('uploads.show', $upload)`, is a 403 from the `signed` middleware. `downloadUrl()` answers with `Content-Disposition: attachment`, so `assertDownload('order.3mf')` works on it.

## PDF pages

The job needs Imagick or `pdftoppm` to render pages. Skip those tests on a machine without either:

```php
it('renders the pages of a manual', function () {
    // …
})->skip(fn () => ! app(\Darvis\Uploads\Processing\PdfRasterizer::class)->isAvailable(), 'No PDF driver');
```

## The Livewire components

```php
use Darvis\Uploads\Livewire\UploadManager;
use Livewire\Livewire;

Livewire::test(UploadManager::class, ['model' => $product, 'collection' => 'photos', 'public' => true])
    ->set('files', [UploadedFile::fake()->image('one.png', 400, 300)])
    ->call('save')
    ->assertHasNoErrors()
    ->assertSee('one.png');
```

Livewire's test helper needs `UploadedFile::fake()` instances for `set('files', …)`; a plain `UploadedFile` fails with "Undefined property: $name". A file above `max_kilobytes` or outside `accept` gives `assertHasErrors(['files.0'])`.

## The package's own suite

```bash
composer test       # Pest on Orchestra Testbench, in-memory SQLite, fake disks
composer lint       # Pint, check only; composer format fixes
composer analyse    # Larastan, level 8
```

The suite builds its PDFs by hand and its images with GD, so it has no fixtures on disk and no PDF library. The PDF rendering tests skip themselves without a driver, which is the case in the shared CI workflow; run them locally with `poppler-utils` installed.

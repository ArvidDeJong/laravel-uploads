<?php

use Darvis\Uploads\Models\Upload;
use Darvis\Uploads\Processing\PdfRasterizer;
use Darvis\Uploads\Tests\Fixtures\OpenUploadPolicy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

it('gives a public upload the permanent URL of the disk', function () {
    $product = $this->createProduct();
    $upload = $product->addUpload($this->fakeImage(), 'photos', public: true)->fresh();

    expect($upload->url())->toBe(Storage::disk('public')->url($upload->path))
        ->and($upload->conversionUrl('thumb'))->toBe(Storage::disk('public')->url($upload->conversionPath('thumb')))
        ->and($upload->url())->not->toContain('signature=');
});

it('gives a private upload a signed link that expires', function () {
    $this->actingAs($this->createUser());
    $product = $this->createProduct();
    $upload = $product->addUpload(UploadedFile::fake()->create('secret.txt', 1, 'text/plain'))->fresh();

    $url = $upload->url();

    expect($url)->toContain('/uploads/'.$upload->id)->toContain('signature=');

    $this->get($url)->assertOk();

    $this->travel(31)->minutes();

    $this->get($url)->assertForbidden();
});

it('serves a private upload to the uploader, the owner of the model and an admin, and nobody else', function () {
    $uploader = $this->createUser();
    $owner = $this->createUser();
    $stranger = $this->createUser();
    $product = $this->createProduct(['user_id' => $owner->id]);

    $this->actingAs($uploader);
    $upload = $product->addUpload(UploadedFile::fake()->create('secret.txt', 1, 'text/plain'))->fresh();
    auth()->logout();

    $url = $upload->url();

    $this->get($url)->assertForbidden();
    $this->actingAs($stranger)->get($url)->assertForbidden();
    $this->actingAs($uploader)->get($url)->assertOk()->assertHeader('content-disposition', 'inline; filename=secret.txt');
    $this->actingAs($uploader)->get($upload->downloadUrl())->assertOk()->assertDownload('secret.txt');
    $this->actingAs($owner)->get($url)->assertOk();
});

it('refuses a link without a signature', function () {
    $product = $this->createProduct();
    $upload = $product->addUpload(UploadedFile::fake()->create('secret.txt', 1))->fresh();

    $this->actingAs($this->createUser())->get(route('uploads.show', $upload))->assertForbidden();
});

it('serves a conversion and a page of a private upload through the same route', function () {
    $owner = $this->createUser();
    $this->actingAs($owner);
    $product = $this->createProduct();
    $upload = $product->addUpload($this->fakeImage(), 'photos')->fresh();

    $this->get($upload->conversionUrl('thumb'))->assertOk()->assertHeader('content-type', 'image/webp');

    $missing = URL::temporarySignedRoute('uploads.show', now()->addMinute(), ['upload' => $upload->id, 'conversion' => 'nope']);
    $this->get($missing)->assertNotFound();

    expect($upload->pageUrl(1))->toBeNull();
});

it('serves the rendered pages of a private PDF', function () {
    $this->actingAs($this->createUser());
    $product = $this->createProduct();
    $upload = $product->addUpload($this->fakePdf('manual.pdf', 2), 'documents')->fresh();

    $this->get($upload->pageUrl(2))->assertOk()->assertHeader('content-type', 'image/jpeg');
    expect($upload->pageUrl(3))->toBeNull();
})->skip(fn () => ! app(PdfRasterizer::class)->isAvailable(), 'No PDF driver on this machine.');

it('answers 404 for an upload in the trash', function () {
    $this->actingAs($this->createUser());
    $product = $this->createProduct();
    $upload = $product->addUpload(UploadedFile::fake()->create('secret.txt', 1))->fresh();
    $url = $upload->url();

    $upload->delete();

    $this->get($url)->assertNotFound();
});

it('lets the host app replace the policy', function () {
    Gate::policy(Upload::class, OpenUploadPolicy::class);

    $product = $this->createProduct();
    $upload = $product->addUpload(UploadedFile::fake()->create('open.txt', 1))->fresh();

    $this->get($upload->url())->assertOk();
});

it('falls back to the original while a conversion does not exist yet', function () {
    Queue::fake();
    $product = $this->createProduct();
    $upload = $product->addUpload($this->fakeImage(), 'photos', public: true);

    expect($upload->isProcessed())->toBeFalse()
        ->and($upload->thumbnailUrl())->toBe($upload->url());
});

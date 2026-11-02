<?php

use Darvis\Uploads\Models\Upload;

it('makes upload rows for tests without writing a file', function () {
    $product = $this->createProduct();

    $private = Upload::factory()->for($product, 'uploadable')->create();
    $photo = Upload::factory()->for($product, 'uploadable')->public()->processed()->create(['collection' => 'photos']);

    expect($private->disk)->toBe('local')
        ->and($private->is_public)->toBeFalse()
        ->and($private->isImage())->toBeTrue()
        ->and($photo->disk)->toBe('public')
        ->and($photo->isProcessed())->toBeTrue()
        ->and($product->uploadsIn('photos')->sole()->is($photo))->toBeTrue();
});

it('makes videos and PDFs and finds videos with their scope', function () {
    $video = Upload::factory()->video()->create();
    $pdf = Upload::factory()->pdf()->create();

    expect($video->path)->toEndWith('.mp4')
        ->and($video->isVideo())->toBeTrue()
        ->and($pdf->name)->toEndWith('.pdf')
        ->and($pdf->isPdf())->toBeTrue()
        ->and(Upload::videos()->pluck('id')->all())->toBe([$video->id]);
});

<?php

namespace Darvis\Uploads\Database\Factories;

use Darvis\Uploads\Models\Upload;
use Darvis\Uploads\Support\UploadsConfig;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Rows for tests that do not need a real file. The factory writes nothing to a disk; use
 * `addUpload(UploadedFile::fake()->image(...))` when a test needs the file or its conversions.
 *
 * Upload::factory()->for($product, 'uploadable')->public()->create(['collection' => 'photos']);
 *
 * @extends Factory<Upload>
 */
class UploadFactory extends Factory
{
    protected $model = Upload::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::slug(implode(' ', (array) fake()->words(2)));

        return [
            'collection' => 'files',
            'disk' => app(UploadsConfig::class)->privateDisk(),
            'path' => 'uploads/files/'.now()->format('Y/m').'/'.$name.'-'.Str::lower(Str::random(6)).'.jpg',
            'name' => $name.'.jpg',
            'mime_type' => 'image/jpeg',
            'size' => fake()->numberBetween(10_000, 2_000_000),
            'is_public' => false,
            'sort_order' => 0,
        ];
    }

    /**
     * On the public disk, with a permanent URL.
     */
    public function public(): static
    {
        return $this->state(fn (): array => [
            'disk' => app(UploadsConfig::class)->publicDisk(),
            'is_public' => true,
        ]);
    }

    /**
     * A PDF instead of an image.
     */
    public function pdf(): static
    {
        return $this->withExtension('pdf', 'application/pdf');
    }

    /**
     * An mp4 video instead of an image.
     */
    public function video(): static
    {
        return $this->withExtension('mp4', 'video/mp4');
    }

    /**
     * As if the processing job has run, without conversions.
     */
    public function processed(): static
    {
        return $this->state(fn (): array => ['processed_at' => now()]);
    }

    protected function withExtension(string $extension, string $mimeType): static
    {
        return $this->state(fn (array $attributes): array => [
            'path' => preg_replace('/\.[^.\/]+$/', '.'.$extension, (string) $attributes['path']),
            'name' => preg_replace('/\.[^.\/]+$/', '.'.$extension, (string) $attributes['name']),
            'mime_type' => $mimeType,
        ]);
    }
}

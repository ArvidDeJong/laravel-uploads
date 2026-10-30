<?php

namespace Darvis\Uploads\Tests;

use Darvis\Uploads\Tests\Fixtures\Product;
use Darvis\Uploads\Tests\Fixtures\User;
use Darvis\Uploads\UploadsServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\Concerns\WithLaravelMigrations;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    // The policy and the overview look at the host app's users table, which Laravel's own migrations create.
    use WithLaravelMigrations;

    protected function getPackageProviders($app): array
    {
        return [LivewireServiceProvider::class, UploadsServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('queue.default', 'sync');
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Every test writes to fake disks, never to the real storage folder.
        Storage::fake('public');
        Storage::fake('local');
    }

    public function createUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Test user',
            'email' => uniqid('user').'@example.com',
            'password' => 'secret',
        ], $attributes));
    }

    public function createProduct(array $attributes = []): Product
    {
        return Product::create(array_merge(['name' => 'Fidget ball'], $attributes));
    }

    /**
     * A real PNG of the given size, so the converter has something to resize.
     */
    public function fakeImage(string $name = 'photo.png', int $width = 800, int $height = 600): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 120, 200));
        imagefilledrectangle($image, 10, 10, (int) ($width / 2), (int) ($height / 2), imagecolorallocate($image, 250, 200, 40));

        $path = tempnam(sys_get_temp_dir(), 'uploads-test').'.png';
        imagepng($image, $path);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    /**
     * A small valid PDF with the given number of pages, built by hand so no PDF library is needed.
     */
    public function fakePdf(string $name = 'manual.pdf', int $pages = 2): UploadedFile
    {
        $objects = [];
        $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';

        $kids = [];
        for ($i = 0; $i < $pages; $i++) {
            $kids[] = (3 + $i * 2).' 0 R';
        }
        $objects[] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$pages.' >>';

        for ($i = 1; $i <= $pages; $i++) {
            $pageObject = 1 + $i * 2;
            $contentObject = $pageObject + 1;
            $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 200] /Contents {$contentObject} 0 R >>";
            $stream = '0 0 1 rg 20 20 120 80 re f';
            $objects[] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";
        }

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$body}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf('%010d 00000 n ', $offset)."\n";
        }
        $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        $path = tempnam(sys_get_temp_dir(), 'uploads-test').'.pdf';
        file_put_contents($path, $pdf);

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }
}

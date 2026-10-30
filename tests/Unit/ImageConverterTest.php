<?php

use Darvis\Uploads\Exceptions\PdfRenderingUnavailable;
use Darvis\Uploads\Processing\ImageConverter;
use Darvis\Uploads\Processing\PdfRasterizer;
use Darvis\Uploads\Support\UploadsConfig;
use Darvis\Uploads\Tests\Fixtures\User;
use Symfony\Component\Process\ExecutableFinder;

it('covers, contains and encodes in the asked format', function () {
    $source = $this->fakeImage('photo.png', 800, 600)->getRealPath();
    $converter = new ImageConverter;

    [$binary, $extension] = $converter->make($source, ['width' => 200, 'height' => 200, 'fit' => 'cover', 'format' => 'webp']);
    $info = getimagesizefromstring($binary);
    expect($extension)->toBe('webp')->and($info[0])->toBe(200)->and($info[1])->toBe(200)->and($info[2])->toBe(IMAGETYPE_WEBP);

    [$binary, $extension] = $converter->make($source, ['width' => 400, 'fit' => 'contain', 'format' => 'jpeg']);
    $info = getimagesizefromstring($binary);
    expect($extension)->toBe('jpg')->and($info[0])->toBe(400)->and($info[1])->toBe(300)->and($info[2])->toBe(IMAGETYPE_JPEG);

    // A thumbnail never enlarges a small original.
    [$binary] = $converter->make($source, ['width' => 2000, 'height' => 2000, 'fit' => 'contain', 'format' => 'png']);
    $info = getimagesizefromstring($binary);
    expect($info[0])->toBe(800)->and($info[1])->toBe(600)->and($info[2])->toBe(IMAGETYPE_PNG);

    // `cover` with one side behaves like `contain`.
    [$binary] = $converter->make($source, ['height' => 150, 'fit' => 'cover', 'format' => 'jpg']);
    $info = getimagesizefromstring($binary);
    expect($info[0])->toBe(200)->and($info[1])->toBe(150);
});

it('refuses a format it cannot write', function () {
    (new ImageConverter)->make($this->fakeImage()->getRealPath(), ['format' => 'gif']);
})->throws(InvalidArgumentException::class, 'Conversion format [gif] is not supported');

it('picks the PDF driver from the config', function () {
    config()->set('uploads.pdf.driver', 'pdftoppm');
    config()->set('uploads.pdf.pdftoppm_path', '/nonexistent/pdftoppm');

    expect((new PdfRasterizer(app(UploadsConfig::class)))->driver())->toBeNull();

    config()->set('uploads.pdf.driver', 'imagick');
    expect((new PdfRasterizer(app(UploadsConfig::class)))->driver())->toBe(extension_loaded('imagick') ? 'imagick' : null);

    config()->set('uploads.pdf.max_pages', 0);
    config()->set('uploads.pdf.driver', 'auto');
    expect((new PdfRasterizer(app(UploadsConfig::class)))->render('/nowhere.pdf', sys_get_temp_dir(), 0))->toBe([]);
});

/**
 * A rasterizer whose Imagick refuses PDF files, as on Debian and Ubuntu where ImageMagick's
 * policy forbids PDF or Ghostscript is missing.
 */
function rasterizerWithBrokenImagick(): PdfRasterizer
{
    return new class(app(UploadsConfig::class)) extends PdfRasterizer
    {
        public function driver(): ?string
        {
            return $this->config->pdfDriver() === 'pdftoppm' ? parent::driver() : 'imagick';
        }

        protected function renderWithImagick(string $pdfPath, string $directory, int $maxPages): array
        {
            throw new ImagickException('attempt to perform an operation not allowed by the security policy `PDF\'');
        }
    };
}

it('falls back to pdftoppm when Imagick cannot read the PDF', function () {
    $directory = sys_get_temp_dir().'/uploads-fallback-'.uniqid();
    mkdir($directory);

    $pages = rasterizerWithBrokenImagick()->render((string) $this->fakePdf('manual.pdf', 2)->getRealPath(), $directory, 5);

    expect($pages)->toHaveCount(2)->and($pages[0])->toEndWith('.jpg');

    array_map('unlink', glob($directory.'/*') ?: []);
    rmdir($directory);
})->skip(fn () => ! class_exists(ImagickException::class) || ! is_executable((string) (new ExecutableFinder)->find('pdftoppm')), 'Needs the Imagick extension and pdftoppm.');

it('explains why when Imagick cannot read the PDF and there is no pdftoppm', function () {
    config()->set('uploads.pdf.pdftoppm_path', '/nonexistent/pdftoppm');

    rasterizerWithBrokenImagick()->render((string) $this->fakePdf()->getRealPath(), sys_get_temp_dir(), 5);
})->throws(PdfRenderingUnavailable::class, 'Imagick could not read the PDF')
    ->skip(fn () => ! class_exists(ImagickException::class), 'Needs the Imagick extension.');

it('reads every config key through UploadsConfig', function () {
    $config = app(UploadsConfig::class);

    expect($config->conversions())->toHaveKeys(['thumb', 'preview'])
        ->and($config->conversion('thumb'))->toBe(['fit' => 'cover', 'format' => 'webp', 'height' => 300, 'quality' => 80, 'width' => 300])
        ->and($config->conversion('nope'))->toBeNull()
        ->and($config->privateDisk())->toBe('local')
        ->and($config->publicDisk())->toBe('public')
        ->and($config->diskFor(true))->toBe('public')
        ->and($config->linkExpiresAfterMinutes())->toBe(30)
        ->and($config->maxKilobytes())->toBe(512000)
        ->and($config->pathPrefix())->toBe('')
        ->and($config->pdfDpi())->toBe(110)
        ->and($config->pdfDriver())->toBe('auto')
        ->and($config->pdfFormat())->toBe('jpg')
        ->and($config->pdfMaxPages())->toBe(50)
        ->and($config->pdftoppmPath())->toBe('pdftoppm')
        ->and($config->pdfQuality())->toBe(85)
        ->and($config->pruneAfterDays())->toBe(30)
        ->and($config->queueConnection())->toBeNull()
        ->and($config->queueName())->toBeNull()
        ->and($config->routeMiddleware())->toBe(['web'])
        ->and($config->routePrefix())->toBe('uploads')
        ->and($config->temporaryUrlMinutes())->toBe(5)
        ->and($config->userModel())->toBe(User::class);
});

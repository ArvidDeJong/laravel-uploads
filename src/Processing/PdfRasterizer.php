<?php

namespace Darvis\Uploads\Processing;

use Darvis\Uploads\Exceptions\PdfRenderingUnavailable;
use Darvis\Uploads\Support\UploadsConfig;
use Imagick;
use ImagickException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Render the pages of a PDF to image files, with Imagick or the pdftoppm binary.
 */
class PdfRasterizer
{
    public function __construct(
        protected UploadsConfig $config,
    ) {}

    /**
     * The driver that will be used: `imagick`, `pdftoppm`, or null when none is available.
     */
    public function driver(): ?string
    {
        $wanted = $this->config->pdfDriver();

        if (in_array($wanted, ['imagick', 'auto'], true) && extension_loaded('imagick') && class_exists(Imagick::class)) {
            return 'imagick';
        }

        if (in_array($wanted, ['pdftoppm', 'auto'], true) && $this->pdftoppm() !== null) {
            return 'pdftoppm';
        }

        return null;
    }

    public function isAvailable(): bool
    {
        return $this->driver() !== null;
    }

    /**
     * Render up to `$maxPages` pages of a local PDF into `$directory`.
     *
     * @return array<int, string> Local paths of the rendered pages, in page order.
     *
     * @throws PdfRenderingUnavailable
     */
    public function render(string $pdfPath, string $directory, int $maxPages): array
    {
        if ($maxPages < 1) {
            return [];
        }

        return match ($this->driver()) {
            'imagick' => $this->renderWithImagickOrFallBack($pdfPath, $directory, $maxPages),
            'pdftoppm' => $this->renderWithPdftoppm($pdfPath, $directory, $maxPages),
            default => throw new PdfRenderingUnavailable(
                'No PDF driver is available: install the Imagick extension with Ghostscript, or poppler-utils for the pdftoppm binary.'
            ),
        };
    }

    /**
     * Imagick can be loaded and still refuse PDF files: Ghostscript is missing, or ImageMagick's
     * policy forbids PDF, which is the default on Debian and Ubuntu. With `auto` the pdftoppm
     * binary takes over when it is there.
     *
     * @return array<int, string>
     *
     * @throws PdfRenderingUnavailable
     */
    protected function renderWithImagickOrFallBack(string $pdfPath, string $directory, int $maxPages): array
    {
        try {
            return $this->renderWithImagick($pdfPath, $directory, $maxPages);
        } catch (ImagickException $exception) {
            if ($this->config->pdfDriver() === 'auto' && $this->pdftoppm() !== null) {
                return $this->renderWithPdftoppm($pdfPath, $directory, $maxPages);
            }

            throw new PdfRenderingUnavailable(
                'Imagick could not read the PDF ('.$exception->getMessage().'). Ghostscript is missing or ImageMagick\'s policy forbids PDF: '
                .'install poppler-utils for the pdftoppm binary, or allow PDF in ImageMagick\'s policy.xml.',
                previous: $exception,
            );
        }
    }

    /**
     * @return array<int, string>
     */
    protected function renderWithImagick(string $pdfPath, string $directory, int $maxPages): array
    {
        $format = $this->config->pdfFormat();
        $imagick = new Imagick;
        $imagick->setResolution($this->config->pdfDpi(), $this->config->pdfDpi());
        $imagick->readImage($pdfPath);

        $paths = [];
        $count = min($imagick->getNumberImages(), $maxPages);

        for ($index = 0; $index < $count; $index++) {
            $imagick->setIteratorIndex($index);
            $page = $imagick->getImage();
            $page->setImageBackgroundColor('white');
            $page->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
            $page->setImageFormat($format === 'png' ? 'png' : 'jpeg');
            $page->setImageCompressionQuality($this->config->pdfQuality());

            $path = $directory.'/page-'.($index + 1).'.'.$format;
            $page->writeImage($path);
            $page->clear();

            $paths[] = $path;
        }

        $imagick->clear();

        return $paths;
    }

    /**
     * @return array<int, string>
     */
    protected function renderWithPdftoppm(string $pdfPath, string $directory, int $maxPages): array
    {
        $format = $this->config->pdfFormat();
        $prefix = $directory.'/page';

        $command = [
            (string) $this->pdftoppm(),
            '-r', (string) $this->config->pdfDpi(),
            '-l', (string) $maxPages,
            $format === 'png' ? '-png' : '-jpeg',
        ];

        if ($format !== 'png') {
            $command[] = '-jpegopt';
            $command[] = 'quality='.$this->config->pdfQuality();
        }

        $command[] = $pdfPath;
        $command[] = $prefix;

        $process = new Process($command);
        $process->setTimeout(300);
        $process->mustRun();

        // pdftoppm numbers the files with leading zeros when the PDF has ten pages or more.
        $paths = glob($prefix.'-*.'.$format) ?: [];
        natsort($paths);

        return array_values($paths);
    }

    protected function pdftoppm(): ?string
    {
        $path = $this->config->pdftoppmPath();

        if (str_contains($path, '/') || str_contains($path, '\\')) {
            return is_executable($path) ? $path : null;
        }

        return (new ExecutableFinder)->find($path);
    }
}

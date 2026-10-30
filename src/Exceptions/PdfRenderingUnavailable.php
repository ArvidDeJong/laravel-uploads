<?php

namespace Darvis\Uploads\Exceptions;

use RuntimeException;

/**
 * Thrown when no PDF driver is available: neither the Imagick extension nor the pdftoppm binary.
 */
class PdfRenderingUnavailable extends RuntimeException {}

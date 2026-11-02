<?php

namespace Darvis\Uploads\Exceptions;

use RuntimeException;

/**
 * Thrown when an image or PDF cannot be read because the file itself is damaged or empty.
 * The processing job catches it: a retry would fail again, so the upload keeps its original.
 */
class UnreadableFile extends RuntimeException {}

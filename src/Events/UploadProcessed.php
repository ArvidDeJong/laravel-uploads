<?php

namespace Darvis\Uploads\Events;

use Darvis\Uploads\Models\Upload;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched once the conversions and PDF pages of an upload have been generated.
 */
class UploadProcessed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Upload $upload,
    ) {}
}

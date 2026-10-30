<?php

namespace Darvis\Uploads\Tests\Fixtures;

use Darvis\Uploads\Models\Upload;
use Darvis\Uploads\Policies\UploadPolicy;
use Illuminate\Contracts\Auth\Authenticatable;

class OpenUploadPolicy extends UploadPolicy
{
    public function view(?Authenticatable $user, Upload $upload): bool
    {
        return true;
    }
}

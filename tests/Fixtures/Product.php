<?php

namespace Darvis\Uploads\Tests\Fixtures;

use Darvis\Uploads\Contracts\UploadOwner;
use Darvis\Uploads\Models\Concerns\HasUploads;
use Illuminate\Database\Eloquent\Model;

class Product extends Model implements UploadOwner
{
    use HasUploads;

    protected $guarded = [];
}

<?php

namespace Darvis\Uploads\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;

class ClosedProductPolicy
{
    public function update(?Authenticatable $user, Product $product): bool
    {
        return false;
    }
}

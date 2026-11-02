<?php

namespace Darvis\Uploads\Tests\Fixtures;

/**
 * An owner that names its own upload folder, on the products table.
 */
class NewsItem extends Product
{
    protected $table = 'products';

    public function uploadsModule()
    {
        return 'news';
    }
}

---
title: "Tutorial: your first upload"
description: "Step by step for developers new to Laravel: from a fresh Laravel app to a page where you upload product photos, see thumbnails and remove a photo again."
nav_order: 2
---

# Tutorial: your first upload

In this tutorial you build one page: the photos of a product. On that page you pick a photo, add a caption and press Upload. The page then shows a small thumbnail of every photo, links it to a larger version and has a button to remove it.

It takes about twenty minutes. Every step is something you type or paste; nothing is left out. When a step uses a Laravel feature, a sentence says what it does, so you can follow along even when this is your first Laravel project. [How it works](how-it-works.md) explains the ideas behind the package in more detail.

## What you need

- **PHP 8.2 or higher with the GD extension.** GD is the part of PHP that resizes images. Check it in a terminal:

  ```bash
  php -v
  php -m
  ```

  The first line of `php -v` shows the version; `php -m` lists the extensions, and `gd` has to be in that list.
- **Composer**, the package manager of PHP. `composer --version` should print a version.
- **A Laravel application.** Start a new one if you do not have one yet:

  ```bash
  composer create-project laravel/laravel shop
  cd shop
  ```

  A new Laravel application uses SQLite, a database that is a single file in `database/`, so there is no database server to set up.

From here on every command runs in the folder of your application.

## Step 1. Install the package

```bash
composer require darvis/laravel-uploads
php artisan migrate
php artisan storage:link
```

- `composer require` downloads the package. Laravel finds it by itself; there is nothing to register.
- `php artisan migrate` creates the `uploads` table. Every file you upload becomes one row in that table.
- `php artisan storage:link` makes the folder `storage/app/public` reachable from the browser as `/storage`. Photos that everyone may see are stored there.

Now open the file `.env` in the root of your application and add this line at the bottom:

```text
UPLOADS_QUEUE_CONNECTION=sync
```

After an upload the package makes smaller versions of the photo, the *conversions*. Normally that happens in the background, in a *queue*, so the visitor does not wait for it. `sync` means "do it right away, during the upload". That is the easiest setting while you learn; [step 8](#step-8-make-the-thumbnails-in-the-background) switches to the background.

## Step 2. Create the products

A product needs a table and a model. Laravel makes both with one command:

```bash
php artisan make:model Product -m
```

This creates `app/Models/Product.php` and, because of `-m`, a migration in `database/migrations/` whose name ends in `_create_products_table.php`. Open that migration and add a `name` column:

```php
Schema::create('products', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->timestamps();
});
```

Run the migration to create the table:

```bash
php artisan migrate
```

Now open `app/Models/Product.php` and replace its contents with:

```php
<?php

namespace App\Models;

use Darvis\Uploads\Models\Concerns\HasUploads;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasUploads;

    protected $fillable = ['name'];
}
```

`use HasUploads;` is the line that matters: it gives every product the methods to store and read uploads, such as `addUpload()` and `uploadsIn()`. `$fillable` allows the `name` to be filled in when you create a product.

Create a first product. `php artisan tinker` opens a PHP prompt inside your application:

```bash
php artisan tinker
```

```php
App\Models\Product::create(['name' => 'Desk lamp']);
```

Type `exit` to leave tinker. The product has id `1`.

## Step 3. The routes

A route connects an address in the browser to code. Open `routes/web.php` and add these lines at the bottom, and the `use` line at the top under the other `use` line:

```php
use App\Http\Controllers\ProductPhotoController;

Route::get('/products/{product}/photos', [ProductPhotoController::class, 'index'])->name('products.photos.index');
Route::post('/products/{product}/photos', [ProductPhotoController::class, 'store'])->name('products.photos.store');
Route::delete('/products/{product}/photos/{upload}', [ProductPhotoController::class, 'destroy'])->name('products.photos.destroy');
```

Three routes: one shows the page, one receives the form with the photo, and one removes a photo. `{product}` is the id of the product in the address; Laravel looks the product up for you.

## Step 4. The controller

The controller holds the code behind the routes. Create it:

```bash
php artisan make:controller ProductPhotoController
```

Open `app/Http/Controllers/ProductPhotoController.php` and replace its contents with:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductPhotoController extends Controller
{
    public function index(Product $product): View
    {
        return view('products.photos', [
            'product' => $product,
            'photos' => $product->uploadsIn('photos')->get(),
        ]);
    }

    public function store(Request $request, Product $product): RedirectResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'max:10240'],
            'caption' => ['nullable', 'string', 'max:255'],
        ]);

        $product->addUpload($request->file('photo'), 'photos', public: true, meta: [
            'caption' => $request->input('caption'),
        ]);

        return back()->with('status', 'The photo has been uploaded.');
    }

    public function destroy(Product $product, int $upload): RedirectResponse
    {
        $product->uploadsIn('photos')->findOrFail($upload)->delete();

        return back()->with('status', 'The photo has been removed.');
    }
}
```

What each method does:

- **`index`** fetches the photos of this product and passes them to the page. `uploadsIn('photos')` means "the uploads of this product in the collection `photos`"; a collection is just a name you choose to keep different kinds of files apart. `->get()` runs the query and returns the photos, in the order they were added.
- **`store`** first checks the form: a photo is required, it has to be an image and it may be at most 10240 kilobytes (10 MB). When the check fails, Laravel goes back to the page with the error messages by itself. Then `addUpload()` stores the file in the collection `photos`, `public: true` means everyone may see it, and `meta` keeps the caption with the photo.
- **`destroy`** looks the photo up *within this product's photos*, so nobody can remove a photo of another product by changing the number in the address, and moves it to the trash. The files stay until the trash is emptied, see [Administration & trash](administration.md).

## Step 5. The page

Create the folder `resources/views/products` and in it the file `photos.blade.php`:

```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Photos of <?= e($product->name) ?></title>
</head>
<body>
    <h1>Photos of <?= e($product->name) ?></h1>

    @if (session('status'))
        <p><?= e(session('status')) ?></p>
    @endif

    @if ($errors->any())
        <ul>
            @foreach ($errors->all() as $error)
                <li><?= e($error) ?></li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="<?= e(route('products.photos.store', $product)) ?>" enctype="multipart/form-data">
        @csrf
        <input type="file" name="photo" accept="image/*">
        <input type="text" name="caption" placeholder="Caption (optional)">
        <button type="submit">Upload</button>
    </form>

    @foreach ($photos as $photo)
        <figure>
            <a href="<?= e($photo->conversionUrl('preview')) ?>">
                <img src="<?= e($photo->thumbnailUrl()) ?>" alt="<?= e($photo->meta('caption') ?: $photo->name) ?>">
            </a>
            <figcaption><?= e($photo->meta('caption') ?: $photo->name) ?> (<?= e($photo->humanSize()) ?>)</figcaption>

            <form method="POST" action="<?= e(route('products.photos.destroy', [$product, $photo->id])) ?>">
                @csrf
                @method('DELETE')
                <button type="submit">Remove</button>
            </form>
        </figure>
    @endforeach
</body>
</html>
```

{: .note }
In Blade you normally print a value between double curly braces. This documentation site cannot show those braces, so the examples use `<?= e(...) ?>`, which does exactly the same: `e()` escapes the value, so a caption with HTML in it is shown as text instead of run as code. You can paste the examples as they are, or write the double curly braces yourself. Never leave out the `e()`.

Three details in the form that are easy to forget, and each one breaks the upload when it is missing:

- `enctype="multipart/form-data"` tells the browser to send the file itself. Without it only the file name arrives, and the validation says "The photo field must be an image."
- `@csrf` adds a hidden token that proves the form came from your own site. Without it Laravel answers with **419 Page Expired**.
- `@method('DELETE')` on the remove form: a browser can only send GET and POST, so Laravel reads the real method from this hidden field.

On the photo itself:

- `thumbnailUrl()` is the address of the small version (300 × 300 pixels).
- `conversionUrl('preview')` is the address of the larger version (1200 pixels wide).
- `meta('caption')` reads the caption you stored; `?: $photo->name` falls back to the original file name when there is no caption.
- `humanSize()` turns the size into something readable, like `1.2 MB`.

## Step 6. Try it

Start the development server:

```bash
php artisan serve
```

Open [http://localhost:8000/products/1/photos](http://localhost:8000/products/1/photos), pick a photo, type a caption and press **Upload**. The page comes back with "The photo has been uploaded." and a thumbnail. Click the thumbnail to see the larger version, and try **Remove**.

Try the checks as well: press Upload without a photo, or pick a text file. The page shows the error message and stores nothing.

## Step 7. Look at what was stored

Open the folder `storage/app/public/products/1/photos`. For one photo you will find three files:

```text
7131d4ec-e520-4129-b964-05a803fde311.jpg           the original
7131d4ec-e520-4129-b964-05a803fde311-thumb.webp    300 × 300, cropped
7131d4ec-e520-4129-b964-05a803fde311-preview.webp  1200 wide
```

The long name is a random *uuid*, so two files called `photo.jpg` never overwrite each other; the original name is kept in the database. Look at the row in tinker:

```php
$photo = App\Models\Product::find(1)->firstUpload('photos');

$photo->name;          // "lamp.jpg", the original file name
$photo->collection;    // "photos"
$photo->conversions;   // ["thumb" => "products/1/photos/...-thumb.webp", "preview" => "products/1/photos/...-preview.webp"]
$photo->meta('caption'); // the caption, or null when you left it empty
```

`thumb` and `preview` are the two sizes the package makes by default. Add your own in `config/uploads.php`, see [Conversions & PDF pages](conversions-and-pdf.md).

## Step 8. Make the thumbnails in the background

On a real site you do not want a visitor to wait while a large photo is resized. Remove the line `UPLOADS_QUEUE_CONNECTION=sync` from `.env` again. The package now uses the queue of your application (in a new Laravel application that is `database`: the work is stored in the `jobs` table). If `php artisan serve` is running, it restarts by itself when `.env` changes.

Upload another photo. The page shows the photo itself instead of a thumbnail: the thumbnail does not exist yet, and `thumbnailUrl()` falls back to the original so the page never shows a broken image.

Now start a *worker*, the process that does the work from the queue, in a second terminal:

```bash
php artisan queue:work
```

The worker prints `Darvis\Uploads\Jobs\ProcessUpload ... DONE`. Reload the page: the thumbnail is there. On your server the worker has to run all the time; that is a setting of your hosting, see [Laravel's queue documentation](https://laravel.com/docs/queues#running-the-queue-worker).

## Before you put this online

This tutorial page has no login: anyone who knows the address can upload and remove photos. In a real application put the routes behind the `auth` middleware, or check in the controller who may change the product:

```php
Route::middleware('auth')->group(function () {
    // the three routes from step 3
});
```

## Where to go next

- [How it works](how-it-works.md): the ideas behind collections, disks, conversions, the queue and signed links.
- [Private files](private-files.md): files only logged in users may open, such as invoices or print files.
- [Livewire components](livewire.md): a ready made upload manager with captions, drag and drop sorting and remove buttons, instead of the form you built here.
- [Conversions & PDF pages](conversions-and-pdf.md): your own sizes, and a PDF turned into one image per page.
- [Troubleshooting](troubleshooting.md): when something does not work, look up the message there.

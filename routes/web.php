<?php

use Darvis\Uploads\Http\Controllers\UploadController;
use Darvis\Uploads\Support\UploadsConfig;
use Illuminate\Support\Facades\Route;

$config = app(UploadsConfig::class);

Route::middleware(array_merge($config->routeMiddleware(), ['signed']))
    ->prefix($config->routePrefix())
    ->get('/{upload}', UploadController::class)
    ->name('uploads.show');

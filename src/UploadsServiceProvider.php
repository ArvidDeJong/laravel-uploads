<?php

namespace Darvis\Uploads;

use Darvis\Uploads\Commands\PruneUploads;
use Darvis\Uploads\Livewire\UploadManager;
use Darvis\Uploads\Livewire\UploadsOverview;
use Darvis\Uploads\Models\Upload;
use Darvis\Uploads\Policies\UploadPolicy;
use Darvis\Uploads\Processing\ImageConverter;
use Darvis\Uploads\Processing\PdfRasterizer;
use Darvis\Uploads\Support\UploadsConfig;
use Darvis\Uploads\Support\UploadStatistics;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class UploadsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/uploads.php', 'uploads');

        $this->app->singleton(UploadsConfig::class);
        $this->app->singleton(ImageConverter::class);
        $this->app->singleton(PdfRasterizer::class);
        $this->app->singleton(UploadStatistics::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'uploads');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');

        // A host app that registers its own policy for Upload wins, because Gate keeps the last one.
        if (Gate::getPolicyFor(Upload::class) === null) {
            Gate::policy(Upload::class, UploadPolicy::class);
        }

        if (class_exists(Livewire::class)) {
            Livewire::component('uploads.upload-manager', UploadManager::class);
            Livewire::component('uploads.uploads-overview', UploadsOverview::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([PruneUploads::class]);

            $this->publishes([
                __DIR__.'/../config/uploads.php' => config_path('uploads.php'),
            ], 'uploads-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/uploads'),
            ], 'uploads-views');
        }
    }
}

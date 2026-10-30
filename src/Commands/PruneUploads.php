<?php

namespace Darvis\Uploads\Commands;

use Darvis\Uploads\Models\Upload;
use Darvis\Uploads\Support\UploadsConfig;
use Illuminate\Console\Command;

/**
 * Hard delete uploads that have been in the trash longer than `prune_after_days`,
 * which removes their files too. Schedule it daily.
 */
class PruneUploads extends Command
{
    protected $signature = 'uploads:prune {--days= : Override the number of days an upload stays in the trash}';

    protected $description = 'Hard delete soft deleted uploads and their files after the configured number of days';

    public function handle(UploadsConfig $config): int
    {
        $days = $this->option('days');
        $days = is_numeric($days) ? (int) $days : $config->pruneAfterDays();

        $query = Upload::onlyTrashed()->where('deleted_at', '<=', now()->subDays($days));

        $count = 0;

        // One model at a time, so the forceDeleted hook removes the files of each upload.
        $query->chunkById(100, function ($uploads) use (&$count): void {
            foreach ($uploads as $upload) {
                $upload->forceDelete();
                $count++;
            }
        });

        $this->info("Pruned {$count} upload(s) that were in the trash for more than {$days} day(s).");

        return self::SUCCESS;
    }
}

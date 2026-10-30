<?php

namespace Darvis\Uploads\Support;

use Darvis\Uploads\Models\Upload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Totals of files and bytes, for an overview page or a dashboard.
 */
class UploadStatistics
{
    /**
     * One row per disk: `disk`, `files`, `bytes`.
     *
     * @return Collection<int, array{disk: string, files: int, bytes: int}>
     */
    public function perDisk(): Collection
    {
        return $this->grouped('disk')->map(fn (Upload $row): array => [
            'disk' => (string) $row->getAttribute('disk'),
            'files' => (int) $row->getAttribute('files'),
            'bytes' => (int) $row->getAttribute('bytes'),
        ])->values();
    }

    /**
     * One row per collection: `collection`, `files`, `bytes`.
     *
     * @return Collection<int, array{collection: string, files: int, bytes: int}>
     */
    public function perCollection(): Collection
    {
        return $this->grouped('collection')->map(fn (Upload $row): array => [
            'collection' => (string) $row->getAttribute('collection'),
            'files' => (int) $row->getAttribute('files'),
            'bytes' => (int) $row->getAttribute('bytes'),
        ])->values();
    }

    /**
     * Files and bytes over everything that is not in the trash.
     *
     * @return array{files: int, bytes: int}
     */
    public function total(): array
    {
        return $this->sum(Upload::query());
    }

    /**
     * Files and bytes that are soft deleted and waiting for `uploads:prune`.
     *
     * @return array{files: int, bytes: int}
     */
    public function trashed(): array
    {
        return $this->sum(Upload::onlyTrashed());
    }

    /**
     * @param  Builder<Upload>  $query
     * @return array{files: int, bytes: int}
     */
    protected function sum(Builder $query): array
    {
        $row = $query->selectRaw('count(*) as files, coalesce(sum(size), 0) as bytes')->first();

        return [
            'files' => (int) ($row?->getAttribute('files') ?? 0),
            'bytes' => (int) ($row?->getAttribute('bytes') ?? 0),
        ];
    }

    /**
     * @return Collection<int, Upload>
     */
    protected function grouped(string $column): Collection
    {
        return Upload::query()
            ->select($column)
            ->selectRaw('count(*) as files, coalesce(sum(size), 0) as bytes')
            ->groupBy($column)
            ->orderBy($column)
            ->get()
            ->toBase();
    }
}

{{-- The administration of every upload. Publish with --tag=uploads-views to change it. --}}
<div class="space-y-6">
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded border border-zinc-200 bg-white p-3">
            <p class="text-xs uppercase text-zinc-500">{{ __('Total') }}</p>
            <p class="text-lg font-semibold">{{ $total['files'] }} {{ __('files') }}</p>
            <p class="text-sm text-zinc-600">{{ \Illuminate\Support\Number::fileSize($total['bytes'], precision: 1) }}</p>
        </div>
        <div class="rounded border border-zinc-200 bg-white p-3">
            <p class="text-xs uppercase text-zinc-500">{{ __('In the trash') }}</p>
            <p class="text-lg font-semibold">{{ $inTrash['files'] }} {{ __('files') }}</p>
            <p class="text-sm text-zinc-600">{{ \Illuminate\Support\Number::fileSize($inTrash['bytes'], precision: 1) }}</p>
        </div>
        <div class="rounded border border-zinc-200 bg-white p-3">
            <p class="text-xs uppercase text-zinc-500">{{ __('Per disk') }}</p>
            @foreach ($perDisk as $row)
                <p class="text-sm">{{ $row['disk'] }}: {{ $row['files'] }} · {{ \Illuminate\Support\Number::fileSize($row['bytes'], precision: 1) }}</p>
            @endforeach
        </div>
        <div class="rounded border border-zinc-200 bg-white p-3">
            <p class="text-xs uppercase text-zinc-500">{{ __('Per collection') }}</p>
            @foreach ($perCollection as $row)
                <p class="text-sm">{{ $row['collection'] }}: {{ $row['files'] }} · {{ \Illuminate\Support\Number::fileSize($row['bytes'], precision: 1) }}</p>
            @endforeach
        </div>
    </div>

    <div class="flex flex-wrap items-end gap-3">
        <label class="block text-sm">
            <span class="block text-xs text-zinc-500">{{ __('Search') }}</span>
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('File name') }}" class="mt-1 rounded border border-zinc-300 px-2 py-1">
        </label>
        <label class="block text-sm">
            <span class="block text-xs text-zinc-500">{{ __('Collection') }}</span>
            <select wire:model.live="collection" class="mt-1 rounded border border-zinc-300 px-2 py-1">
                <option value="">{{ __('All') }}</option>
                @foreach ($collections as $name)
                    <option value="{{ $name }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>
        <label class="block text-sm">
            <span class="block text-xs text-zinc-500">{{ __('Disk') }}</span>
            <select wire:model.live="disk" class="mt-1 rounded border border-zinc-300 px-2 py-1">
                <option value="">{{ __('All') }}</option>
                @foreach ($disks as $name)
                    <option value="{{ $name }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" wire:model.live="trashed">
            {{ __('Show the trash') }}
        </label>
    </div>

    <div class="overflow-x-auto rounded border border-zinc-200 bg-white">
        <table class="min-w-full text-sm">
            <thead class="bg-zinc-50 text-left text-xs uppercase text-zinc-500">
                <tr>
                    <th class="px-3 py-2">{{ __('File') }}</th>
                    <th class="px-3 py-2">{{ __('Collection') }}</th>
                    <th class="px-3 py-2">{{ __('Belongs to') }}</th>
                    <th class="px-3 py-2">{{ __('Size') }}</th>
                    <th class="px-3 py-2">{{ __('Disk') }}</th>
                    <th class="px-3 py-2">{{ __('Uploaded by') }}</th>
                    <th class="px-3 py-2">{{ __('Date') }}</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse ($uploads as $upload)
                    <tr wire:key="overview-{{ $upload->getKey() }}">
                        <td class="px-3 py-2">
                            <div class="flex items-center gap-2">
                                @if ($upload->isImage() || $upload->isPdf())
                                    <img src="{{ $upload->thumbnailUrl() }}" alt="" class="h-8 w-8 rounded object-cover" loading="lazy">
                                @endif
                                <span class="truncate" title="{{ $upload->name }}">{{ $upload->name }}</span>
                                @unless ($upload->isProcessed())
                                    <span class="text-xs text-amber-600">{{ __('Processing') }}</span>
                                @endunless
                            </div>
                        </td>
                        <td class="px-3 py-2">{{ $upload->collection }}</td>
                        <td class="px-3 py-2">
                            @if ($upload->uploadable)
                                {{ class_basename($upload->uploadable) }} #{{ $upload->uploadable->getKey() }}
                            @else
                                <span class="text-zinc-400">{{ __('Nothing') }}</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">{{ $upload->humanSize() }}</td>
                        <td class="px-3 py-2">{{ $upload->disk }} @if ($upload->is_public)<span class="text-xs text-zinc-500">({{ __('public') }})</span>@endif</td>
                        <td class="px-3 py-2">{{ $upload->user?->getAttribute('name') ?? '' }}</td>
                        <td class="px-3 py-2 whitespace-nowrap">{{ $upload->created_at?->format('d-m-Y H:i') }}</td>
                        <td class="px-3 py-2 whitespace-nowrap text-right text-xs">
                            @if ($trashed)
                                <button type="button" wire:click="restore({{ $upload->getKey() }})" class="text-zinc-700 hover:underline">{{ __('Restore') }}</button>
                                <button type="button" wire:click="forceDelete({{ $upload->getKey() }})" wire:confirm="{{ __('Delete this file for good? The files are removed too.') }}" class="ml-2 text-red-600 hover:underline">{{ __('Delete for good') }}</button>
                            @else
                                <a href="{{ $upload->url() }}" target="_blank" rel="noopener" class="text-zinc-700 underline">{{ __('Open') }}</a>
                                <button type="button" wire:click="delete({{ $upload->getKey() }})" wire:confirm="{{ __('Move this file to the trash?') }}" class="ml-2 text-red-600 hover:underline">{{ __('Remove') }}</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-3 py-6 text-center text-zinc-500">{{ __('No files found.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $uploads->links() }}
</div>

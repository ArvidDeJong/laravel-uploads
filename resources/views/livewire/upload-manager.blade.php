{{-- Upload, caption, sort and remove the uploads of one collection. Publish with --tag=uploads-views to change it. --}}
<div class="space-y-4" @if ($processing) wire:poll.5s @endif>
    <form wire:submit="save" class="flex flex-wrap items-end gap-3">
        <label class="block">
            <span class="block text-sm font-medium">{{ __('Add files') }}</span>
            <input
                type="file"
                multiple
                wire:model="files"
                @if ($accept !== '') accept="{{ collect(explode(',', $accept))->map(fn ($ext) => '.'.trim($ext))->implode(',') }}" @endif
                class="mt-1 block text-sm"
            >
        </label>

        <button type="submit" class="rounded bg-zinc-800 px-3 py-2 text-sm font-medium text-white hover:bg-zinc-700" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="files, save">{{ __('Upload') }}</span>
            <span wire:loading wire:target="files, save">{{ __('Uploading...') }}</span>
        </button>

        @error('files.*')
            <p class="w-full text-sm text-red-600">{{ $message }}</p>
        @enderror
    </form>

    @if ($uploads->isEmpty())
        <p class="text-sm text-zinc-500">{{ __('No files yet.') }}</p>
    @else
        <ul
            class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
            x-sort="$wire.reorder([...$el.querySelectorAll('[x-sort\\:item]')].map(item => item.getAttribute('x-sort:item')))"
        >
            @foreach ($uploads as $upload)
                <li x-sort:item="{{ $upload->getKey() }}" wire:key="upload-{{ $upload->getKey() }}" class="rounded border border-zinc-200 bg-white p-2">
                    <div class="flex aspect-square items-center justify-center overflow-hidden rounded bg-zinc-100">
                        @if ($upload->isImage() || $upload->isPdf())
                            <img src="{{ $upload->thumbnailUrl() }}" alt="{{ $upload->meta('caption', $upload->name) }}" class="h-full w-full object-cover" loading="lazy">
                        @elseif ($upload->isVideo())
                            <video src="{{ $upload->url() }}" class="h-full w-full object-cover" muted playsinline preload="metadata"></video>
                        @else
                            <span class="px-2 text-center text-xs text-zinc-500">{{ strtoupper(pathinfo($upload->name, PATHINFO_EXTENSION)) ?: __('File') }}</span>
                        @endif
                    </div>

                    <div class="mt-2 space-y-1">
                        <p class="truncate text-xs text-zinc-500" title="{{ $upload->name }}">
                            {{ $upload->name }} · {{ $upload->humanSize() }}
                            @unless ($upload->isProcessed())
                                · <span class="text-amber-600">{{ __('Processing') }}</span>
                            @endunless
                            @if ($upload->isPdf() && $upload->pageCount() > 0)
                                · {{ trans_choice('{1} :count page|[2,*] :count pages', $upload->pageCount()) }}
                            @endif
                        </p>

                        <input
                            type="text"
                            wire:model.blur="captions.{{ $upload->getKey() }}"
                            placeholder="{{ __('Caption') }}"
                            class="w-full rounded border border-zinc-300 px-2 py-1 text-sm"
                        >

                        <div class="flex items-center justify-between text-xs">
                            <a href="{{ $upload->url() }}" target="_blank" rel="noopener" class="text-zinc-600 underline">{{ __('Open') }}</a>
                            <button type="button" wire:click="delete({{ $upload->getKey() }})" wire:confirm="{{ __('Remove this file?') }}" class="text-red-600 hover:underline">{{ __('Remove') }}</button>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>

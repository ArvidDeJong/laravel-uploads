{{-- Upload, caption, sort and remove the uploads of one collection. Publish with --tag=uploads-views to change it. --}}
<div
    class="space-y-4"
    @if ($processing) wire:poll.5s @endif
    @if ($crop)
        x-data="{
            cropper: null,
            cropId: null,
            openCrop(id, url, area) {
                if (typeof window.Cropper === 'undefined') {
                    console.error('Cropper.js is not loaded. Check uploads.cropper in config/uploads.php.')

                    return
                }

                this.cropId = id
                this.$refs.cropImage.src = url
                this.$refs.cropDialog.showModal()
                this.cropper = new window.Cropper(this.$refs.cropImage, {
                    aspectRatio: @js($cropRatio) ?? NaN,
                    autoCropArea: 1,
                    checkCrossOrigin: false,
                    checkOrientation: false,
                    data: area ?? undefined,
                    rotatable: false,
                    scalable: false,
                    viewMode: 1,
                    zoomable: false,
                })
            },
            saveCrop() {
                this.$wire.saveCrop(this.cropId, this.cropper.getData(true))
                this.closeCrop()
            },
            resetCrop() {
                this.$wire.resetCrop(this.cropId)
                this.closeCrop()
            },
            closeCrop() {
                this.cropper?.destroy()
                this.cropper = null
                this.cropId = null
                this.$refs.cropDialog.close()
            },
        }"
    @endif
>
    @if ($crop)
        {{-- Loaded once per page. Empty URLs in uploads.cropper mean the app loads Cropper.js itself. --}}
        @assets
            @if ($cropperStyle)
                <link rel="stylesheet" href="{{ $cropperStyle }}">
            @endif
            @if ($cropperScript)
                <script src="{{ $cropperScript }}"></script>
            @endif
        @endassets
    @endif

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

    @error('crop')
        <p class="text-sm text-red-600">{{ $message }}</p>
    @enderror

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
                            @if ($crop && $upload->cropArea() !== null)
                                · {{ __('Cropped') }}
                            @endif
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
                            <span class="flex gap-3">
                                <a href="{{ $upload->url() }}" target="_blank" rel="noopener" class="text-zinc-600 underline">{{ __('Open') }}</a>
                                @if ($crop && $upload->isImage())
                                    <button
                                        type="button"
                                        x-on:click="openCrop({{ $upload->getKey() }}, @js($upload->url()), @js($upload->cropArea()))"
                                        class="text-zinc-600 underline"
                                    >{{ __('Crop') }}</button>
                                @endif
                            </span>
                            <button type="button" wire:click="delete({{ $upload->getKey() }})" wire:confirm="{{ __('Remove this file?') }}" class="text-red-600 hover:underline">{{ __('Remove') }}</button>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($crop)
        {{-- wire:ignore keeps Livewire from redrawing the dialog while Cropper.js works in it. The
             inline margin centres it; space-y-4 on the root would otherwise push it to the top. --}}
        <dialog x-ref="cropDialog" x-on:close="closeCrop()" wire:ignore style="margin: auto" class="w-full max-w-3xl rounded p-4 backdrop:bg-black/60">
            <p class="mb-3 text-sm font-medium">{{ __('Crop the image') }}</p>

            <div class="h-[60vh] bg-zinc-100">
                <img x-ref="cropImage" alt="" class="block max-w-full">
            </div>

            <div class="mt-3 flex flex-wrap justify-end gap-3 text-sm">
                <button type="button" x-on:click="closeCrop()" class="px-3 py-2 text-zinc-600 hover:underline">{{ __('Cancel') }}</button>
                <button type="button" x-on:click="resetCrop()" class="px-3 py-2 text-zinc-600 hover:underline">{{ __('Use the whole image') }}</button>
                <button type="button" x-on:click="saveCrop()" class="rounded bg-zinc-800 px-3 py-2 font-medium text-white hover:bg-zinc-700">{{ __('Save crop') }}</button>
            </div>
        </dialog>
    @endif
</div>

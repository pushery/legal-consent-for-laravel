<div>
    <section aria-labelledby="legal-text-editor-heading">
        <h1 id="legal-text-editor-heading">{{ $key }} — {{ $locale }}</h1>

        {{-- WCAG 4.1.3: the result of Save / Translate / Mark reviewed is announced here. --}}
        <p role="status" aria-live="polite" wire:key="legal-text-editor-status">{{ $status }}</p>

        @if ($stale)
            <p role="alert">The source text changed after this translation was reviewed — review it again before releasing.</p>
        @endif

        {{-- Plain-stub editor: a textarea bound straight to the property. The WireKit variant swaps
             in <x-wirekit::editor> and binds via $wire.set (see the published stub). --}}
        <label for="legal-text-body">Text (sanitized HTML)</label>
        <textarea id="legal-text-body" wire:model="body" rows="20"></textarea>

        <div>
            <button type="button" wire:click="save">Save</button>

            @unless ($isSource)
                <button type="button" wire:click="translate">Translate from {{ $sourceLocale }}</button>
            @endunless

            <button type="button" wire:click="markReviewed">Mark reviewed</button>
        </div>

        <h2>Preview</h2>
        {{-- The preview renders the already-sanitized stored body — the exact bytes a publish
             freezes, so what you see here is what the subject will see and the ledger will prove. --}}
        <div aria-label="Preview of the published text">{!! $preview !!}</div>
    </section>
</div>

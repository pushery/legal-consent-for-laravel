{{-- WireKit-styled twin of the document fragment (publish tag: legal-consent-wirekit).

     Still NO layout and no `<html>`: this is served to a caller that already has a page — a dialog
     on the host's registration form — and a full document inserted there is a document inside a
     document. What the twin adds is the typography, which is the whole reason a WireKit host would
     want it: a legal text is the longest prose this package ever renders, and the plain stub hands
     it over with BEM hooks and nothing behind them.

     `prose` carries the readable-measure clamp, and that is not decoration here. Long-form text
     without a line-length cap runs the full width of whatever dialog holds it, and a privacy notice
     read at 140 characters per line is a text somebody stops reading — on the one screen where the
     package's whole argument is that they should read it. --}}
<x-wirekit::stack gap="md" class="lc-document" lang="{{ $document->locale }}">
    <x-wirekit::stack gap="xs">
        {{-- Left out when the caller asks for `heading=0`: the dialog names the document in its own
             header, and the same title again directly under it reads as a stutter. --}}
        @if ($heading ?? true)
            <x-wirekit::heading :level="1" size="lg">{{ $document->title }}</x-wirekit::heading>
        @endif

        {{-- The VERSION, and it is not a detail. Somebody reading this in a dialog is about to agree
             to it, and which version they read is the fact a ledger row will later claim. Muted
             rather than hidden: it is context, not the text. --}}
        <x-wirekit::text size="sm" intent="muted">
            {{ __('legal-consent::ui.document_version', ['version' => $document->version]) }}
        </x-wirekit::text>

        {{-- Named when the text is not in the language the reader asked for or reads in: a text that
             may fall back is otherwise a German page on an English site with nothing saying why. --}}
        @if (($shownIn ?? null) !== null)
            <x-wirekit::text size="sm" intent="muted">
                {{ __('legal-consent::ui.document_language', ['language' => $shownIn]) }}
            </x-wirekit::text>
        @endif
    </x-wirekit::stack>

    {{-- The sanitizer's output, and the ONLY place this fragment trusts anything.
         `LegalHtmlSanitizer` runs inside the publish pipeline, so what is stored is what survived
         it — this is the sink that class exists to protect, written unescaped on purpose. --}}
    <x-wirekit::prose>{!! $document->html !!}</x-wirekit::prose>
</x-wirekit::stack>

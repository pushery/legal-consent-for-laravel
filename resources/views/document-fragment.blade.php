{{-- The published text of one document, as a fragment a dialog can hold.

     ⚠️ NO LAYOUT, NO `<html>`, NO STYLESHEET, and that is the whole point. This is served to a
     caller that already has a page — a dialog on the host's registration form — and a full document
     inserted there is a document inside a document. Publish this stub (tag `legal-consent-views`)
     and wrap it in whatever the host's own reader uses.

     It carries no CSS for the same reason `consent-checkboxes.blade.php` carries none: the classes
     here are BEM hooks with nothing behind them, so how a legal text reads on a phone stays the
     host's decision rather than this package's guess.

     The VERSION is rendered, and it is not decoration. Somebody reading this in a dialog is about
     to agree to it, and which version they read is the fact a ledger row will later claim. A
     fragment that showed the text and hid the version would make that row unverifiable from the
     reader's side. --}}
<article class="lc-document" lang="{{ $document->locale }}">
    <header class="lc-document__header">
        <h1 class="lc-document__title">{{ $document->title }}</h1>

        <p class="lc-document__version">
            {{ __('legal-consent::ui.document_version', ['version' => $document->version]) }}
        </p>
    </header>

    {{-- The sanitizer's output, and the ONLY place this fragment trusts anything. `LegalHtmlSanitizer`
         runs inside the publish pipeline, so what is stored is what survived it — this is the sink
         that class exists to protect, and it is written unescaped on purpose. --}}
    <div class="lc-document__body">{!! $document->html !!}</div>
</article>

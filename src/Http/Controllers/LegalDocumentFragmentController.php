<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Pushery\LegalConsent\Content\PublishedDocument;
use Pushery\LegalConsent\Contracts\ConsentManager;
use Pushery\LegalConsent\Contracts\NamesLegalTexts;
use Pushery\LegalConsent\Support\DocumentMatrix;

/**
 * The published text of one legal document, as a FRAGMENT rather than as a page.
 *
 * ## What it is for
 *
 * A consent checkbox names its document in the sentence and links it. Following that link navigates
 * away from a half-filled registration form, which punishes the one thing the form wants somebody to
 * do: read before agreeing. A dialog over the form is the answer, and a dialog needs the text —
 * which the checkbox views do not have. They are handed `field`, `wording`, `title`, `url`, `locale`
 * and `required`, and no body.
 *
 * Putting the body in the page instead was measured and rejected: a registration form shows up to
 * four documents, a privacy notice is tens of kilobytes of HTML, and in the normal case the reader
 * opens NONE of them. That is a large, certain cost for an uncommon benefit, on the page whose load
 * time decides whether somebody registers at all.
 *
 * ## Why it is a route rather than a Livewire action
 *
 * The consent checkboxes render inside the HOST's registration form, and nothing says that form is a
 * Livewire component. A route works either way, and it costs the page nothing until somebody asks.
 *
 * ## What it will not serve
 *
 * Only a PUBLISHED document, only under a registered key, and only in a configured locale. A draft
 * is somebody's work in progress and has never been anybody's terms; a key or a locale outside the
 * registry answers 404 rather than an empty document, so probing this route tells a caller nothing
 * the registry does not already say in public.
 *
 * **It adds no exposure, and that is the reason it may be public at all.** The text it returns is
 * the one the host already publishes at the address the checkbox links to. What changes is the
 * shape — a fragment instead of a page — so a dialog can hold it.
 *
 * ## It is OFF by default, like every other route this package ships
 *
 * `legal-consent.routes.fragment`. With it off, the anchor in the checkbox keeps doing what it does
 * today: it opens the host's own page. That fallback is not a consolation prize — it is the half
 * that carries the clickwrap, because a consent binds only where the full text was reachable BEFORE
 * agreeing (§ 305 Abs. 2 BGB), and a dialog opened by script is not reachable without script.
 *
 * ## A dialog asks for it without the title
 *
 * `heading=0` leaves the title out and keeps everything else, the version included. The dialog's own
 * header already names the document, and the fragment's heading directly under it read as the same
 * title twice. Without the parameter the fragment keeps its heading, so a caller that shows the
 * fragment somewhere with no title of its own gets exactly what it got before.
 */
final readonly class LegalDocumentFragmentController
{
    public function __construct(private ConsentManager $consent, private NamesLegalTexts $names) {}

    public function __invoke(Request $request, string $key, string $locale): View
    {
        $locales = config('legal-consent.locales', ['de']);

        // The registry decides, not the request. Reading `published()` for an unregistered key would
        // answer 404 anyway most of the time — but "most of the time" is the part that makes a
        // surface hard to reason about, so the answer comes from the configuration either way.
        //
        // Through the matrix, like every other reader of the key SET: asking the raw array would
        // serve a fragment for `0` on a config written as a list, which is a name that defines
        // nothing — and this route is one a consent checkbox links to.
        abort_unless(in_array($key, DocumentMatrix::keys(), true), 404);
        abort_unless(is_array($locales) && in_array($locale, $locales, true), 404);

        $document = $this->consent->published($key, $locale);

        // A registered, configured, unpublished combination is a 404 rather than an empty fragment.
        // An empty dialog reads to somebody about to agree as "there is nothing to read here", which
        // is the opposite of what an unpublished document means.
        abort_unless($document instanceof PublishedDocument, 404);

        return view('legal-consent::document-fragment', [
            'document' => $document,
            'heading' => $request->query('heading') !== '0',
            'shownIn' => $this->shownIn($document, $locale),
        ]);
    }

    /**
     * The language to name above the text, or null when the reader gets the language they read in.
     *
     * A reader lands on another language's text two ways: the route falls back because the text was
     * never published in the language it was asked for, or a dialog on an English page asks for the
     * German version because that is the one the reader is held to. The `lang` attribute already
     * tells assistive technology either way; this line tells the person reading.
     */
    private function shownIn(PublishedDocument $document, string $locale): ?string
    {
        if ($document->locale === $locale && $document->locale === app()->getLocale()) {
            return null;
        }

        return $this->names->language($document->locale);
    }
}

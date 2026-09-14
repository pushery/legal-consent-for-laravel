<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Console;

use Illuminate\Console\Command;
use Pushery\LegalConsent\Exceptions\LegalDocumentNotFound;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Support\DocumentMatrix;
use Pushery\LegalConsent\Support\PresentationRerenderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Re-freeze published versions whose text is unchanged and whose RENDERING has moved — the answer
 * `legal-consent:check-drift` points at when it reports "PRESENTATION only".
 *
 * It publishes the identical text again, under the next patch version and the silent mode: no
 * re-consent, no notice, no materiality decision, because there is no change to classify. Whether
 * the text really is identical is decided by the source hash the row carries, never by the caller,
 * and a document whose text HAS moved is refused here rather than quietly re-published.
 *
 * Without arguments it covers the whole configured matrix, which is the point: a renderer change
 * lands on every document at once, and fixing it one key and one locale at a time by hand is what
 * this replaces.
 */
#[AsCommand(name: 'legal-consent:rerender')]
final class RerenderCommand extends Command
{
    protected $signature = 'legal-consent:rerender {key? : Only this document key} {locale? : Only this locale}';

    protected $description = 'Re-freeze published versions whose text is unchanged and whose rendering has moved.';

    public function handle(PresentationRerenderer $rerenderer): int
    {
        $key = $this->argument('key');
        $locale = $this->argument('locale');

        $keys = is_string($key) ? [$key] : DocumentMatrix::keys();
        $locales = is_string($locale) ? [$locale] : DocumentMatrix::locales();

        $published = [];
        $refusals = [];

        foreach ($keys as $documentKey) {
            foreach ($locales as $documentLocale) {
                $document = $this->rerenderOne($rerenderer, $documentKey, $documentLocale, $refusals);

                if ($document instanceof LegalDocument) {
                    $published[] = "'{$documentKey}' ({$documentLocale}) re-frozen as v{$document->version}";
                }
            }
        }

        foreach ($published as $line) {
            $this->info($line);
        }

        foreach ($refusals as $refusal) {
            $this->warn($refusal);
        }

        if ($published === [] && $refusals === []) {
            $this->info('Nothing to re-render: every published version already carries what its source renders to.');
        }

        return $refusals === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * One document, with its refusal collected rather than raised.
     *
     * A missing source is passed over in silence and is the one exception to that: over the whole
     * matrix it means a key that is not authored in this locale, which the bulk publish reports and
     * this command has no business repeating. Every other refusal is a decision the operator owes —
     * a text that moved, or a row too old to prove it did not — and those set the exit code.
     *
     * @param  list<string>  $refusals
     */
    private function rerenderOne(PresentationRerenderer $rerenderer, string $key, string $locale, array &$refusals): ?LegalDocument
    {
        try {
            return $rerenderer->rerender($key, $locale);
        } catch (LegalDocumentNotFound) {
            return null;
        } catch (Throwable $e) {
            $refusals[] = $e->getMessage();

            return null;
        }
    }
}

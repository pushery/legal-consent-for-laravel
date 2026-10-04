<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Models\Concerns;

use Pushery\LegalConsent\Enums\NoticeMode;
use Pushery\LegalConsent\Exceptions\LegalDocumentFrozenException;
use Pushery\LegalConsent\Exceptions\LegalDocumentInEvidenceException;
use Pushery\LegalConsent\Support\EnforceableDocumentCache;

/**
 * The write guards of a published version: the notice mode and its legacy boolean kept in step on
 * insert, the frozen columns refused on update, the cached enforceable set dropped on every write,
 * and a version that consents prove themselves against refused on delete.
 *
 * A trait's boot method rather than `booted()`, so the guard holds for a host's own subclass too:
 * Laravel boots the traits of the whole class hierarchy, and calls only the concrete class's
 * `booted()`, which a subclass replaces when it declares its own without calling its parent.
 */
trait GuardsPublishedDocument
{
    public static function bootGuardsPublishedDocument(): void
    {
        // Keep the legacy boolean `requires_reconsent` and the first-class `notice_mode`
        // consistent on every insert, whichever the caller sets. `notice_mode` is the source of
        // truth; a caller that still sets only the boolean (pre-v0.3.0 code) gets the mapped mode,
        // and a caller that sets only the mode gets the derived boolean — so a notice-mode query
        // and a legacy `requires_reconsent` query can never disagree.
        static::creating(function (self $document): void {
            $mode = $document->notice_mode;

            if ($mode instanceof NoticeMode) {
                $document->requires_reconsent = $mode->gates();

                return;
            }

            $mode = NoticeMode::fromLegacyReconsent((bool) $document->requires_reconsent);
            $document->notice_mode = $mode;
            $document->requires_reconsent = $mode->gates();
        });

        // A published version is frozen proof (EDPB 05/2020 Rz. 108): refuse any update that
        // touches a column outside MUTABLE_AFTER_PUBLISH, in PHP, before any SQL is issued — a
        // clean typed failure on an accidental `$doc->content = …; $doc->save()`. The database
        // trigger (migration 000011) is the defense-in-depth layer that also catches the paths
        // this hook cannot see: the two sweeps write via saveQuietly() (which bypasses events),
        // and raw DB::table()/psql updates never reach a model at all.
        static::updating(function (self $document): void {
            // array_values changes nothing observable: LegalDocumentFrozenException::for() sorts
            // the list, which re-indexes it, and only ever implodes it into its message.
            $forbidden = array_values(array_diff(array_keys($document->getDirty()), self::MUTABLE_AFTER_PUBLISH));

            if ($forbidden !== []) {
                throw LegalDocumentFrozenException::for($forbidden);
            }
        });

        // Any write to this table can change WHICH versions are enforceable, so it drops the gate's
        // cached set. Tying invalidation to the publish event alone would leave every other path
        // stale — an activate(), a seeder, a consumer inserting a row by hand — and a stale
        // enforceable set is a gate that fires late or not at all. The after-commit listener still
        // exists for the release transaction's ordering; this is the net underneath it.
        $flush = static function (self $document): void {
            $cache = app(EnforceableDocumentCache::class);

            // The row's OWN locale first, and only a delete needs it: `flushAll()` discovers
            // locales from the declared list plus the ones currently published, and a deleted row
            // is in neither by the time the listener runs. With `legal-consent.locales` undeclared
            // — the one configuration that lets a document be published in ANY language, which is
            // why this file's sibling guard exists — deleting the last document of a locale left
            // its set cached for the full TTL, so the gate kept enforcing a version that no longer
            // existed. The model still carries the attribute here; the table no longer does.
            // The `!== ''` changes nothing observable: `locale` is a NOT NULL column no write path leaves
            // empty, and flushing '' would drop nothing. It keeps an empty value from reading as a locale.
            if ($document->locale !== '') {
                $cache->flush($document->locale);
            }

            // Redundant for the row this listener was called with: every set is keyed per locale, and
            // resolvedFor() walks a chain by reading each locale's own set, so the flush above already
            // reaches every reader this write can change, through a locale chain too. It stays as the
            // wider net the comment above `$flush` describes.
            $cache->flushAll();
        };

        static::saved($flush);
        static::deleted($flush);

        // The text a subject was shown exists exactly ONCE, here, in `content`. The ledger row
        // beside it holds `content_hash` and the acceptance sentence — a fingerprint verifies a
        // text somebody produces, it cannot produce one. So deleting a version that consents point
        // at destroys the Art. 7(1) evidence for every one of them, silently: the rows survive,
        // history() still answers, and only a supervisory authority asking "what exactly did they
        // agree to?" finds that nothing can answer it any more.
        //
        // Refused, rather than warned about, because it cannot be undone and because the ledger's
        // subordinate tables (legal_change_sets / legal_change_items) have carried BEFORE DELETE
        // triggers since they existed — the load-bearing table was the unprotected one. Retirement
        // is `is_active = false`, which is what the column is for and what every retirement path in
        // the package already uses.
        static::deleting(function (self $document): void {
            $consents = $document->consentsInEvidence();

            if ($consents > 0) {
                // The three casts are for the declared string parameters and change no value: key,
                // version and locale are NOT NULL string columns, and this model casts none of them.
                throw LegalDocumentInEvidenceException::for(
                    (string) $document->key,
                    (string) $document->version,
                    (string) $document->locale,
                    $consents,
                );
            }
        });
    }
}

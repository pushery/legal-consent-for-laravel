<?php

declare(strict_types=1);

namespace Pushery\LegalConsent\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;
use Override;
use Pushery\LegalConsent\Content\RenderPipeline;
use Pushery\LegalConsent\Contracts\ResolvesNoticeIdentity;
use Pushery\LegalConsent\Contracts\SendsNoticeMail;
use Pushery\LegalConsent\Models\LegalDocument;
use Pushery\LegalConsent\Notifications\Concerns\RendersChangeItems;
use Pushery\LegalConsent\Support\ConsentGate;
use Pushery\LegalConsent\Support\NoticeMailConfig;

/**
 * What the three notice-mode notifications share, and the seams a consuming developer activates.
 *
 * NOT final, and that is the whole point: a consumer subclasses one of the three to change how a
 * legally-required notice reads without reimplementing the dispatch, the proof rendering or the
 * mode routing. Everything `protected` here is therefore public surface for them — which is why
 * there are exactly THREE members a subclass must supply, and why the shared behavior is one
 * method (`envelope()`) rather than a dozen hooks.
 *
 * The three:
 *  - {@see translationGroup()} — where this notice's lines live under `notifications.*`;
 *  - `toMail()` — the ORDER of the lines, which is legal substance and not shared. The § 308
 *    Nr. 5 lit. b warning has to precede the call to action; a re-consent notice states its
 *    consequence after it. A base class that assembled the mail would be deciding that.
 *  - `mandatoryContentPresent()` — what this mode's regime actually demands.
 *
 * EVERY SEAM IS INERT BY DEFAULT, with one exception the owner decided: the package's own Markdown
 * shell. Until 0.13 a German § 126b declaration went out inside Laravel's global template, with
 * "Hello!", "Regards," and "If you're having trouble clicking" resolved from the CONSUMING
 * application's translations — while the documentation promised the opposite. `notice_mail.view`
 * set to null opts back out.
 *
 * Inert matters more here than elsewhere: the notice body is hashed into an append-only proof row,
 * so a seam that changed the mail without being asked for would move the bytes of a document
 * nobody can correct afterwards. Only two things ever reach that body — the lines a subclass
 * writes, and the declarant footer, which appears only once someone has configured a declarant.
 */
abstract class ChangeNotification extends Notification implements SendsNoticeMail, ShouldQueue
{
    use Queueable;
    use RendersChangeItems;

    /**
     * Every column of `legal_documents`, so {@see getQueryForModelRestoration()} can name what a
     * queued notice needs by SUBTRACTION instead of by an allow-list that would silently drop a
     * column added later — the failure mode being a legal text rendered from a null. The list is
     * held against the live schema, so a column added to the table and not to this list fails
     * loudly rather than arriving as a missing attribute.
     *
     * @var list<string>
     */
    public const array DOCUMENT_COLUMNS = [
        'id', 'key', 'type', 'requires_explicit_optin', 'locale', 'tenant_id', 'version',
        'major_version', 'minor_version', 'patch_version', 'title', 'content_format', 'content',
        'content_hash', 'ui_wording', 'source_driver', 'source_reference', 'requires_reconsent',
        'change_summary', 'is_active', 'published_at', 'announce_from', 'enforce_from',
        'notified_at', 'created_at', 'updated_at', 'notice_mode', 'change_class', 'regime',
        'notice_period_days', 'offers_termination', 'keeps_unmodified_offered',
        'objection_deadline', 'objection_closed_at',
    ];

    public function __construct(public readonly LegalDocument $document) {}

    /**
     * Columns a queued notice job does NOT need restored with its document.
     *
     * `SerializesModels` puts a model identifier in the payload — 1.9 KB rather than 424 KB, which
     * is right — and the worker then restores the row with `select *`. That pulls the whole legal
     * text back out of the database for every RECIPIENT, and `content` is allowed up to 512 KB
     * ({@see RenderPipeline}) while a notice renders the title, the
     * version, the key, the dates and the notice mode. At a hundred thousand recipients that is
     * tens of gigabytes moved for text no mail ever shows.
     *
     * Narrowed HERE rather than on the model, deliberately: a `newQueryForRestoration()` override
     * would apply to every restore of a LegalDocument anywhere, and only this path is known to be
     * safe. A subclass whose `toMail()` reaches for the body widens the list back.
     *
     * @return list<string>
     */
    protected function documentColumnsNotRestored(): array
    {
        return ['content'];
    }

    /**
     * @template TModel of Model
     *
     * @param  TModel  $model
     * @param  array<array-key, mixed>|int  $ids
     * @return Builder<TModel>
     */
    #[Override]
    protected function getQueryForModelRestoration($model, $ids): Builder
    {
        $query = parent::getQueryForModelRestoration($model, $ids);

        if (! $model instanceof LegalDocument) {
            // A consumer subclass may carry other models of its own, and the narrowing below is
            // only known to be safe for the document this notice is about.
            return $query;
        }

        return $query->select(array_values(array_diff(self::DOCUMENT_COLUMNS, $this->documentColumnsNotRestored())));
    }

    /**
     * The `notifications.*` group this notice's lines live under — `contract`, `deemed`, or
     * `informational.contract` / `informational.acknowledgement`.
     */
    abstract protected function translationGroup(): string;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = config('legal-consent.notifications.channels', ['mail', 'database']);

        if (is_array($channels)) {
            $strings = array_values(array_filter($channels, is_string(...)));

            if ($strings !== []) {
                return $strings;
            }
        }

        return ['mail', 'database'];
    }

    /**
     * The last thing every `toMail()` does: put the mode-specific lines into the shared envelope.
     *
     * Order of operations is deliberate. The subject is decorated FIRST, because it is the first
     * line of the proof body; the declarant is appended as an outro line, so it is inside the
     * hashed body rather than in the template — a § 126b declaration that lived only in the view
     * would be absent from the very row that exists to prove it. Everything after that is
     * transport (sender, reply-to, shell) and never touches the body.
     */
    protected function envelope(MailMessage $mail): MailMessage
    {
        $mail->subject = $this->decoratedSubject($mail->subject);

        $identity = app(ResolvesNoticeIdentity::class)->forDocument($this->document);

        if ($identity->isDeclared()) {
            $mail->line((string) trans('legal-consent::notifications.common.issuer', ['declarant' => $identity->declarationLine()]));
        }

        $from = NoticeMailConfig::from();

        if ($from !== null) {
            $mail->from($from[0], $from[1]);
        }

        $replyTo = NoticeMailConfig::replyTo() ?? $identity->replyTo;

        if ($replyTo !== null) {
            $mail->replyTo($replyTo);
        }

        $view = NoticeMailConfig::view();

        if ($view !== null) {
            // ->markdown(), NEVER ->view(). A plain view nulls $markdown and leaves introLines and
            // outroLines empty, so the proof body would collapse to the subject line while
            // mandatory_content_ok kept saying true.
            $mail->markdown($view, [
                'identity' => $identity,
                'document' => $this->document,
                // So the shell can leave the do-not-reply note out when a reply address IS
                // configured — telling a subject not to reply to an address that accepts replies
                // is the kind of small wrongness that makes a legal notice look automated.
                'replyTo' => $replyTo,
            ]);
        }

        $theme = NoticeMailConfig::theme();

        if ($theme !== null) {
            $mail->theme($theme);
        }

        return $mail;
    }

    /**
     * A prefix and an effective date are both off by default, because the subject is the first
     * line of the hashed proof body: switching either on changes the hash of every notice written
     * from then on. Fine as a decision, wrong as a side effect.
     */
    private function decoratedSubject(?string $subject): string
    {
        $subject = (string) $subject;
        $prefix = NoticeMailConfig::subjectPrefix();

        if ($prefix !== null) {
            $subject = "{$prefix} {$subject}";
        }

        $enforceFrom = $this->document->enforce_from;

        if (NoticeMailConfig::showsEffectiveDateInSubject() && $enforceFrom instanceof CarbonImmutable) {
            return (string) trans('legal-consent::notifications.common.subject_effective', [
                'subject' => $subject,
                'date' => $enforceFrom->toDateString(),
            ]);
        }

        return $subject;
    }

    /**
     * Where the notice sends the subject to act. Identical for all three modes: the consent route
     * is where a subject reviews, agrees, objects or terminates.
     */
    protected function ctaUrl(): string
    {
        $name = config('legal-consent.routes.consent_name');

        // The `!== ''` is redundant against `Route::has('')`, which is false -- measured, and it
        // is why deleting that clause changes nothing any test can see. It stays as the cheaper
        // half of the pair: a name only reaches the router when there is a name to look up.
        if (is_string($name) && $name !== '' && Route::has($name)) {
            return route($name);
        }

        $path = config('legal-consent.routes.consent_path', '/legal-consent');

        return url(is_string($path) ? $path : '/legal-consent');
    }

    /**
     * The last gate before a notice actually leaves, and the only one that runs in the WORKER.
     *
     * The audience is resolved when the sweep runs; the mail goes out when a queue worker picks
     * the job up, which can be minutes later under load. A subject who agreed in between has
     * already done the thing the notice is about to ask them to do, and telling them again is at
     * best noise — at worst, for a re-consent notice, it reads as if their agreement did not
     * register.
     *
     * Only the gating mode is skipped, and only on a document that no longer gates them. An
     * info-only or deemed-consent notice is owed regardless of what the subject does, so
     * suppressing one would drop a legally required communication.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        if (! $this->document->noticeMode()->gates() || ! $notifiable instanceof Model) {
            return true;
        }

        // "Has this subject already accepted this version's major", NOT "is it outstanding now".
        // The two differ exactly where it matters: a re-consent notice is usually the ADVANCE
        // announcement of a change whose enforce_from is still in the future, so the document is
        // not yet enforceable and `outstanding()` would report nothing — suppressing every
        // scheduled notice the package exists to send.
        //
        // Restricted to the ONE key this answer turns on. The question runs in the worker, once
        // per recipient, and the ledger it reads grows for the life of the account — so the
        // unrestricted fold made a single-key yes/no cost more the longer someone had been a
        // customer, for rows it then threw away.
        $held = new ConsentGate()->heldMajorByKey($notifiable, [$this->document->key]);

        return ($held[$this->document->key] ?? 0) < $this->document->major_version;
    }

    /**
     * A translated line, or '' when the key does not resolve.
     *
     * Never the raw key: an untranslated token in a legal notice is not content, and
     * `mandatoryContentPresent()` has to be able to see that it is missing.
     *
     * @param  array<string, string>  $replace
     */
    protected function translate(string $key, array $replace = []): string
    {
        $transKey = "legal-consent::notifications.{$this->translationGroup()}.{$key}";
        $translated = trans($transKey, $replace);

        return is_string($translated) && $translated !== $transKey ? $translated : '';
    }
}

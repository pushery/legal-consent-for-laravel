{{--
    The Markdown shell every change notice renders through.

    It replaces Laravel's global notification template, which greeted a legally-binding German
    § 126b declaration with "Hello!", closed it with "Regards," and explained what to do "if you're
    having trouble clicking" — all resolved from the CONSUMING application's translations, so a
    notice in one language arrived wrapped in another. There is no greeting and no salutation here
    on purpose: a mandatory communication is not correspondence, and a first-name greeting is
    exactly the framing that makes it read as marketing.

    WHAT IS IN THE MAIL AND WHAT IS IN THE PROOF ARE NOT THE SAME SET, and that difference is why
    this file may be edited freely. `$introLines`, `$actionText` and `$outroLines` are the notice
    itself, and they are what gets hashed into the append-only `legal_notices` row. Everything this
    template adds around them — the reason-for-receipt sentence, the do-not-reply note, the
    secondary links — is envelope, never evidence. Restyle it, reorder it, drop a line: the proof
    is untouched.

    The one thing NOT to do is point `notice_mail.view` at a plain (non-Markdown) view. That empties
    the line collections, so the proof body collapses to the subject alone while still reporting its
    mandatory content as present.

    $introLines · $actionText · $actionUrl · $outroLines — the notice, from the MailMessage.
    $identity — the resolved declarant (§ 126b); every field may be null.
    $document — the version this notice is about.
    $replyTo  — the configured reply address, or null.
--}}
<x-mail::message>
@foreach ($introLines as $line)
{{ $line }}

@endforeach

@isset($actionText)
<x-mail::button :url="$actionUrl" color="primary">
{{ $actionText }}
</x-mail::button>
@endisset

@foreach ($outroLines as $line)
{{ $line }}

@endforeach

@if ($identity->imprintUrl !== null || $identity->privacyUrl !== null)
@if ($identity->imprintUrl !== null)
[{{ __('legal-consent::notifications.common.more') }}]({{ $identity->imprintUrl }})
@endif
@if ($identity->privacyUrl !== null)
[{{ __('legal-consent::notifications.common.privacy') }}]({{ $identity->privacyUrl }})
@endif

@endif
<x-slot:subcopy>
{{ __('legal-consent::notifications.common.why') }}
@if ($replyTo === null)

{{ __('legal-consent::notifications.common.no_reply') }}
@endif
</x-slot:subcopy>
</x-mail::message>

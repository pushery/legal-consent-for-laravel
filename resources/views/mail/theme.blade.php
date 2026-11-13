{{--
    The stylesheet of the change notice, and the theme it renders with unless `notice_mail.theme`
    names another.

    Laravel inlines one stylesheet into a mail, and a theme named on the message takes the place of
    the application's rather than adding to it. So this one begins with the application's own theme,
    found the way Laravel finds `mail.markdown.theme`, and adds to it only what sets the notice apart
    from the note under it: the notice's text a shade darker than the theme's, and the note on why the
    mail arrived smaller and lighter. The layout, the colors and the button stay the application's.

    Nothing here reaches the proof row: a theme styles the lines of the notice and writes none.
--}}
@php
    // Named as the application's theme, this one would include itself without end.
    $base = config('mail.markdown.theme');
    $base = is_string($base) && $base !== '' && $base !== 'legal-consent::mail.theme' ? $base : 'default';
    $custom = \Illuminate\Support\Str::start($base, 'mail.');
    $base = view()->exists($custom) ? $custom : (str_contains($base, '::') ? $base : 'mail::themes.'.$base);
@endphp
@include($base)

{{-- Within the card only: Laravel's footer is a content cell as well, and it keeps the theme's own colors. --}}
.inner-body .content-cell p,
.inner-body .content-cell li {
    color: #3f3f46;
}

.inner-body .content-cell li {
    font-size: 16px;
    line-height: 1.5em;
}

.inner-body .subcopy p {
    color: #71717a;
    font-size: 13px;
}

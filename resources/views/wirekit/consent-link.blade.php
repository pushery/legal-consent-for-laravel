{{-- ONE LINK, ON ITS OWN, so a caller can ask for its markup as a string.

     The consent label is assembled in PHP rather than written as directives: Blade leaves a
     closing directive that sits flush against the token before it in the output as literal text,
     and this label may carry no stray whitespace because it is the accessible NAME of the control.

     That leaves the question of how a Blade component reaches an assembled string, and the answer
     is not to keep its markup in a string literal of the calling view. That view writes its
     statements in the one-line form of the PHP directive, and Blade compiles echoes and component
     tags inside that form's string literals as well, so such a template would be compiled before
     it is passed anywhere. A view of its own is compiled on its own.

     Rendered rather than rebuilt: the link component is a hundred and forty lines of prop
     resolution, and restating it here would be a copy of somebody else's formula that stops
     growing the day they add an axis.

     A link that opens a dialog does not announce a new tab. `$extra` carries the dialog trigger
     when there is a dialog, and then the anchor opens it once script runs, and a new tab only
     where script does not. The kit's own announcement is wrong in the first case, so it is
     switched off and replaced by a hint that Alpine hides: a screen reader hears "(opens in new
     tab)" only where the tab is what happens, and the bound `aria-haspopup` announces the dialog
     everywhere else. Without a dialog the anchor opens a new tab in both cases and keeps the
     kit's announcement. Assembled as a string because this text is part of a label, the
     accessible name of a control, and may carry no whitespace of its own. --}}
@php($opensDialog = $extra->isNotEmpty())
@php($tabHint = $opensDialog ? '<span class="sr-only" x-show="false">'.e(__('wirekit::(opens in new tab)')).'</span>' : '')
<x-wirekit::link
    :id="$id"
    :href="$href"
    :hreflang="$hreflang"
    external
    :announce-new-tab="! $opensDialog"
    :attributes="$extra"
>{{ $text }}{!! $tabHint !!}</x-wirekit::link>

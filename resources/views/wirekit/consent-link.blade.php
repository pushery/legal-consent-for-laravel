{{-- ONE LINK, ON ITS OWN, so a caller can ask for its markup as a string.

     The consent label is assembled in PHP rather than written as directives: Blade leaves a
     closing directive that sits flush against the token before it in the output as literal text,
     and this label may carry no stray whitespace because it is the accessible NAME of the control.

     That leaves the question of how a Blade COMPONENT reaches an assembled string, and the answer
     is not to keep its markup in a PHP string literal — Blade rewrites echoes and component tags
     inside this file's own string literals too, so such a template is compiled before it is passed
     anywhere. A view of its own is compiled on its own.

     Rendered rather than rebuilt: the link component is a hundred and forty lines of prop
     resolution, and restating it here would be a copy of somebody else's formula that stops
     growing the day they add an axis. --}}
<x-wirekit::link
    :id="$id"
    :href="$href"
    :hreflang="$hreflang"
    external
    :attributes="$extra"
>{{ $text }}</x-wirekit::link>

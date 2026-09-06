<?php

declare(strict_types=1);

// Labels for the per-version change description a notice carries.
//
// The six type labels are what a reader sees in front of each entry, so they have to be
// UNAMBIGUOUS rather than short: `extended` and `added` look alike and are not — one widens what
// already applied, the other introduces something new, and only the first can quietly take a
// choice away. Informal register throughout, matching every other string this package ships.
return [
    'types' => [
        'added' => 'Nieuw',
        'removed' => 'Vervalt',
        'modified' => 'Gewijzigd',
        'clarified' => 'Verduidelijking',
        'extended' => 'Uitgebreid',
        'restricted' => 'Beperkt',
    ],

];

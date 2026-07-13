<?php

declare(strict_types=1);

// Re-consent notification, split by legal basis: a contract asks for renewed AGREEMENT,
// a privacy policy only for ACKNOWLEDGEMENT (never "agree" — EDPB 05/2020 §122). The
// consequence line satisfies § 308 Nr. 5 lit. b BGB.
return [
    'contract' => [
        'subject' => 'Belangrijk: bijgewerkte gebruiksvoorwaarden',
        'intro' => 'We hebben onze gebruiksvoorwaarden bijgewerkt en vragen je om opnieuw akkoord te gaan.',
        'cta' => 'Nu bekijken en akkoord gaan',
        'consequence' => 'Ga op tijd akkoord — anders wordt het verdere gebruik vanaf de ingangsdatum beperkt.',
    ],
    'acknowledgement' => [
        'subject' => 'Belangrijk: bijgewerkte privacyverklaring',
        'intro' => 'We hebben onze privacyverklaring bijgewerkt en vragen je kennis te nemen van de nieuwe versie.',
        'cta' => 'Nu bekijken en kennisname bevestigen',
        'consequence' => 'Neem op tijd kennis van de wijzigingen — anders wordt het verdere gebruik vanaf de ingangsdatum beperkt.',
    ],
];

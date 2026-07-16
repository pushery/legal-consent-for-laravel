<?php

declare(strict_types=1);

// Twee juridisch onderscheiden meldingen: `contract` (ReconsentRequired, hernieuwd akkoord
// voor een materiële contractwijziging — § 308 Nr. 5 lit. b BGB) en `informational`
// (LegalChangeInformational, ENKEL INFORMATIEVE wijziging, geen actie, geen dreiging van
// beperking; van een privacyverklaring neem je kennis, je gaat er nooit mee «akkoord» —
// EDPB 05/2020 § 122). Informeel register (je).
return [
    'contract' => [
        'subject' => 'Belangrijk: bijgewerkte gebruiksvoorwaarden',
        'intro' => 'We hebben onze gebruiksvoorwaarden bijgewerkt en vragen je om opnieuw akkoord te gaan.',
        'cta' => 'Nu bekijken en akkoord gaan',
        'consequence' => 'Ga op tijd akkoord — anders wordt het verdere gebruik vanaf de ingangsdatum beperkt.',
    ],
    'informational' => [
        'contract' => [
            'subject' => 'Wijzigingen in ons contract',
            'intro' => 'We hebben ons contract aangepast. Er is geen actie van jouw kant nodig.',
            'cta' => 'Wijzigingen bekijken',
            'effective' => 'De wijzigingen gaan in op :deadline.',
            'objection' => 'Als je het niet eens bent met de wijzigingen, kun je tot :deadline kosteloos opzeggen.',
        ],
        'acknowledgement' => [
            'subject' => 'Bijgewerkte privacyverklaring',
            'intro' => 'We hebben onze privacyverklaring bijgewerkt. Neem kennis van de nieuwe versie — er is geen actie nodig.',
            'cta' => 'Nieuwe versie bekijken',
            'effective' => 'De bijgewerkte versie geldt vanaf :deadline.',
            'objection' => 'Je kunt te allen tijde bezwaar maken tegen de verwerking.',
        ],
    ],
    'deemed' => [
        'subject' => 'Een wijziging van ons contract',
        'intro' => 'We werken ons contract bij (":title").',
        'warning' => 'Als je vóór :deadline geen bezwaar maakt, geldt dit als jouw akkoord met de wijzigingen.',
        'cta' => 'Wijzigingen bekijken en zo nodig bezwaar maken',
        'termination' => 'Je kunt het contract tot :effective kosteloos opzeggen.',
    ],
];

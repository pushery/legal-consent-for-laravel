<?php

declare(strict_types=1);

// Two notifications, kept legally distinct:
//  - `contract`      ReconsentRequired: a material CONTRACT change asks for renewed
//                    AGREEMENT; the consequence line satisfies § 308 Nr. 5 lit. b BGB.
//  - `informational` LegalChangeInformational: an INFO-ONLY change — NO action required,
//                    never a threat of restriction. A privacy notice is acknowledged, never
//                    agreed to (EDPB 05/2020 § 122).
return [
    // Shared lines every change notice can use. `issuer` is the § 126b BGB naming of the
    // declaring person and is the ONLY one of these that enters the notice body — and therefore
    // the hash of the append-only proof row — and only once a declarant is configured. The rest
    // is envelope: the reason the message arrived, the do-not-reply note, the secondary links.
    'common' => [
        'issuer' => 'Verklaard door: :declarant',
        'why' => 'Je krijgt dit bericht omdat je een account bij ons hebt en wij je over deze wijziging moeten informeren. Het is geen reclame en er valt niets af te melden.',
        'no_reply' => 'Op dit adres kun je niet antwoorden. Gebruik de link hierboven als je iets wilt doen.',
        'more' => 'Colofon',
        'privacy' => 'Privacyverklaring',
        'subject_effective' => ':subject (geldig vanaf :date)',
    ],
    'contract' => [
        'subject' => 'Belangrijk: bijgewerkte gebruiksvoorwaarden',
        'intro' => 'We hebben onze gebruiksvoorwaarden bijgewerkt en vragen je om opnieuw akkoord te gaan.',
        'cta' => 'Nu bekijken en akkoord gaan',
        'consequence' => 'Ga vóór :deadline akkoord — anders wordt het verdere gebruik vanaf die datum beperkt.',
        'consequence_undated' => 'Ga op tijd akkoord — anders wordt het verdere gebruik vanaf de ingangsdatum beperkt.',
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

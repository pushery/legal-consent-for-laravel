<?php

declare(strict_types=1);

// Two notifications, kept legally distinct:
//  - `contract`      ReconsentRequired: a material CONTRACT change asks for renewed
//                    ZUSTIMMUNG; the consequence line satisfies § 308 Nr. 5 lit. b BGB.
//  - `informational` LegalChangeInformational: an INFO-ONLY change — NO action required,
//                    never a threat of restriction. A privacy notice is acknowledged
//                    (zur Kenntnis nehmen), never agreed to (EDPB 05/2020 Rz. 122). Per Du.
return [
    // Shared lines every change notice can use. `issuer` is the § 126b BGB naming of the
    // declaring person and is the ONLY one of these that enters the notice body — and therefore
    // the hash of the append-only proof row — and only once a declarant is configured. The rest
    // is envelope: the reason the message arrived, the do-not-reply note, the secondary links.
    'common' => [
        'issuer' => 'Erklärende Person: :declarant',
        'why' => 'Du bekommst diese Nachricht, weil du ein Konto bei uns hast und wir dich über diese Änderung informieren müssen. Sie ist keine Werbung, und es gibt nichts abzubestellen.',
        'no_reply' => 'Auf diese Adresse kannst du nicht antworten. Nutze den Link oben, wenn du handeln willst.',
        'more' => 'Impressum',
        'privacy' => 'Datenschutzerklärung',
        'subject_effective' => ':subject (gültig ab :date)',
    ],
    'contract' => [
        'subject' => 'Wichtig: aktualisierte Nutzungsbedingungen',
        'intro' => 'Wir haben unsere Nutzungsbedingungen aktualisiert und bitten dich um deine erneute Zustimmung.',
        'cta' => 'Jetzt ansehen und zustimmen',
        'consequence' => 'Bitte stimme bis zum :deadline zu — andernfalls ist die weitere Nutzung ab diesem Tag eingeschränkt.',
        'consequence_undated' => 'Bitte stimme rechtzeitig zu — andernfalls ist die weitere Nutzung ab dem Stichtag eingeschränkt.',
    ],
    'informational' => [
        'contract' => [
            'subject' => 'Änderungen an unserem Vertrag',
            'intro' => 'Wir haben unseren Vertrag angepasst. Von deiner Seite ist keine Aktion erforderlich.',
            'cta' => 'Änderungen ansehen',
            'effective' => 'Die Änderungen treten zum :deadline in Kraft.',
            'objection' => 'Wenn du mit den Änderungen nicht einverstanden bist, kannst du bis zum :deadline kostenfrei kündigen.',
        ],
        'acknowledgement' => [
            'subject' => 'Aktualisierte Datenschutzerklärung',
            'intro' => 'Wir haben unsere Datenschutzerklärung aktualisiert. Bitte nimm die neue Fassung zur Kenntnis — es ist keine Aktion erforderlich.',
            'cta' => 'Neue Fassung ansehen',
            'effective' => 'Die aktualisierte Fassung gilt ab dem :deadline.',
            'objection' => 'Der Verarbeitung kannst du jederzeit widersprechen.',
        ],
    ],
    // DeemedConsentNotice: a minor/peripheral contract change with a Zustimmungsfiktion. The
    // `warning` line is the § 308 Nr. 5 lit. b BGB special warning — a validity condition, not
    // courtesy copy. Contract only (silence never binds a privacy notice or a real consent).
    'deemed' => [
        'subject' => 'Änderung unseres Vertrags',
        'intro' => 'Wir passen unseren Vertrag („:title") an.',
        'warning' => 'Wenn du nicht bis zum :deadline widersprichst, gilt dies als deine Zustimmung zu den Änderungen.',
        'cta' => 'Änderungen ansehen und ggf. widersprechen',
        'termination' => 'Du kannst den Vertrag bis zum :effective kostenfrei kündigen.',
    ],
];

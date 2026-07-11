<?php

declare(strict_types=1);

// Re-Consent-Benachrichtigung, getrennt nach Rechtsgrundlage: ein Vertrag verlangt
// erneute ZUSTIMMUNG, eine Datenschutzerklärung nur die KENNTNISNAHME (nie „zustimmen“ —
// EDPB 05/2020 Rz. 122). Der consequence-Text erfüllt § 308 Nr. 5 lit. b BGB. Per Du.
return [
    'contract' => [
        'subject' => 'Wichtig: aktualisierte Nutzungsbedingungen',
        'intro' => 'Wir haben unsere Nutzungsbedingungen aktualisiert und bitten dich um deine erneute Zustimmung.',
        'cta' => 'Jetzt ansehen und zustimmen',
        'consequence' => 'Bitte stimme rechtzeitig zu — andernfalls ist die weitere Nutzung ab dem Stichtag eingeschränkt.',
    ],
    'acknowledgement' => [
        'subject' => 'Wichtig: aktualisierte Datenschutzerklärung',
        'intro' => 'Wir haben unsere Datenschutzerklärung aktualisiert und bitten dich, die neue Fassung zur Kenntnis zu nehmen.',
        'cta' => 'Jetzt ansehen und zur Kenntnis nehmen',
        'consequence' => 'Bitte nimm die Aktualisierung rechtzeitig zur Kenntnis — andernfalls ist die weitere Nutzung ab dem Stichtag eingeschränkt.',
    ],
];

<?php

declare(strict_types=1);

// Re-consent notification, split by legal basis: a contract asks for renewed AGREEMENT,
// a privacy policy only for ACKNOWLEDGEMENT (never "agree" — EDPB 05/2020 §122). The
// consequence line satisfies § 308 Nr. 5 lit. b BGB.
return [
    'contract' => [
        'subject' => 'Importante: condizioni d\'uso aggiornate',
        'intro' => 'Abbiamo aggiornato le nostre condizioni d\'uso e ti chiediamo di accettarle di nuovo.',
        'cta' => 'Consulta e accetta ora',
        'consequence' => 'Accetta per tempo — altrimenti l\'utilizzo sarà limitato a partire dalla data di entrata in vigore.',
    ],
    'acknowledgement' => [
        'subject' => 'Importante: informativa sulla privacy aggiornata',
        'intro' => 'Abbiamo aggiornato la nostra informativa sulla privacy e ti chiediamo di prenderne atto.',
        'cta' => 'Consulta e conferma la lettura ora',
        'consequence' => 'Prendine atto per tempo — altrimenti l\'utilizzo sarà limitato a partire dalla data di entrata in vigore.',
    ],
];

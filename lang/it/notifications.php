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
        'issuer' => 'Dichiarato da: :declarant',
        'why' => 'Ricevi questo messaggio perché hai un account con noi e siamo tenuti a informarti di questa modifica. Non è pubblicità e non c\'è nulla da disiscrivere.',
        'no_reply' => 'Questo indirizzo non accetta risposte. Usa il link qui sopra se vuoi agire.',
        'more' => 'Note legali',
        'privacy' => 'Informativa sulla privacy',
        'subject_effective' => ':subject (in vigore dal :date)',
    ],
    'contract' => [
        'subject' => 'Importante: condizioni d\'uso aggiornate',
        'intro' => 'Abbiamo aggiornato le nostre condizioni d\'uso e ti chiediamo di accettarle di nuovo.',
        'cta' => 'Consulta e accetta ora',
        'consequence' => 'Accetta entro il :deadline — altrimenti l\'utilizzo sarà limitato a partire da quella data.',
        'consequence_undated' => 'Accetta per tempo — altrimenti l\'utilizzo sarà limitato a partire dalla data di entrata in vigore.',
    ],
    'informational' => [
        'contract' => [
            'subject' => 'Modifiche al nostro contratto',
            'intro' => 'Abbiamo aggiornato il nostro contratto. Non è richiesta alcuna azione da parte tua.',
            'cta' => 'Vedi le modifiche',
            'effective' => 'Le modifiche entrano in vigore il :deadline.',
            'objection' => 'Se non sei d\'accordo con le modifiche, puoi recedere gratuitamente entro il :deadline.',
        ],
        'acknowledgement' => [
            'subject' => 'Informativa sulla privacy aggiornata',
            'intro' => 'Abbiamo aggiornato la nostra informativa sulla privacy. Prendine atto — non è richiesta alcuna azione.',
            'cta' => 'Vedi la nuova versione',
            'effective' => 'La versione aggiornata si applica dal :deadline.',
            'objection' => 'Puoi opporti al trattamento in qualsiasi momento.',
        ],
    ],
    'deemed' => [
        'subject' => 'Una modifica al nostro contratto',
        'intro' => 'Stiamo aggiornando il nostro contratto («:title»).',
        'warning' => 'Se non ti opponi entro il :deadline, ciò varrà come tua accettazione delle modifiche.',
        'cta' => 'Vedi le modifiche e opponiti se lo desideri',
        'termination' => 'Puoi recedere dal contratto gratuitamente entro il :effective.',
    ],
];

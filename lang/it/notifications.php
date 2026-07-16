<?php

declare(strict_types=1);

// Due notifiche giuridicamente distinte: `contract` (ReconsentRequired, nuova accettazione
// di una modifica sostanziale del contratto — § 308 Nr. 5 lit. b BGB) e `informational`
// (LegalChangeInformational, modifica SOLO INFORMATIVA, senza azione, senza minaccia di
// limitazione; un'informativa sulla privacy si prende atto, non si «accetta» mai —
// EDPB 05/2020 § 122). Registro informale (tu).
return [
    'contract' => [
        'subject' => 'Importante: condizioni d\'uso aggiornate',
        'intro' => 'Abbiamo aggiornato le nostre condizioni d\'uso e ti chiediamo di accettarle di nuovo.',
        'cta' => 'Consulta e accetta ora',
        'consequence' => 'Accetta per tempo — altrimenti l\'utilizzo sarà limitato a partire dalla data di entrata in vigore.',
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

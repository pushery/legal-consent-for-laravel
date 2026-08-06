<?php

declare(strict_types=1);

// Two notifications, kept legally distinct:
//  - `contract`      ReconsentRequired: a material CONTRACT change asks for renewed
//                    AGREEMENT; the consequence line satisfies § 308 Nr. 5 lit. b BGB.
//  - `informational` LegalChangeInformational: an INFO-ONLY change — NO action required,
//                    never a threat of restriction. A privacy notice is acknowledged, never
//                    agreed to (EDPB 05/2020 § 122).
return [
    'contract' => [
        'subject' => 'Important : conditions d\'utilisation mises à jour',
        'intro' => 'Nous avons mis à jour nos conditions d\'utilisation et te demandons de les accepter à nouveau.',
        'cta' => 'Consulter et accepter maintenant',
        'consequence' => 'Merci d\'accepter avant le :deadline — sinon, l\'utilisation sera restreinte à partir de cette date.',
        'consequence_undated' => 'Merci d\'accepter à temps — sinon, l\'utilisation sera restreinte à partir de la date d\'entrée en vigueur.',
    ],
    'informational' => [
        'contract' => [
            'subject' => 'Modifications de notre contrat',
            'intro' => 'Nous avons modifié notre contrat. Aucune action n\'est requise de ta part.',
            'cta' => 'Voir les modifications',
            'effective' => 'Les modifications entrent en vigueur le :deadline.',
            'objection' => 'Si tu n\'es pas d\'accord avec les modifications, tu peux résilier gratuitement jusqu\'au :deadline.',
        ],
        'acknowledgement' => [
            'subject' => 'Politique de confidentialité mise à jour',
            'intro' => 'Nous avons mis à jour notre politique de confidentialité. Prends connaissance de la nouvelle version — aucune action n\'est requise.',
            'cta' => 'Voir la nouvelle version',
            'effective' => 'La version mise à jour s\'applique à partir du :deadline.',
            'objection' => 'Tu peux t\'opposer au traitement à tout moment.',
        ],
    ],
    'deemed' => [
        'subject' => 'Une modification de notre contrat',
        'intro' => 'Nous mettons à jour notre contrat (« :title »).',
        'warning' => 'Si tu ne t\'y opposes pas avant le :deadline, cela vaudra acceptation des modifications.',
        'cta' => 'Consulter les modifications et t\'y opposer si tu le souhaites',
        'termination' => 'Tu peux résilier le contrat gratuitement jusqu\'au :effective.',
    ],
];

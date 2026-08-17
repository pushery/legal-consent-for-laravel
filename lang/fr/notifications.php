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
        'issuer' => 'Déclaré par : :declarant',
        'why' => 'Tu reçois ce message parce que tu as un compte chez nous et que nous sommes tenus de t\'informer de ce changement. Ce n\'est pas de la publicité, et il n\'y a rien à désabonner.',
        'no_reply' => 'Cette adresse ne reçoit pas de réponses. Utilise le lien ci-dessus si tu veux agir.',
        'more' => 'Mentions légales',
        'privacy' => 'Politique de confidentialité',
        'subject_effective' => ':subject (en vigueur le :date)',
    ],
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

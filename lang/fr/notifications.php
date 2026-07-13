<?php

declare(strict_types=1);

// Re-consent notification, split by legal basis: a contract asks for renewed AGREEMENT,
// a privacy policy only for ACKNOWLEDGEMENT (never "agree" — EDPB 05/2020 §122). The
// consequence line satisfies § 308 Nr. 5 lit. b BGB.
return [
    'contract' => [
        'subject' => 'Important : conditions d\'utilisation mises à jour',
        'intro' => 'Nous avons mis à jour nos conditions d\'utilisation et te demandons de les accepter à nouveau.',
        'cta' => 'Consulter et accepter maintenant',
        'consequence' => 'Merci d\'accepter à temps — sinon, l\'utilisation sera restreinte à partir de la date d\'entrée en vigueur.',
    ],
    'acknowledgement' => [
        'subject' => 'Important : politique de confidentialité mise à jour',
        'intro' => 'Nous avons mis à jour notre politique de confidentialité et te demandons de prendre connaissance de la nouvelle version.',
        'cta' => 'Consulter et confirmer la lecture maintenant',
        'consequence' => 'Merci d\'en prendre connaissance à temps — sinon, l\'utilisation sera restreinte à partir de la date d\'entrée en vigueur.',
    ],
];

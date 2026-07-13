<?php

declare(strict_types=1);

// UI strings for the publishable stubs (settings page + grace-period banner). The three
// legal kinds stay separately labelled (contracts / acknowledgements / consents). Informal (tu).
return [
    'settings_heading' => 'Tes consentements',
    'contracts_heading' => 'Contrats',
    'acknowledgements_heading' => 'Documents lus',
    'consents_heading' => 'Consentements',
    'withdraw' => 'Retirer',
    'review' => 'Consulter maintenant',
    'submit' => 'Accepter et continuer',
    'all_current' => 'Tout est à jour — rien à faire.',

    // Grace-period remaining time (trans_choice): pluralisation + effective-today case.
    'days_left' => '{0}En vigueur aujourd\'hui|{1}Encore :count jour|[2,*]Encore :count jours',
];

<?php

declare(strict_types=1);

// UI strings for the publishable stubs (settings page + grace-period banner). The three
// legal kinds stay separately labelled (contracts / acknowledgements / consents). Informal (tu).
return [
    'settings_heading' => 'I tuoi consensi',
    'contracts_heading' => 'Contratti',
    'acknowledgements_heading' => 'Documenti letti',
    'consents_heading' => 'Consensi',
    'withdraw' => 'Revoca',
    'review' => 'Consulta ora',
    'submit' => 'Accetta e continua',
    'all_current' => 'Tutto è aggiornato — niente da fare.',

    // Grace-period remaining time (trans_choice): pluralisation + effective-today case.
    'days_left' => '{0}In vigore da oggi|{1}Ancora :count giorno|[2,*]Ancora :count giorni',
    'updated_note' => 'Aggiornato — non è richiesta alcuna azione.',
    'object_review' => 'Vedi o opponiti',
];

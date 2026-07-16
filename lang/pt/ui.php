<?php

declare(strict_types=1);

// UI strings for the publishable stubs (settings page + grace-period banner). The three
// legal kinds stay separately labelled (contracts / acknowledgements / consents). Informal (tu).
return [
    'settings_heading' => 'Os teus consentimentos',
    'contracts_heading' => 'Contratos',
    'acknowledgements_heading' => 'Tomado conhecimento',
    'consents_heading' => 'Consentimentos',
    'withdraw' => 'Retirar',
    'review' => 'Rever agora',
    'submit' => 'Aceitar e continuar',
    'all_current' => 'Está tudo em dia — nada a fazer.',

    // Grace-period remaining time (trans_choice): pluralisation + effective-today case.
    'days_left' => '{0}Em vigor hoje|{1}Falta :count dia|[2,*]Faltam :count dias',
    'updated_note' => 'Atualizado — não é necessária qualquer ação.',
    'object_review' => 'Ver ou opor-te',
];

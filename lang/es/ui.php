<?php

declare(strict_types=1);

// UI strings for the publishable stubs (settings page + grace-period banner). The three
// legal kinds stay separately labelled (contracts / acknowledgements / consents). Informal (tú).
return [
    'settings_heading' => 'Tus consentimientos',
    'contracts_heading' => 'Contratos',
    'acknowledgements_heading' => 'Documentos leídos',
    'consents_heading' => 'Consentimientos',
    'withdraw' => 'Retirar',
    'review' => 'Revisar ahora',
    'submit' => 'Aceptar y continuar',
    'all_current' => 'Todo está al día — no hay nada que hacer.',

    // Grace-period remaining time (trans_choice): pluralisation + effective-today case.
    'days_left' => '{0}En vigor hoy|{1}Queda :count día|[2,*]Quedan :count días',
    'updated_note' => 'Actualizado — no se requiere ninguna acción.',
    'object_review' => 'Revisar u oponerte',
];

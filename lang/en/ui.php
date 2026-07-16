<?php

declare(strict_types=1);

// UI strings for the publishable stubs (settings page + grace-period banner). The three
// legal kinds stay separately labelled (contracts / acknowledgements / consents).
return [
    'settings_heading' => 'Your consents',
    'contracts_heading' => 'Contracts',
    'acknowledgements_heading' => 'Acknowledged',
    'consents_heading' => 'Consents',
    'withdraw' => 'Withdraw',
    'review' => 'Review now',
    'submit' => 'Accept and continue',
    'all_current' => 'Everything is up to date — nothing to do.',

    // Grace-period remaining time (trans_choice): pluralisation + effective-today case.
    'days_left' => '{0}Effective today|{1}:count day left|[2,*]:count days left',
    'updated_note' => 'Updated — no action required.',
    'object_review' => 'Review or object',
];

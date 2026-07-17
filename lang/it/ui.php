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
    // Per-item accessible name for the withdraw control: with N consents, N buttons all
    // labelled just 'withdraw' are indistinguishable in a screen reader's button list
    // (WCAG 2.4.6). The grant was document-specific; the withdrawal must be too.
    'withdraw_for' => 'Revoca: :title',
    'review' => 'Consulta ora',
    // Accessible name for the banner region (it is a `complementary`/`region` landmark,
    // not a live region — a live region present at page load never announces anyway).
    'banner_label' => 'Avvisi legali',

    // The countdown's expired states — a statement about the subject's position, so each
    // is translated, never left to an English fallback on a legal surface.
    'enforced_now' => 'In vigore da ora',
    'objection_closed' => 'Termine di opposizione scaduto',
    'submit' => 'Accetta e continua',
    'all_current' => 'Tutto è aggiornato — niente da fare.',

    // Grace-period remaining time (trans_choice): pluralisation + effective-today case.
    'days_left' => '{0}In vigore da oggi|{1}Ancora :count giorno|[2,*]Ancora :count giorni',
    'updated_note' => 'Aggiornato — non è richiesta alcuna azione.',
    'object_review' => 'Vedi o opponiti',

    // The withdraw confirmation. Art. 7(3) sentence 3: withdrawal must be as easy as
    // giving consent — so this asks once, states the consequence, and never nags.
    'withdraw_confirm_title' => 'Revocare il consenso?',
    'withdraw_confirm_body' => 'Il tuo consenso a «:title» verrà revocato. Ha effetto immediato e non pregiudica la liceità del trattamento precedente.',
    'cancel' => 'Annulla',

    // Admin screens (LegalTextManager / LegalTextEditor).
    'admin_heading' => 'Testi legali',
    'admin_policy' => 'I testi si modificano per lingua, li rivede una persona e poi si pubblicano in tutte le lingue insieme. Una traduzione automatica non può mai essere pubblicata finché qualcuno non la rivede, e la frase di accettazione è testo fisso: non viene mai tradotta automaticamente.',
    'admin_document' => 'Documento',
    'admin_release' => 'Pubblica',
    'admin_not_written' => 'Non scritto',
    'admin_machine' => 'Bozza automatica',
    'admin_needs_update' => 'Da aggiornare',
    'admin_unpublished' => 'Modifiche non pubblicate',
    'admin_release_all' => 'Pubblica tutte le lingue',
    'admin_release_confirm_title' => 'Pubblicare tutte le lingue?',
    'admin_release_confirm_body' => 'Tutte le lingue di questo testo vengono pubblicate insieme come una versione. Non è reversibile: una modifica è una nuova versione superiore.',
    'admin_stale' => 'Il testo di origine è cambiato dopo la revisione di questa traduzione — rivedila prima di pubblicare.',
    'admin_save' => 'Salva',
    'admin_translate' => 'Traduci da :locale',
    'admin_mark_reviewed' => 'Segna come rivisto',
    'admin_preview' => 'Anteprima',
    'withdrawn_confirmation' => 'Consenso ritirato. Ha effetto immediato.',
];

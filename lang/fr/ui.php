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
    // Per-item accessible name for the withdraw control: with N consents, N buttons all
    // labelled just 'withdraw' are indistinguishable in a screen reader's button list
    // (WCAG 2.4.6). The grant was document-specific; the withdrawal must be too.
    'withdraw_for' => 'Retirer : :title',
    'review' => 'Consulter maintenant',
    // Accessible name for the banner region (it is a `complementary`/`region` landmark,
    // not a live region — a live region present at page load never announces anyway).
    'banner_label' => 'Informations légales',

    // The countdown's expired states — a statement about the subject's position, so each
    // is translated, never left to an English fallback on a legal surface.
    'enforced_now' => 'En vigueur maintenant',
    'objection_closed' => "Délai d'opposition expiré",
    'submit' => 'Accepter et continuer',
    'all_current' => 'Tout est à jour — rien à faire.',

    // Grace-period remaining time (trans_choice): pluralisation + effective-today case.
    'days_left' => '{0}En vigueur aujourd\'hui|{1}Encore :count jour|[2,*]Encore :count jours',
    'updated_note' => 'Mis à jour — aucune action requise.',
    'object_review' => 'Consulter ou t’opposer',

    // The withdraw confirmation. Art. 7(3) sentence 3: withdrawal must be as easy as
    // giving consent — so this asks once, states the consequence, and never nags.
    'withdraw_confirm_title' => 'Retirer le consentement ?',
    'withdraw_confirm_body' => "Votre consentement à « :title » sera retiré. Le retrait prend effet immédiatement et n'affecte pas la licéité du traitement antérieur.",
    'cancel' => 'Annuler',

    // Admin screens (LegalTextManager / LegalTextEditor).
    'admin_heading' => 'Textes juridiques',
    'admin_policy' => 'Les textes sont modifiés par langue, relus par une personne, puis publiés dans toutes les langues en même temps. Une traduction automatique ne peut jamais être publiée avant relecture, et la phrase d\'acceptation est un texte fixe : elle n\'est jamais traduite automatiquement.',
    'admin_document' => 'Document',
    'admin_release' => 'Publier',
    'admin_not_written' => 'Non rédigé',
    'admin_machine' => 'Brouillon automatique',
    'admin_needs_update' => 'À mettre à jour',
    'admin_unpublished' => 'Modifications non publiées',
    'admin_release_all' => 'Publier toutes les langues',
    'admin_release_confirm_title' => 'Publier toutes les langues ?',
    'admin_release_confirm_body' => 'Toutes les langues de ce texte sont publiées ensemble en une version. C\'est irréversible : une modification est une nouvelle version supérieure.',
    'admin_stale' => 'Le texte source a changé après la relecture de cette traduction — relis-la avant de publier.',
    'admin_save' => 'Enregistrer',
    'admin_translate' => 'Traduire depuis :locale',
    'admin_mark_reviewed' => 'Marquer comme relu',
    'admin_preview' => 'Aperçu',
    'withdrawn_confirmation' => 'Consentement retiré. Il prend effet immédiatement.',
];

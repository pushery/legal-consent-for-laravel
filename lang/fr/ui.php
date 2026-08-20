<?php

declare(strict_types=1);

// UI strings for the publishable stubs (settings page + grace-period banner). The three
// legal kinds stay separately labeled (contracts / acknowledgements / consents). Informal (tu).
return [
    'settings_heading' => 'Tes consentements',
    'contracts_heading' => 'Contrats',
    'acknowledgements_heading' => 'Documents lus',
    'consents_heading' => 'Consentements',
    'grant' => 'Donner',
    'withdraw' => 'Retirer',
    // Per-item accessible name for the withdraw control: with N consents, N buttons all
    // labeled just 'withdraw' are indistinguishable in a screen reader's button list
    // (WCAG 2.4.6). The grant was document-specific; the withdrawal must be too.
    'grant_for' => 'Donner : :title',
    'withdraw_for' => 'Retirer : :title',
    // Accessible name for the link to a document's full text. Every row that shows a document
    // carries one, and with N rows a link labeled just 'read' is indistinguishable in a screen
    // reader's link list (WCAG 2.4.4) — the title is what makes each one nameable.
    'read_document' => 'Lire :title',
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
    'withdraw_confirm_body' => "Ton consentement à « :title » sera retiré. Le retrait prend effet immédiatement et n'affecte pas la licéité du traitement antérieur.",
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
    'granted_confirmation' => 'Consentement donné. Tu peux le retirer à tout moment.',
    'withdrawn_confirmation' => 'Consentement retiré. Il prend effet immédiatement.',

    // Re-consent submit confirmation + plain admin-stub labels (announced / localized).
    'reconsent_recorded' => 'Merci — ton consentement a été enregistré.',
    'reconsent_changed' => 'Ce document a changé depuis que tu as ouvert cette page. Vérifie la version actuelle avant de donner ton consentement.',
    'reconsent_none_selected' => 'Coche chaque élément que tu acceptes avant de continuer.',
    'admin_body_label' => 'Texte (HTML assaini)',
    'admin_preview_label' => 'Aperçu du texte publié',
    'admin_edit' => 'modifier',
    'admin_edit_for' => 'Modifier :key (:locale)',
    'admin_status_saved' => 'Enregistré. Une relecture est nécessaire avant de pouvoir publier ce texte.',
    'admin_status_source_not_translated' => 'La langue source est rédigée, pas traduite.',
    'admin_status_no_source' => 'Écris d\'abord le texte source — il n\'y a rien à traduire.',
    'admin_status_machine_translated' => 'Traduit automatiquement. Une personne doit le relire avant toute publication.',
    'admin_status_reviewed' => 'Marqué comme relu. Ce texte est désormais publiable.',
    'admin_status_release_blocked' => '« :key » n\'a pas été publié : :reasons',
    'admin_status_released' => '« :key » publié dans :count langue(s) — concerne :affects personne(s).',
];

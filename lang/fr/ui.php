<?php

declare(strict_types=1);

// UI strings for the publishable stubs (settings page + grace-period banner). The three
// legal kinds stay separately labeled ('contracts' / 'acknowledgements' / 'consents'). Informal (tu).
return [
    // The version line of the document-fragment stub. It is not decoration: somebody reading that
    // fragment in a dialog is about to agree to it, and WHICH version they read is the fact a ledger
    // row will later claim. A fragment showing the text and hiding the version would leave that row
    // unverifiable from the only side that matters — the reader's.
    'document_version' => 'Version :version',

    'settings_heading' => 'Tes consentements',
    'contracts_heading' => 'Contrats',
    'acknowledgements_heading' => 'Documents lus',
    'consents_heading' => 'Consentements',

    // Empty states. A group with no entries used to render its heading over nothing, and that is
    // not the rare case it looks like: statusFor() reads the `legal_documents` table, which a
    // freshly installed package has NOTHING in until `legal-consent:publish` runs — so an empty
    // screen is the SHIPPING state every consumer meets first, and three bare headings read as
    // broken rather than as "nothing yet". `nothing_published` covers all three being empty at
    // once, where one sentence says more than three.
    'nothing_published' => 'Il n\'y a encore rien ici : aucun texte juridique n\'a été publié.',
    'nothing_published_title' => 'Pas encore de textes juridiques',
    'nothing_published_description' => 'Tes contrats, les documents lus et tes consentements apparaîtront ici dès que des textes juridiques seront publiés.',
    'contracts_empty' => 'Aucun contrat.',
    'acknowledgements_empty' => 'Aucun document lu.',
    'consents_empty' => 'Aucun consentement.',
    // The double opt-in's middle state. It needs saying because it is the one position the screen
    // cannot show any other way: entered but not yet confirmed looks exactly like never entered,
    // so without this the subject is invited to enter themselves again — and the second request
    // supersedes the first, which stops the confirmation link already in their inbox from working.
    'confirmation_pending' => 'En attente de confirmation : regarde ta boîte de réception.',
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
    // Shown beside the submit while the request is in flight; the button itself carries
    // aria-busy, so this is the sighted half of the same state.
    'working' => 'Un instant…',
    'all_current' => 'Tout est à jour — rien à faire.',
    // The one state a settings screen must not blur: a new major version is waiting, so
    // this is the invitation to do voluntarily what the gate will otherwise compel.
    'action_required' => 'Action requise',
    'retired' => 'N\'est plus proposé',
    'consent_given' => 'Donné',
    'consent_not_given' => 'Non donné',

    // Grace-period remaining time (trans_choice): pluralisation + effective-today case.
    'days_left' => '{0}En vigueur aujourd\'hui|{1}Encore :count jour|[2,*]Encore :count jours',
    'updated_note' => 'Mis à jour — aucune action requise.',
    'object_review' => 'Consulter ou t’opposer',

    // The withdraw confirmation. Art. 7(3) sentence 3: withdrawal must be as easy as
    // giving consent — so this asks once, states the consequence, and never nags.
    'withdraw_confirm_title' => 'Retirer le consentement ?',
    'withdraw_confirm_body' => "Ton consentement à « :title » sera retiré. Le retrait prend effet immédiatement et n'affecte pas la licéité du traitement antérieur.",
    'cancel' => 'Annuler',

    // Admin screens (LegalTextManager / LegalTextEditor).
    'admin_heading' => 'Textes juridiques',
    'admin_policy' => 'Les textes sont modifiés par langue, relus par une personne, puis publiés dans toutes les langues en même temps. Une traduction automatique ne peut jamais être publiée avant relecture, et la phrase d\'acceptation est un texte fixe : elle n\'est jamais traduite automatiquement.',
    'admin_document' => 'Document',
    'admin_release' => 'Publier',
    'admin_not_written' => 'Non rédigé',
    'admin_not_written_short' => 'Absent',
    'review_state_draft' => 'Brouillon',
    'review_state_draft_description' => 'Rédigé, mais personne ne s\'en porte encore garant. Un document auquel les personnes consentent n\'est pas publié tant que l\'une de ses langues est encore un brouillon.',
    'review_state_draft_short' => 'Brouillon',
    'review_state_reviewed' => 'Relu',
    'review_state_reviewed_description' => 'Une personne a lu cette langue et s\'en porte garante. Elle n\'est en ligne qu\'une fois le document publié.',
    'review_state_reviewed_short' => 'Relu',
    'blocking_not_draft_backed' => 'ce document ne tire pas son texte du magasin de brouillons',
    'blocking_no_draft' => 'aucun brouillon n\'a encore été écrit',
    'blocking_not_reviewed' => 'pas encore relu par une personne',
    'blocking_stale_translation' => 'le texte source a changé après la relecture de cette traduction',
    'blocking_no_change_description' => 'aucune description de la modification n\'a encore été écrite',
    'blocking_incomplete_change_description' => 'la description de la modification n\'a ni titre ni impact',
    'blocking_stale_change_description' => 'le texte juridique a changé après la rédaction de cette description',
    'admin_machine' => 'Brouillon automatique',
    'admin_machine_short' => 'Automatique',
    'admin_needs_update' => 'À mettre à jour',
    'admin_needs_update_short' => 'Obsolète',
    'admin_unpublished' => 'Modifications non publiées',
    'admin_unpublished_short' => 'Inédit',
    'admin_release_all' => 'Publier toutes les langues',
    'admin_release_confirm_title' => 'Publier toutes les langues ?',
    'admin_release_confirm_body' => 'Toutes les langues de ce texte sont publiées ensemble en une version. C\'est irréversible : une modification est une nouvelle version supérieure.',
    'admin_stale' => 'Le texte source a changé après la relecture de cette traduction — relis-la avant de publier.',
    'admin_stale_unconfirmed' => 'Cette traduction n’a jamais été confirmée par rapport au texte source — relis-la avant de publier.',
    'admin_save' => 'Enregistrer',
    'admin_translate' => 'Traduire depuis :locale',
    'admin_mark_reviewed' => 'Marquer comme relu',
    'admin_review_state' => 'État de relecture :',
    'admin_discard' => 'Supprimer le brouillon',
    'admin_discard_confirm_title' => 'Supprimer le brouillon ?',
    'admin_discard_confirm_body' => 'Le brouillon pour :locale sera supprimé. Les versions publiées ne sont pas touchées.',
    'admin_status_draft_discarded' => 'Le brouillon a été supprimé.',
    'admin_status_source_not_discarded' => 'Le brouillon source ne peut pas être supprimé : chaque traduction y mesure sa fraîcheur.',
    'admin_status_no_draft_to_discard' => 'Il n\'existe aucun brouillon pour cette langue.',
    'admin_preview' => 'Aperçu',
    'granted_confirmation' => 'Consentement donné. Tu peux le retirer à tout moment.',
    'withdrawn_confirmation' => 'Consentement retiré. Il prend effet immédiatement.',
    'objected_confirmation' => 'Opposition enregistrée, avec l\'heure à laquelle tu l\'as envoyée.',
    'terminated_confirmation' => 'Résiliation enregistrée, avec l\'heure à laquelle tu l\'as envoyée.',
    // The refusal a subject can actually reach — a stale page, a hand-built post or a custom
    // stub asking to withdraw something that was never a consent. It states the position
    // without the document key or the internal type: those are the operator's business, and
    // they go to the log.
    'not_withdrawable' => 'Ce document ne peut pas être retiré — seul un consentement que tu as donné librement peut être retiré.',

    // Re-consent submit confirmation + plain admin-stub labels (announced / localized).
    'reconsent_recorded' => 'Merci — ton consentement a été enregistré.',
    'reconsent_changed' => 'Ce document a changé depuis que tu as ouvert cette page. Vérifie la version actuelle avant de donner ton consentement.',
    'reconsent_none_selected' => 'Coche chaque élément que tu acceptes avant de continuer.',
    'admin_body_label' => 'Texte (HTML assaini)',
    'admin_preview_label' => 'Aperçu du texte publié',
    'admin_edit' => 'modifier',
    'admin_edit_for' => 'Modifier :key (:locale)',
    'admin_edit_for_state' => 'Modifier :key (:locale) — :state',
    'admin_status_saved' => 'Enregistré. Une relecture est nécessaire avant de pouvoir publier ce texte.',
    'admin_status_not_saved' => 'Non enregistré — :reason',
    'admin_status_source_not_translated' => 'La langue source est rédigée, pas traduite.',
    'admin_status_no_source' => 'Écris d\'abord le texte source — il n\'y a rien à traduire.',
    // The status a QUEUED translation reports. It names where the result will appear rather than
    // asking the reader to do anything, because there is nothing for them to do: the page polls
    // while the job runs and stops when it stops.
    'admin_status_translation_queued' => 'La traduction est en cours. Elle apparaîtra ici une fois terminée.',

    'admin_status_machine_translated' => 'Traduit automatiquement. Une personne doit le relire avant toute publication.',
    'admin_status_translation_failed' => 'La traduction n\'est pas allée à son terme. Le brouillon est inchangé, vous pouvez réessayer.',

    /** The button that closes the document dialog on a consent form. */
    'dialog_close' => 'Fermer',
    /** The link in the document dialog that opens the same text as its own page, in a new tab. */
    'dialog_open_page' => 'Ouvrir en tant que page',
    'dialog_loading' => 'Chargement du texte …',
    'dialog_failed' => 'Le texte n\'a pas pu être chargé.',
    'admin_status_reviewed' => 'Marqué comme relu. Ce texte est désormais publiable.',
    'admin_status_release_blocked' => '« :key » n\'a pas été publié : :reasons',
    'admin_status_released' => '« :key » publié dans :count langue(s) — concerne :affects personne(s).',
    'admin_deemed_heading' => 'Publier avec un délai d’opposition',
    'admin_deemed_explainer' => 'Une modification à consentement tacite engage si le délai d’opposition se termine sans opposition. Le délai doit respecter au minimum le préavis légal.',
    'admin_deemed_announce' => 'Annoncer le',
    'admin_deemed_deadline' => 'Date limite d’opposition',
    'admin_deemed_enforce' => 'En vigueur à partir du',
    'admin_deemed_regime' => 'Régime juridique',
    'admin_deemed_regime_none' => 'Non classé',
    'admin_deemed_change_class' => 'Classe de modification',
    'admin_deemed_change_class_hint' => 'Votre propre étiquette de classification, celle que la ligne de commande accepte — par exemple agb_minor_peripheral ou privacy_material.',
    'admin_deemed_offers_termination' => 'Accorde un droit de résiliation gratuit',
    'admin_deemed_keeps_unmodified' => 'Garde la version inchangée disponible',
    'admin_deemed_submit' => 'Publier avec un délai d’opposition',
    'admin_status_deemed_window_rejected' => 'Non publié — le délai d’opposition a été refusé : :reason',
    'lead_time_too_short' => 'une modification substantielle de « :document » exige au moins :days jours entre l’annonce (:announce) et l’entrée en vigueur (:enforce)',
    'lead_time_too_short_objection' => 'une modification substantielle de « :document » exige au moins :days jours entre l’annonce (:announce) et le délai d’opposition (:deadline)',
];

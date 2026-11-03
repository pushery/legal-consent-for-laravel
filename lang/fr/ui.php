<?php

declare(strict_types=1);

// UI strings for the publishable stubs (settings page + grace-period banner). The three
// legal kinds stay separately labeled ('contracts' / 'acknowledgements' / 'consents'). Informal (tu).
return [
    // The version line of the document-fragment stub. It is not decoration: somebody reading that
    // fragment in a dialog is about to agree to it, and which version they read is the fact a ledger
    // row will later claim. A fragment showing the text and hiding the version would leave that row
    // unverifiable from the only side that matters — the reader's.
    'document_version' => 'Version :version',
    'document_language' => 'Langue de ce texte : :language',

    'settings_heading' => 'Tes consentements',
    'contracts_heading' => 'Contrats',
    'acknowledgements_heading' => 'Documents lus',
    'consents_heading' => 'Consentements',

    // Empty states. A group with no entries used to render its heading over nothing, and that is
    // not the rare case it looks like: statusFor() reads the `legal_documents` table, which a
    // freshly installed package has nothing in until `legal-consent:publish` runs — so an empty
    // screen is the shipping state every consumer meets first, and three bare headings read as
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

    // Grace-period remaining time (trans_choice): pluralization + effective-today case.
    'days_left' => '{0}En vigueur aujourd\'hui|{1}Encore :count jour|[2,*]Encore :count jours',
    'updated_note' => 'Mis à jour — aucune action requise.',
    'object_review' => 'Consulter ou t’opposer',

    // The withdraw confirmation. Art. 7(3) sentence 3: withdrawal must be as easy as
    // giving consent — so this asks once, states the consequence, and never nags.
    'withdraw_confirm_title' => 'Retirer le consentement ?',
    'withdraw_confirm_body' => "Ton consentement à « :title » sera retiré. Cela prend effet immédiatement et n'affecte pas la licéité du traitement antérieur.",
    'cancel' => 'Annuler',
    // The deemed-consent changes on the re-consent form, the page the notice and the banner send the
    // subject to, until they take effect. Objecting and terminating each write an irreversible row,
    // so each asks once.
    'upcoming_changes_heading' => 'Modifications contractuelles à venir',
    'object' => 'S’opposer',
    'object_for' => 'S’opposer : :title',
    'terminate' => 'Résilier gratuitement',
    'terminate_for' => 'Résilier gratuitement : :title',
    'object_confirm_title' => 'S’opposer à la modification ?',
    'object_confirm_body' => 'La modification de « :title » ne vaudra alors pas acceptation de ta part. Ton opposition est enregistrée avec l’heure à laquelle tu l’envoies.',
    'terminate_confirm_title' => 'Résilier gratuitement ?',
    'terminate_confirm_body' => 'Tu résilies gratuitement le contrat « :title » avant que la modification ne prenne effet. Ta résiliation est enregistrée avec l’heure à laquelle tu l’envoies.',

    // Admin screens (LegalTextManager / LegalTextEditor).
    'admin_heading' => 'Textes juridiques',
    'admin_policy' => 'Les textes sont modifiés par langue, relus par une personne, puis publiés dans toutes les langues en même temps. Une traduction automatique ne peut jamais être publiée avant relecture, et la phrase d\'acceptation est un texte fixe : elle n\'est jamais traduite automatiquement.',
    'admin_document' => 'Document',
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
    'admin_release_leaves_out' => 'Cette publication laisse de côté :languages. Ces langues gardent la version déjà publiée, s’il y en a une, jusqu’à la publication de leur propre traduction.',
    'admin_release_headings' => 'Titres : :headings',
    'admin_stale' => 'Le texte source a changé après la relecture de cette traduction — relis-la avant de publier.',
    'admin_stale_unconfirmed' => 'Cette traduction n’a jamais été confirmée par rapport au texte source — relis-la avant de publier.',
    'admin_save' => 'Enregistrer',
    'admin_translate' => 'Traduire depuis :locale',
    'admin_translate_running' => 'Traduction en cours…',
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
    'reconsent_box_unticked' => 'Pas encore cochée.',
    'admin_body_label' => 'Texte (HTML assaini)',
    'admin_preview_label' => 'Aperçu du texte publié',
    'admin_edit_for_state' => 'Modifier :key (:locale) — :state',
    'admin_status_saved' => 'Enregistré. Une relecture est nécessaire avant de pouvoir publier ce texte.',
    'admin_status_not_saved' => 'Non enregistré — :reason',
    'admin_status_source_not_translated' => 'La langue source est rédigée, pas traduite.',
    'admin_status_no_source' => 'Écris d\'abord le texte source — il n\'y a rien à traduire.',
    // The status a queued translation reports. It names where the result will appear rather than
    // asking the reader to do anything, because there is nothing for them to do: the page polls
    // while the job runs and stops when it stops.
    'admin_status_translation_queued' => 'La traduction est en cours. Elle apparaîtra ici une fois terminée.',
    'admin_status_translation_running' => 'Une traduction de ce brouillon est déjà en cours. Le résultat apparaîtra ici une fois qu\'elle sera terminée.',

    'admin_status_machine_translated' => 'Traduit automatiquement. Une personne doit le relire avant toute publication.',
    'admin_status_translation_failed' => 'La traduction n\'est pas allée à son terme. Le brouillon est inchangé, tu peux réessayer.',

    /** The button that closes the document dialog on a consent form. */
    'dialog_close' => 'Fermer',
    /** The link in the document dialog that opens the same text as its own page, in a new tab. */
    'dialog_open_page' => 'Ouvrir en tant que page',
    'dialog_loading' => 'Chargement du texte …',
    'dialog_failed' => 'Le texte n\'a pas pu être chargé.',
    'admin_status_reviewed' => 'Marqué comme relu. Ce texte est désormais publiable.',
    'admin_status_no_draft_to_review' => 'Il n\'existe aucun brouillon pour cette langue, il n\'y a donc rien à marquer comme relu.',
    'admin_status_changed_before_review' => 'Le texte a été modifié après son affichage ici, il n\'a donc pas été marqué comme relu. Recharge-le et relis la version actuelle.',
    'admin_status_release_blocked' => '« :key » n\'a pas été publié : :reasons',
    'admin_status_released' => '« :key » publié dans :count langue(s) — concerne :affects personne(s).',
    'admin_status_released_uncounted' => '« :key » publié dans :count langue(s). Le temps prévu n\'a pas suffi pour compter les personnes concernées.',
    'admin_status_wording_changed' => 'La phrase d’acceptation a changé en :languages, et chaque consentement donné désormais enregistre la nouvelle.',
    'admin_save_and_release' => 'Enregistrer et publier',
    'admin_release_dialog_title' => 'Publier « :document »',
    'admin_release_dialog_mode' => 'Comment il est publié',
    'admin_release_dialog_languages' => 'Langues',
    'admin_release_dialog_left_out' => 'Restent à l’écart',
    'admin_release_dialog_version' => 'Version',
    'admin_release_dialog_dates' => 'Dates',
    'admin_release_dialog_regime' => 'Régime juridique',
    'admin_release_dialog_heading' => 'Titre',
    'admin_release_dialog_sentence' => 'Phrase d’acceptation',
    'admin_release_dialog_generic_sentence' => 'La phrase générale, car ce document n’en a pas de propre. Donne-lui la sienne dans lang/vendor/legal-consent/:locale/wording.php.',
    'admin_release_dialog_window' => 'annonce le :announce, délai d’opposition le :deadline, en vigueur le :enforce',
    'admin_release_dialog_now' => 'en vigueur aussitôt',
    'admin_release_dialog_reviewer' => 'En confirmant, tu valides aussi :language comme relu par toi.',
    'admin_release_dialog_confirm' => 'Confirmer et publier',
    'admin_status_release_dialog_open' => 'Enregistré. Vérifie ce que fait la publication, puis confirme-la.',
    'admin_status_mode_not_offered' => 'Ce document ne peut pas être publié ainsi.',
    // The modes "save and publish" offers, by NoticeMode value: a label and its consequence.
    'admin_release_modes' => [
        'active_reconsent' => ['label' => 'Demander à tous de l’accepter à nouveau', 'consequence' => 'Tout le monde doit accepter la nouvelle version avant de continuer. Elle devient une nouvelle version majeure.'],
        'deemed_consent' => ['label' => 'Avec un délai d’opposition', 'consequence' => 'Tout le monde est averti et peut s’opposer jusqu’au délai d’opposition ; qui se tait accepte. Les dates du formulaire avec délai d’opposition s’appliquent.'],
        'info_push' => ['label' => 'L’annoncer', 'consequence' => 'Tout le monde est averti, et personne ne doit rien accepter.'],
        'silent_editorial' => ['label' => 'Sans bruit, comme modification rédactionnelle', 'consequence' => 'Personne ne remarque rien : une modification rédactionnelle sans avis ni acceptation, comme une faute de frappe.'],
    ],
    'admin_translation_heading' => 'Ajouter :language',
    'admin_translation_explainer' => 'Ne change rien au contrat et ajoute seulement la langue : :language reçoit la version :version, qui s’applique déjà dans les autres langues. Personne ne doit l’accepter à nouveau.',
    'admin_translation_submit' => 'Publier la langue',
    'admin_status_translation_published' => ':language est publié, dans la version :version. Rien ne change au contrat.',
    'admin_status_translation_not_offered' => 'Cette langue ne peut pas être ajoutée seule : elle est déjà publiée, aucune autre langue n’a de version en vigueur qui le permette, ou la source porte déjà une nouvelle version. Publie-la avec la prochaine modification.',
    'admin_first_publication_heading' => 'Publier pour la première fois',
    // By the mode a first publication takes, DocumentType::materialChangeMode().
    'admin_first_publication_explainer' => [
        'active_reconsent' => 'Ce texte n’a jamais été publié. Il s’applique dès maintenant, et chaque personne l’accepte pour la première fois lors de sa prochaine visite. Aucun délai ne s’applique, car il n’y a encore rien à quoi s’opposer.',
        'info_push' => 'Ce texte n’a jamais été publié. Il s’applique dès maintenant et est annoncé à toutes les personnes concernées. Aucun délai ne s’applique, car il n’y a encore rien à quoi s’opposer.',
        'silent_editorial' => 'Ce texte n’a jamais été publié. Il s’applique dès maintenant et sort sans bruit : personne ne doit l’accepter, et personne n’est averti.',
        'deemed_consent' => 'Ce texte n’a jamais été publié. Il s’applique dès maintenant. Aucun délai ne s’applique, car il n’y a encore rien à quoi s’opposer.',
    ],
    'admin_first_publication_submit' => 'Publier maintenant',
    'admin_status_already_published' => 'Ce texte est déjà publié. Publie une modification avec le formulaire ci-dessous.',
    'admin_deemed_heading' => 'Publier avec un délai d’opposition',
    'admin_deemed_explainer' => 'Une modification à consentement tacite engage si le délai d’opposition se termine sans opposition. Le délai doit respecter au minimum le préavis légal.',
    'admin_deemed_announce' => 'Annoncer le',
    'admin_deemed_deadline' => 'Date limite d’opposition',
    'admin_deemed_enforce' => 'En vigueur à partir du',
    'admin_deemed_regime' => 'Régime juridique',
    'admin_deemed_regime_none' => 'Non classé',
    'admin_deemed_change_class' => 'Classe de modification (facultatif)',
    'admin_deemed_change_class_hint' => 'Un libellé à toi pour retrouver cette modification plus tard, par exemple lors d’un audit. La ligne de commande accepte le même libellé. Tu peux laisser le champ vide.',
    'admin_deemed_offers_termination' => 'Accorde un droit de résiliation gratuit',
    'admin_deemed_keeps_unmodified' => 'Garde la version inchangée disponible',
    'admin_deemed_offers_termination_hint' => 'Nécessaire lorsque la loi accorde un droit de résiliation gratuit pour une telle modification, par exemple pour les services de paiement (§ 675g Abs. 2 BGB), les produits numériques (§ 327r Abs. 3 BGB) ou les plateformes pour entreprises utilisatrices (art. 3, par. 2, règlement (UE) 2019/1150).',
    'admin_deemed_keeps_unmodified_hint' => 'Qui s’oppose garde la version qu’il a déjà. Si un produit numérique destiné aux consommateurs reste ainsi utilisable sans changement ni frais supplémentaires, le droit de résiliation disparaît (§ 327r Abs. 4 BGB).',
    'admin_deemed_lead_mode' => '{1} Au moins un jour entre l’annonce et le délai d’opposition, la référence pour un consentement tacite (§ 308 Nr. 5 BGB).|[2,*] Au moins :count jours entre l’annonce et le délai d’opposition, la référence pour un consentement tacite (§ 308 Nr. 5 BGB).',
    'admin_deemed_lead_statute' => '{1} Au moins un jour entre l’annonce et le délai d’opposition, fixé par la loi (:source).|[2,*] Au moins :count jours entre l’annonce et le délai d’opposition, fixés par la loi (:source).',
    'admin_deemed_lead_statute_months' => '{1} Au moins un mois civil, donc au moins :days jours, entre l’annonce et le délai d’opposition, fixé par la loi (:source).|[2,*] Au moins :count mois civils, donc au moins :days jours, entre l’annonce et le délai d’opposition, fixés par la loi (:source).',
    'admin_deemed_lead_regime_setting' => '{1} Au moins un jour entre l’annonce et le délai d’opposition, comme réglé pour ce régime.|[2,*] Au moins :count jours entre l’annonce et le délai d’opposition, comme réglé pour ce régime.',
    'admin_deemed_lead_document' => '{1} Au moins un jour entre l’annonce et le délai d’opposition, comme réglé pour ce document.|[2,*] Au moins :count jours entre l’annonce et le délai d’opposition, comme réglé pour ce document.',
    'admin_deemed_lead_none' => 'Aucun délai minimum ne s’applique à ce régime.',
    // The minimum's source where a statute sets it, by regime (LegalDocumentPublisher::STATUTORY_FLOORS).
    'admin_deemed_lead_sources' => [
        'psd2_675g' => '§ 675g Abs. 1 BGB',
        'p2b' => 'art. 3, par. 2, règlement (UE) 2019/1150',
        'eecc' => 'art. 105, par. 4, directive (UE) 2018/1972',
    ],
    // The regimes of LegalDocumentPublisher::REGIMES, named and explained for the editor's form.
    'admin_regimes' => [
        'bgb_agb' => ['name' => 'Conditions générales (§§ 305 s. BGB)', 'description' => 'Conditions générales selon le droit allemand, la règle pour la plupart des contrats.'],
        'psd2_675g' => ['name' => 'Services de paiement (§ 675g BGB)', 'description' => 'Un contrat-cadre de services de paiement. La loi exige au moins deux mois de préavis.'],
        'dcd_327r' => ['name' => 'Produits numériques (§ 327r BGB)', 'description' => 'Modifications de contenus ou services numériques pour les consommateurs, annoncées dans un délai raisonnable.'],
        'gdpr' => ['name' => 'Protection des données (RGPD)', 'description' => 'Modifications d’une politique de confidentialité. Le délai est un réglage, pas un chiffre fixé par la loi.'],
        'p2b' => ['name' => 'Plateformes pour entreprises utilisatrices (règlement P2B)', 'description' => 'Conditions d’un service d’intermédiation en ligne envers les entreprises utilisatrices, avec au moins 15 jours de préavis.'],
        'eecc' => ['name' => 'Communications électroniques (CCEE)', 'description' => 'Contrats de services de communications électroniques, avec au moins un mois de préavis.'],
    ],
    'admin_preview_show' => 'Afficher l’aperçu',
    'admin_deemed_submit' => 'Publier avec un délai d’opposition',
    'admin_status_deemed_window_rejected' => 'Non publié — le délai d’opposition a été refusé : :reason',
    'admin_status_deemed_window_incomplete' => 'Non publié — une publication avec un délai d’opposition exige ces champs : :fields',
    'admin_status_deemed_dates_unreadable' => 'Non publié — ces dates sont illisibles : :fields. Format attendu : AAAA-MM-JJ.',
    'lead_time_too_short' => 'une modification substantielle de « :document » exige au moins :days jours entre l’annonce (:announce) et l’entrée en vigueur (:enforce)',
    'lead_time_too_short_objection' => 'une modification substantielle de « :document » exige au moins :days jours entre l’annonce (:announce) et le délai d’opposition (:deadline)',
    'notice_timeline_inverted' => 'l’annonce de « :document » (:announce) tombe après son entrée en vigueur (:enforce) ; une modification doit être annoncée avant d’entrer en vigueur',
    'publish_refused_version_taken_in_another_mode' => 'la version :version est déjà publiée en :language avec ce texte sous un autre mode d’information ; un autre mode exige une nouvelle version',
    'publish_refused_binding_document_became_informational' => 'elle est désormais enregistrée comme page d’information, alors que sa version active :active_version en :language demande l’acceptation des personnes ; conserve sa base juridique ou enregistre la page sous une nouvelle clé',
    'publish_refused_version_lower_than_active' => 'la version :version est inférieure à la version active :active_version en :language ; publie plutôt une version supérieure avec l’ancien texte',
    'publish_refused_mode_differs_across_languages' => 'la version majeure :major est déjà active en :other_language sous un autre mode d’information, et :language partirait sous celui-ci ; toutes les langues d’une même modification exigent le même mode',
    'publish_refused_major_needs_reconsent' => 'la version :version relève la version majeure en :language, ce qui demande à chacun d’accepter à nouveau la modification ; publie-la comme nouveau consentement actif',
    'publish_refused_gating_mode_keeps_the_major' => 'la version :version garde la version majeure :major en :language, un nouveau consentement n’atteindrait donc personne qui a déjà accepté ; publie-la comme :next',
    'publish_refused_objection_deadline_not_before_effective_date' => 'la date limite d’opposition (:deadline) doit tomber avant le jour d’entrée en vigueur de la modification (:enforce)',
    'publish_refused_change_class_too_long' => 'la classe de modification compte :length caractères et ne peut en compter que :max',
    'publish_refused_title_is_the_key' => 'il n’a pas de titre en :language, et sa clé « :key » resterait le titre de la version ; ajoute le titre dans :path',
    'draft_refused_no_translator' => 'aucune traduction automatique n’est configurée pour cette application ; traduis le texte à la main ou demande à un développeur de connecter un traducteur',
    'draft_refused_too_large' => 'le texte fait :size Ko, et un texte juridique ne peut pas dépasser :limit Ko',
    'draft_refused_unparsable' => 'le texte n’a pas pu être lu jusqu’au bout et aurait été tronqué ; la cause habituelle est un balisage imbriqué très profondément',
    'draft_refused_translation_truncated' => 'la réponse du traducteur a été coupée à l’une de ses limites, et une traduction qui s’arrête au milieu d’une clause n’est pas conservée ; découpe ou raccourcis le texte et réessaie, ou traduis-le à la main',
    'draft_refused_translation_declined' => 'le traducteur a refusé ce texte ; réessaie ou traduis-le à la main',
    // The name of each ledger action, for a screen that shows an entry: ConsentAction::label().
    'actions' => [
        'granted' => 'Consentement donné',
        'acknowledged' => 'Pris connaissance',
        're_accepted' => 'Accepté à nouveau',
        'withdrawn' => 'Retiré',
        'declined' => 'Refusé',
        'parental' => 'Consentement parental',
        'objected' => 'Opposition formée',
        'terminated' => 'Résilié',
        'deemed_accepted' => 'Acceptation tacite',
        'optin_requested' => 'Opt-in demandé',
        'confirmed' => 'Opt-in confirmé',
    ],
];

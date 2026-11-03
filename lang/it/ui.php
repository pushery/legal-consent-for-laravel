<?php

declare(strict_types=1);

// UI strings for the publishable stubs (settings page + grace-period banner). The three
// legal kinds stay separately labeled ('contracts' / 'acknowledgements' / 'consents'). Informal (tu).
return [
    // The version line of the document-fragment stub. It is not decoration: somebody reading that
    // fragment in a dialog is about to agree to it, and which version they read is the fact a ledger
    // row will later claim. A fragment showing the text and hiding the version would leave that row
    // unverifiable from the only side that matters — the reader's.
    'document_version' => 'Versione :version',
    'document_language' => 'Lingua di questo testo: :language',

    'settings_heading' => 'I tuoi consensi',
    'contracts_heading' => 'Contratti',
    'acknowledgements_heading' => 'Documenti letti',
    'consents_heading' => 'Consensi',

    // Empty states. A group with no entries used to render its heading over nothing, and that is
    // not the rare case it looks like: statusFor() reads the `legal_documents` table, which a
    // freshly installed package has nothing in until `legal-consent:publish` runs — so an empty
    // screen is the shipping state every consumer meets first, and three bare headings read as
    // broken rather than as "nothing yet". `nothing_published` covers all three being empty at
    // once, where one sentence says more than three.
    'nothing_published' => 'Qui non c\'è ancora nulla: non è stato pubblicato alcun testo legale.',
    'nothing_published_title' => 'Ancora nessun testo legale',
    'nothing_published_description' => 'I tuoi contratti, i documenti letti e i tuoi consensi compariranno qui non appena saranno pubblicati dei testi legali.',
    'contracts_empty' => 'Nessun contratto.',
    'acknowledgements_empty' => 'Nessun documento letto.',
    'consents_empty' => 'Nessun consenso.',
    // The double opt-in's middle state. It needs saying because it is the one position the screen
    // cannot show any other way: entered but not yet confirmed looks exactly like never entered,
    // so without this the subject is invited to enter themselves again — and the second request
    // supersedes the first, which stops the confirmation link already in their inbox from working.
    'confirmation_pending' => 'In attesa di conferma: controlla la tua casella di posta.',
    'grant' => 'Concedi',
    'withdraw' => 'Revoca',
    // Per-item accessible name for the withdraw control: with N consents, N buttons all
    // labeled just 'withdraw' are indistinguishable in a screen reader's button list
    // (WCAG 2.4.6). The grant was document-specific; the withdrawal must be too.
    'grant_for' => 'Concedi: :title',
    'withdraw_for' => 'Revoca: :title',
    // Accessible name for the link to a document's full text. Every row that shows a document
    // carries one, and with N rows a link labeled just 'read' is indistinguishable in a screen
    // reader's link list (WCAG 2.4.4) — the title is what makes each one nameable.
    'read_document' => 'Leggi :title',
    'review' => 'Consulta ora',
    // Accessible name for the banner region (it is a `complementary`/`region` landmark,
    // not a live region — a live region present at page load never announces anyway).
    'banner_label' => 'Avvisi legali',

    // The countdown's expired states — a statement about the subject's position, so each
    // is translated, never left to an English fallback on a legal surface.
    'enforced_now' => 'In vigore da ora',
    'objection_closed' => 'Termine di opposizione scaduto',
    'submit' => 'Accetta e continua',
    // Shown beside the submit while the request is in flight; the button itself carries
    // aria-busy, so this is the sighted half of the same state.
    'working' => 'Un momento…',
    'all_current' => 'Tutto è aggiornato — niente da fare.',
    // The one state a settings screen must not blur: a new major version is waiting, so
    // this is the invitation to do voluntarily what the gate will otherwise compel.
    'action_required' => 'Azione necessaria',
    'retired' => 'Non più offerto',
    'consent_given' => 'Concesso',
    'consent_not_given' => 'Non concesso',

    // Grace-period remaining time (trans_choice): pluralization + effective-today case.
    'days_left' => '{0}In vigore da oggi|{1}Ancora :count giorno|[2,*]Ancora :count giorni',
    'updated_note' => 'Aggiornato — non è richiesta alcuna azione.',
    'object_review' => 'Vedi o opponiti',

    // The withdraw confirmation. Art. 7(3) sentence 3: withdrawal must be as easy as
    // giving consent — so this asks once, states the consequence, and never nags.
    'withdraw_confirm_title' => 'Revocare il consenso?',
    'withdraw_confirm_body' => 'Il tuo consenso a «:title» verrà revocato. Ha effetto immediato e non pregiudica la liceità del trattamento precedente.',
    'cancel' => 'Annulla',
    // The deemed-consent changes on the re-consent form, the page the notice and the banner send the
    // subject to, until they take effect. Objecting and terminating each write an irreversible row,
    // so each asks once.
    'upcoming_changes_heading' => 'Modifiche contrattuali in arrivo',
    'object' => 'Opponiti',
    'object_for' => 'Opponiti: :title',
    'terminate' => 'Recedi gratuitamente',
    'terminate_for' => 'Recedi gratuitamente: :title',
    'object_confirm_title' => 'Opporsi alla modifica?',
    'object_confirm_body' => 'La modifica a «:title» non varrà allora come tua accettazione. La tua opposizione viene registrata con l’ora in cui la invii.',
    'terminate_confirm_title' => 'Recedere gratuitamente?',
    'terminate_confirm_body' => 'Recedi gratuitamente dal contratto «:title» prima che la modifica abbia effetto. Il tuo recesso viene registrato con l’ora in cui lo invii.',

    // Admin screens (LegalTextManager / LegalTextEditor).
    'admin_heading' => 'Testi legali',
    'admin_policy' => 'I testi si modificano per lingua, li rivede una persona e poi si pubblicano in tutte le lingue insieme. Una traduzione automatica non può mai essere pubblicata finché qualcuno non la rivede, e la frase di accettazione è testo fisso: non viene mai tradotta automaticamente.',
    'admin_document' => 'Documento',
    'admin_not_written' => 'Non scritto',
    'admin_not_written_short' => 'Assente',
    'review_state_draft' => 'Bozza',
    'review_state_draft_description' => 'Scritto, ma nessuno ne risponde ancora. Un documento che le persone accettano non viene pubblicato finché una delle sue lingue è ancora una bozza.',
    'review_state_draft_short' => 'Bozza',
    'review_state_reviewed' => 'Revisionato',
    'review_state_reviewed_description' => 'Una persona ha letto questa lingua e ne risponde. È online solo quando il documento viene pubblicato.',
    'review_state_reviewed_short' => 'Revisionato',
    'blocking_not_draft_backed' => 'questo documento non prende il suo testo dall’archivio delle bozze',
    'blocking_no_draft' => 'non è stata ancora scritta alcuna bozza',
    'blocking_not_reviewed' => 'non ancora revisionato da una persona',
    'blocking_stale_translation' => 'il testo di partenza è cambiato dopo la revisione di questa traduzione',
    'blocking_no_change_description' => 'non è stata ancora scritta alcuna descrizione della modifica',
    'blocking_incomplete_change_description' => 'alla descrizione della modifica manca il titolo o l\'impatto',
    'blocking_stale_change_description' => 'il testo legale è cambiato dopo la stesura di questa descrizione',
    'admin_machine' => 'Bozza automatica',
    'admin_machine_short' => 'Automatica',
    'admin_needs_update' => 'Da aggiornare',
    'admin_needs_update_short' => 'Obsoleto',
    'admin_unpublished' => 'Modifiche non pubblicate',
    'admin_unpublished_short' => 'Inedito',
    'admin_release_all' => 'Pubblica tutte le lingue',
    'admin_release_confirm_title' => 'Pubblicare tutte le lingue?',
    'admin_release_confirm_body' => 'Tutte le lingue di questo testo vengono pubblicate insieme come una versione. Non è reversibile: una modifica è una nuova versione superiore.',
    'admin_release_leaves_out' => 'Questa pubblicazione esclude :languages. Queste lingue mantengono la versione già pubblicata, se esiste, finché non viene pubblicata la loro traduzione.',
    'admin_release_headings' => 'Intestazioni: :headings',
    'admin_stale' => 'Il testo di origine è cambiato dopo la revisione di questa traduzione — rivedila prima di pubblicare.',
    'admin_stale_unconfirmed' => 'Questa traduzione non è mai stata confermata rispetto al testo di origine — rivedila prima di pubblicare.',
    'admin_save' => 'Salva',
    'admin_translate' => 'Traduci da :locale',
    'admin_translate_running' => 'Traduzione in corso…',
    'admin_mark_reviewed' => 'Segna come rivisto',
    'admin_review_state' => 'Stato di revisione:',
    'admin_discard' => 'Elimina bozza',
    'admin_discard_confirm_title' => 'Eliminare la bozza?',
    'admin_discard_confirm_body' => 'La bozza per :locale verrà eliminata. Le versioni pubblicate restano intatte.',
    'admin_status_draft_discarded' => 'La bozza è stata eliminata.',
    'admin_status_source_not_discarded' => 'La bozza di origine non può essere eliminata: ogni traduzione vi misura la propria freschezza.',
    'admin_status_no_draft_to_discard' => 'Per questa lingua non esiste alcuna bozza.',
    'admin_preview' => 'Anteprima',
    'granted_confirmation' => 'Consenso concesso. Puoi revocarlo quando vuoi.',
    'withdrawn_confirmation' => 'Consenso revocato. Ha effetto immediato.',
    'objected_confirmation' => 'Opposizione registrata, con l\'ora in cui l\'hai inviata.',
    'terminated_confirmation' => 'Recesso registrato, con l\'ora in cui l\'hai inviato.',
    // The refusal a subject can actually reach — a stale page, a hand-built post or a custom
    // stub asking to withdraw something that was never a consent. It states the position
    // without the document key or the internal type: those are the operator's business, and
    // they go to the log.
    'not_withdrawable' => 'Questo documento non può essere revocato — puoi revocare solo un consenso che hai dato liberamente.',

    // Re-consent submit confirmation + plain admin-stub labels (announced / localized).
    'reconsent_recorded' => 'Grazie — il tuo consenso è stato registrato.',
    'reconsent_changed' => 'Questo documento è cambiato da quando hai aperto questa pagina. Controlla la versione attuale prima di dare il consenso.',
    'reconsent_none_selected' => 'Seleziona ogni voce che accetti prima di continuare.',
    'reconsent_box_unticked' => 'Non ancora selezionata.',
    'admin_body_label' => 'Testo (HTML sanificato)',
    'admin_preview_label' => 'Anteprima del testo pubblicato',
    'admin_edit_for_state' => 'Modifica :key (:locale) — :state',
    'admin_status_saved' => 'Salvato. Prima della pubblicazione è necessaria una revisione.',
    'admin_status_not_saved' => 'Non salvato — :reason',
    'admin_status_source_not_translated' => 'La lingua di origine viene redatta, non tradotta.',
    'admin_status_no_source' => 'Scrivi prima il testo di origine — non c\'è nulla da cui tradurre.',
    // The status a queued translation reports. It names where the result will appear rather than
    // asking the reader to do anything, because there is nothing for them to do: the page polls
    // while the job runs and stops when it stops.
    'admin_status_translation_queued' => 'La traduzione è in corso. Comparirà qui non appena sarà pronta.',
    'admin_status_translation_running' => 'Per questa bozza è già in corso una traduzione. Il risultato comparirà qui non appena sarà pronta.',

    'admin_status_machine_translated' => 'Tradotto automaticamente. Una persona deve revisionarlo prima della pubblicazione.',
    'admin_status_translation_failed' => 'La traduzione non è arrivata alla fine. La bozza è invariata e puoi riprovare.',

    /** The button that closes the document dialog on a consent form. */
    'dialog_close' => 'Chiudi',
    /** The link in the document dialog that opens the same text as its own page, in a new tab. */
    'dialog_open_page' => 'Apri come pagina',
    'dialog_loading' => 'Caricamento del testo …',
    'dialog_failed' => 'Non è stato possibile caricare il testo.',
    'admin_status_reviewed' => 'Contrassegnato come revisionato. Questo testo ora è pubblicabile.',
    'admin_status_no_draft_to_review' => 'Per questa lingua non esiste alcuna bozza, quindi non c\'è nulla da contrassegnare come revisionato.',
    'admin_status_changed_before_review' => 'Il testo è stato modificato dopo essere stato mostrato qui, quindi non è stato contrassegnato come revisionato. Ricaricalo e rivedi la versione attuale.',
    'admin_status_release_blocked' => '«:key» non è stato pubblicato: :reasons',
    'admin_status_released' => '«:key» pubblicato in :count lingua/e — riguarda :affects persona/e.',
    'admin_status_released_uncounted' => '«:key» pubblicato in :count lingua/e. Il tempo previsto non è bastato per contare le persone interessate.',
    'admin_status_wording_changed' => 'La frase di accettazione è cambiata in :languages, e ogni consenso dato d’ora in poi registra quella nuova.',
    'admin_save_and_release' => 'Salva e pubblica',
    'admin_release_dialog_title' => 'Pubblica «:document»',
    'admin_release_dialog_mode' => 'Come viene pubblicato',
    'admin_release_dialog_languages' => 'Lingue',
    'admin_release_dialog_left_out' => 'Restano fuori',
    'admin_release_dialog_version' => 'Versione',
    'admin_release_dialog_dates' => 'Date',
    'admin_release_dialog_regime' => 'Regime giuridico',
    'admin_release_dialog_heading' => 'Intestazione',
    'admin_release_dialog_sentence' => 'Frase di accettazione',
    'admin_release_dialog_generic_sentence' => 'La frase generica, perché questo documento non ne ha una propria. Dagliene una in lang/vendor/legal-consent/:locale/wording.php.',
    'admin_release_dialog_window' => 'annuncio :announce, termine di opposizione :deadline, in vigore dal :enforce',
    'admin_release_dialog_now' => 'in vigore subito',
    'admin_release_dialog_reviewer' => 'Confermando, approvi anche :language come revisionato da te.',
    'admin_release_dialog_confirm' => 'Conferma e pubblica',
    'admin_status_release_dialog_open' => 'Salvato. Controlla che cosa fa la pubblicazione e confermala.',
    'admin_status_mode_not_offered' => 'Questo documento non si può pubblicare così.',
    // The modes "save and publish" offers, by NoticeMode value: a label and its consequence.
    'admin_release_modes' => [
        'active_reconsent' => ['label' => 'Chiedere a tutti di accettarlo di nuovo', 'consequence' => 'Tutti devono accettare la nuova versione prima di continuare. Diventa una nuova versione principale.'],
        'deemed_consent' => ['label' => 'Con termine di opposizione', 'consequence' => 'Tutti ricevono un avviso e possono opporsi fino al termine di opposizione; chi tace accetta. Valgono le date del modulo con termine di opposizione.'],
        'info_push' => ['label' => 'Annunciarlo', 'consequence' => 'Tutti ricevono un avviso, e nessuno deve accettare nulla.'],
        'silent_editorial' => ['label' => 'Senza avviso, come modifica redazionale', 'consequence' => 'Nessuno se ne accorge: una modifica redazionale senza avviso e senza accettazione, come un refuso.'],
    ],
    'admin_translation_heading' => 'Aggiungi :language',
    'admin_translation_explainer' => 'Non cambia nulla del contratto e aggiunge solo la lingua: :language riceve la versione :version, che vale già nelle altre lingue. Nessuno deve accettarla di nuovo.',
    'admin_translation_submit' => 'Pubblica la lingua',
    'admin_status_translation_published' => ':language è pubblicato, nella versione :version. Nel contratto non cambia nulla.',
    'admin_status_translation_not_offered' => 'Questa lingua non si può aggiungere da sola: è già pubblicata, nessun’altra lingua ha una versione in vigore che lo consenta, oppure la fonte porta già una nuova versione. Pubblicala con la prossima modifica.',
    'admin_first_publication_heading' => 'Pubblica per la prima volta',
    // By the mode a first publication takes, DocumentType::materialChangeMode().
    'admin_first_publication_explainer' => [
        'active_reconsent' => 'Questo testo non è mai stato pubblicato. Vale da subito, e ogni persona lo accetta per la prima volta alla prossima visita. Non c’è alcun termine, perché non c’è ancora nulla a cui opporsi.',
        'info_push' => 'Questo testo non è mai stato pubblicato. Vale da subito e viene annunciato a tutte le persone che riguarda. Non c’è alcun termine, perché non c’è ancora nulla a cui opporsi.',
        'silent_editorial' => 'Questo testo non è mai stato pubblicato. Vale da subito ed esce senza avviso: nessuno deve accettarlo e nessuno riceve un avviso.',
        'deemed_consent' => 'Questo testo non è mai stato pubblicato. Vale da subito. Non c’è alcun termine, perché non c’è ancora nulla a cui opporsi.',
    ],
    'admin_first_publication_submit' => 'Pubblica ora',
    'admin_status_already_published' => 'Questo testo è già pubblicato. Pubblica una modifica con il modulo qui sotto.',
    'admin_deemed_heading' => 'Pubblica con termine di opposizione',
    'admin_deemed_explainer' => 'Una modifica a consenso tacito vincola se il termine di opposizione si chiude senza opposizione. Il termine deve rispettare almeno il preavviso di legge.',
    'admin_deemed_announce' => 'Annuncia il',
    'admin_deemed_deadline' => 'Termine di opposizione',
    'admin_deemed_enforce' => 'In vigore dal',
    'admin_deemed_regime' => 'Regime giuridico',
    'admin_deemed_regime_none' => 'Non classificata',
    'admin_deemed_change_class' => 'Classe di modifica (facoltativo)',
    'admin_deemed_change_class_hint' => 'Un’etichetta tua per ritrovare questa modifica in seguito, per esempio durante una verifica. La riga di comando accetta la stessa etichetta. Puoi lasciare il campo vuoto.',
    'admin_deemed_offers_termination' => 'Concede un diritto di recesso gratuito',
    'admin_deemed_keeps_unmodified' => 'Mantiene disponibile la versione non modificata',
    'admin_deemed_offers_termination_hint' => 'Necessario dove la legge concede un diritto di recesso gratuito per una modifica del genere, per esempio per i servizi di pagamento (§ 675g Abs. 2 BGB), i prodotti digitali (§ 327r Abs. 3 BGB) o le piattaforme per utenti commerciali (art. 3, par. 2, regolamento (UE) 2019/1150).',
    'admin_deemed_keeps_unmodified_hint' => 'Chi si oppone mantiene la versione che ha già. Se così un prodotto digitale per i consumatori resta utilizzabile senza modifiche né costi aggiuntivi, il diritto di recesso viene meno (§ 327r Abs. 4 BGB).',
    'admin_deemed_lead_mode' => '{1} Almeno un giorno tra l’annuncio e il termine di opposizione, il riferimento per un consenso tacito (§ 308 Nr. 5 BGB).|[2,*] Almeno :count giorni tra l’annuncio e il termine di opposizione, il riferimento per un consenso tacito (§ 308 Nr. 5 BGB).',
    'admin_deemed_lead_statute' => '{1} Almeno un giorno tra l’annuncio e il termine di opposizione, fissato per legge (:source).|[2,*] Almeno :count giorni tra l’annuncio e il termine di opposizione, fissati per legge (:source).',
    'admin_deemed_lead_statute_months' => '{1} Almeno un mese di calendario, quindi almeno :days giorni, tra l’annuncio e il termine di opposizione, fissato per legge (:source).|[2,*] Almeno :count mesi di calendario, quindi almeno :days giorni, tra l’annuncio e il termine di opposizione, fissati per legge (:source).',
    'admin_deemed_lead_regime_setting' => '{1} Almeno un giorno tra l’annuncio e il termine di opposizione, come impostato per questo regime.|[2,*] Almeno :count giorni tra l’annuncio e il termine di opposizione, come impostato per questo regime.',
    'admin_deemed_lead_document' => '{1} Almeno un giorno tra l’annuncio e il termine di opposizione, come impostato per questo documento.|[2,*] Almeno :count giorni tra l’annuncio e il termine di opposizione, come impostato per questo documento.',
    'admin_deemed_lead_none' => 'Per questo regime non vale alcun termine minimo.',
    // The minimum's source where a statute sets it, by regime (LegalDocumentPublisher::STATUTORY_FLOORS).
    'admin_deemed_lead_sources' => [
        'psd2_675g' => '§ 675g Abs. 1 BGB',
        'p2b' => 'art. 3, par. 2, regolamento (UE) 2019/1150',
        'eecc' => 'art. 105, par. 4, direttiva (UE) 2018/1972',
    ],
    // The regimes of LegalDocumentPublisher::REGIMES, named and explained for the editor's form.
    'admin_regimes' => [
        'bgb_agb' => ['name' => 'Condizioni generali (§§ 305 ss. BGB)', 'description' => 'Condizioni generali di contratto secondo il diritto tedesco, la regola per la maggior parte dei contratti.'],
        'psd2_675g' => ['name' => 'Servizi di pagamento (§ 675g BGB)', 'description' => 'Un contratto quadro per servizi di pagamento. La legge richiede almeno due mesi di preavviso.'],
        'dcd_327r' => ['name' => 'Prodotti digitali (§ 327r BGB)', 'description' => 'Modifiche a contenuti o servizi digitali per i consumatori, annunciate con un termine ragionevole.'],
        'gdpr' => ['name' => 'Protezione dei dati (GDPR)', 'description' => 'Modifiche a un’informativa sulla privacy. Il termine è un’impostazione, non un numero fissato dalla legge.'],
        'p2b' => ['name' => 'Piattaforme per utenti commerciali (regolamento P2B)', 'description' => 'Condizioni di un servizio di intermediazione online verso gli utenti commerciali, con almeno 15 giorni di preavviso.'],
        'eecc' => ['name' => 'Comunicazioni elettroniche (CECE)', 'description' => 'Contratti per servizi di comunicazione elettronica, con almeno un mese di preavviso.'],
    ],
    'admin_preview_show' => 'Mostra l’anteprima',
    'admin_deemed_submit' => 'Pubblica con termine di opposizione',
    'admin_status_deemed_window_rejected' => 'Non pubblicato — il termine di opposizione è stato rifiutato: :reason',
    'admin_status_deemed_window_incomplete' => 'Non pubblicato — una pubblicazione con termine di opposizione richiede questi campi: :fields',
    'admin_status_deemed_dates_unreadable' => 'Non pubblicato — queste date non sono leggibili: :fields. Formato atteso: AAAA-MM-GG.',
    'lead_time_too_short' => 'una modifica sostanziale a «:document» richiede almeno :days giorni tra l’annuncio (:announce) e l’entrata in vigore (:enforce)',
    'lead_time_too_short_objection' => 'una modifica sostanziale a «:document» richiede almeno :days giorni tra l’annuncio (:announce) e il termine per opporsi (:deadline)',
    'notice_timeline_inverted' => 'l’annuncio di «:document» (:announce) cade dopo la sua entrata in vigore (:enforce); una modifica va annunciata prima di entrare in vigore',
    'publish_refused_version_taken_in_another_mode' => 'la versione :version è già pubblicata in :language con questo testo in un’altra modalità di avviso; una modalità diversa richiede una nuova versione',
    'publish_refused_binding_document_became_informational' => 'ora è registrata come pagina informativa, ma la sua versione attiva :active_version in :language chiede l’accettazione delle persone; mantieni la sua base giuridica o registra la pagina con una nuova chiave',
    'publish_refused_version_lower_than_active' => 'la versione :version è inferiore alla versione attiva :active_version in :language; pubblica invece una versione superiore con il testo precedente',
    'publish_refused_mode_differs_across_languages' => 'la versione principale :major è già attiva in :other_language in un’altra modalità di avviso, e :language uscirebbe in questa; tutte le lingue di una stessa modifica richiedono la stessa modalità',
    'publish_refused_major_needs_reconsent' => 'la versione :version aumenta la versione principale in :language, il che chiede a tutti di accettare di nuovo la modifica; pubblicala come nuovo consenso attivo',
    'publish_refused_gating_mode_keeps_the_major' => 'la versione :version mantiene la versione principale :major in :language, quindi un nuovo consenso non raggiungerebbe nessuno che ha già accettato; pubblicala come :next',
    'publish_refused_objection_deadline_not_before_effective_date' => 'il termine di opposizione (:deadline) deve scadere prima del giorno in cui la modifica entra in vigore (:enforce)',
    'publish_refused_change_class_too_long' => 'la classe di modifica ha :length caratteri e può averne al massimo :max',
    'publish_refused_title_is_the_key' => 'non ha un titolo in :language, quindi la sua chiave «:key» resterebbe l’intestazione della versione; aggiungi il titolo in :path',
    'draft_refused_no_translator' => 'per questa applicazione non è configurata alcuna traduzione automatica; traduci il testo a mano o chiedi a uno sviluppatore di collegare un traduttore',
    'draft_refused_too_large' => 'il testo è di :size KB, e un testo legale può essere al massimo di :limit KB',
    'draft_refused_unparsable' => 'non è stato possibile leggere il testo fino alla fine e sarebbe stato troncato; la causa abituale è un markup annidato molto in profondità',
    'draft_refused_translation_truncated' => 'la risposta del traduttore è stata troncata a uno dei suoi limiti, e una traduzione che si ferma a metà di una clausola non viene conservata; dividi o accorcia il testo e riprova, oppure traducilo a mano',
    'draft_refused_translation_declined' => 'il traduttore ha rifiutato questo testo; riprova oppure traducilo a mano',
    // The name of each ledger action, for a screen that shows an entry: ConsentAction::label().
    'actions' => [
        'granted' => 'Consenso dato',
        'acknowledged' => 'Presa visione',
        're_accepted' => 'Accettato di nuovo',
        'withdrawn' => 'Revocato',
        'declined' => 'Rifiutato',
        'parental' => 'Consenso dei genitori',
        'objected' => 'Opposizione presentata',
        'terminated' => 'Disdetto',
        'deemed_accepted' => 'Accettazione tacita',
        'optin_requested' => 'Opt-in richiesto',
        'confirmed' => 'Opt-in confermato',
    ],
];

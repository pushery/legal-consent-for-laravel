<?php

declare(strict_types=1);

// UI strings for the publishable stubs (settings page + grace-period banner). The three legal
// types stay separately named ('contracts' / 'acknowledgements' / 'consents'). Informal tone (per Du).
return [
    // The version line of the document-fragment stub. It is not decoration: somebody reading that
    // fragment in a dialog is about to agree to it, and which version they read is the fact a ledger
    // row will later claim. A fragment showing the text and hiding the version would leave that row
    // unverifiable from the only side that matters — the reader's.
    'document_version' => 'Fassung :version',
    'document_language' => 'Sprache dieses Textes: :language',

    'settings_heading' => 'Deine Zustimmungen',
    'contracts_heading' => 'Verträge',
    'acknowledgements_heading' => 'Zur Kenntnis genommen',
    'consents_heading' => 'Einwilligungen',

    // Empty states. A group with no entries used to render its heading over nothing, and that is
    // not the rare case it looks like: statusFor() reads the `legal_documents` table, which a
    // freshly installed package has nothing in until `legal-consent:publish` runs — so an empty
    // screen is the shipping state every consumer meets first, and three bare headings read as
    // broken rather than as "nothing yet". `nothing_published` covers all three being empty at
    // once, where one sentence says more than three.
    'nothing_published' => 'Hier ist noch nichts — es sind keine Rechtstexte veröffentlicht.',
    'nothing_published_title' => 'Noch keine Rechtstexte',
    'nothing_published_description' => 'Deine Verträge, die zur Kenntnis genommenen Dokumente und deine Einwilligungen erscheinen hier, sobald Rechtstexte veröffentlicht sind.',
    'contracts_empty' => 'Keine Verträge.',
    'acknowledgements_empty' => 'Nichts zur Kenntnis genommen.',
    'consents_empty' => 'Keine Einwilligungen.',
    // The double opt-in's middle state. It needs saying because it is the one position the screen
    // cannot show any other way: entered but not yet confirmed looks exactly like never entered,
    // so without this the subject is invited to enter themselves again — and the second request
    // supersedes the first, which stops the confirmation link already in their inbox from working.
    'confirmation_pending' => 'Bestätigung ausstehend — sieh in dein Postfach.',
    'grant' => 'Erteilen',
    'withdraw' => 'Widerrufen',
    // Per-item accessible name for the withdraw control: with N consents, N buttons all
    // labeled just 'withdraw' are indistinguishable in a screen reader's button list
    // (WCAG 2.4.6). The grant was document-specific; the withdrawal must be too.
    'grant_for' => 'Erteilen: :title',
    'withdraw_for' => 'Widerrufen: :title',
    // Accessible name for the link to a document's full text. Every row that shows a document
    // carries one, and with N rows a link labeled just 'read' is indistinguishable in a screen
    // reader's link list (WCAG 2.4.4) — the title is what makes each one nameable.
    'read_document' => ':title lesen',
    'review' => 'Jetzt ansehen',
    // Accessible name for the banner region (it is a `complementary`/`region` landmark,
    // not a live region — a live region present at page load never announces anyway).
    'banner_label' => 'Rechtliche Hinweise',

    // The countdown's expired states — a statement about the subject's position, so each
    // is translated, never left to an English fallback on a legal surface.
    'enforced_now' => 'Ab jetzt wirksam',
    'objection_closed' => 'Widerspruchsfrist abgelaufen',
    'submit' => 'Zustimmen und fortfahren',
    // Shown beside the submit while the request is in flight; the button itself carries
    // aria-busy, so this is the sighted half of the same state.
    'working' => 'Einen Moment…',
    'all_current' => 'Alles aktuell — nichts zu tun.',
    // The one state a settings screen must not blur: a new major version is waiting, so
    // this is the invitation to do voluntarily what the gate will otherwise compel.
    'action_required' => 'Aktion erforderlich',
    'retired' => 'Nicht mehr angeboten',
    'consent_given' => 'Erteilt',
    'consent_not_given' => 'Nicht erteilt',

    // Grace-period remaining time (trans_choice): pluralization + effective-today case.
    'days_left' => '{0}Ab heute wirksam|{1}Noch :count Tag|[2,*]Noch :count Tage',
    'updated_note' => 'Aktualisiert — keine Aktion erforderlich.',
    'object_review' => 'Ansehen oder widersprechen',

    // The withdraw confirmation. Art. 7(3) sentence 3: withdrawal must be as easy as
    // giving consent — so this asks once, states the consequence, and never nags.
    'withdraw_confirm_title' => 'Einwilligung widerrufen?',
    'withdraw_confirm_body' => 'Deine Einwilligung zu „:title“ wird widerrufen. Der Widerruf gilt ab sofort und lässt die Rechtmäßigkeit der bisherigen Verarbeitung unberührt.',
    'cancel' => 'Abbrechen',
    // The deemed-consent changes on the re-consent form, the page the notice and the banner send the
    // subject to, until they take effect. Objecting and terminating each write an irreversible row,
    // so each asks once.
    'upcoming_changes_heading' => 'Anstehende Vertragsänderungen',
    'object' => 'Widersprechen',
    'object_for' => 'Widersprechen: :title',
    'terminate' => 'Kostenfrei kündigen',
    'terminate_for' => 'Kostenfrei kündigen: :title',
    'object_confirm_title' => 'Der Änderung widersprechen?',
    'object_confirm_body' => 'Die Änderung an „:title“ gilt dann nicht als deine Zustimmung. Dein Widerspruch wird mit dem Zeitpunkt erfasst, zu dem du ihn abschickst.',
    'terminate_confirm_title' => 'Kostenfrei kündigen?',
    'terminate_confirm_body' => 'Du kündigst den Vertrag „:title“ kostenfrei, bevor die Änderung wirksam wird. Deine Kündigung wird mit dem Zeitpunkt erfasst, zu dem du sie abschickst.',

    // Admin screens (LegalTextManager / LegalTextEditor).
    'admin_heading' => 'Rechtstexte',
    'admin_policy' => 'Texte werden je Sprache bearbeitet, von einem Menschen geprüft und dann über alle Sprachen zugleich freigegeben. Eine maschinelle Übersetzung kann nie publiziert werden, bevor sie jemand geprüft hat, und der Zustimmungssatz ist feste Copy — er wird nie maschinell übersetzt.',
    'admin_document' => 'Dokument',
    'admin_not_written' => 'Nicht geschrieben',
    'admin_not_written_short' => 'Fehlt',
    'review_state_draft' => 'Entwurf',
    'review_state_draft_description' => 'Geschrieben, aber noch ist niemand dafür eingestanden. Ein Dokument, dem Menschen zustimmen, wird nicht freigegeben, solange eine seiner Sprachen noch Entwurf ist.',
    'review_state_draft_short' => 'Entwurf',
    'review_state_reviewed' => 'Geprüft',
    'review_state_reviewed_description' => 'Ein Mensch hat diese Sprache gelesen und ist dafür eingestanden. Live ist sie erst, wenn das Dokument freigegeben wird.',
    'review_state_reviewed_short' => 'Geprüft',
    'blocking_not_draft_backed' => 'dieses Dokument bezieht seinen Text nicht aus dem Entwurfs-Speicher',
    'blocking_no_draft' => 'es wurde noch kein Entwurf geschrieben',
    'blocking_not_reviewed' => 'noch nicht von einem Menschen geprüft',
    'blocking_stale_translation' => 'der Quelltext hat sich geändert, nachdem diese Übersetzung geprüft wurde',
    'blocking_no_change_description' => 'es wurde noch keine Änderungsbeschreibung geschrieben',
    'blocking_incomplete_change_description' => 'der Änderungsbeschreibung fehlt die Überschrift oder die Angabe der Auswirkung',
    'blocking_stale_change_description' => 'der Rechtstext hat sich geändert, nachdem diese Änderungsbeschreibung geschrieben wurde',
    'admin_machine' => 'Maschinell entworfen',
    'admin_machine_short' => 'Maschinell',
    'admin_needs_update' => 'Muss aktualisiert werden',
    'admin_needs_update_short' => 'Veraltet',
    'admin_unpublished' => 'Unveröffentlichte Änderungen',
    'admin_unpublished_short' => 'Unveröffentlicht',
    'admin_release_all' => 'Alle Sprachen freigeben',
    'admin_release_confirm_title' => 'Alle Sprachen freigeben?',
    'admin_release_confirm_body' => 'Alle Sprachen dieses Textes werden gemeinsam als eine Version publiziert. Das lässt sich nicht rückgängig machen — eine Änderung ist eine neue, höhere Version.',
    'admin_release_leaves_out' => 'Diese Freigabe lässt :languages aus. Sie behalten die dort schon veröffentlichte Fassung, falls es eine gibt, bis ihre eigene Übersetzung freigegeben ist.',
    'admin_release_headings' => 'Überschriften: :headings',
    'admin_stale' => 'Der Quelltext hat sich geändert, nachdem diese Übersetzung geprüft wurde — prüfe sie vor der Freigabe erneut.',
    'admin_stale_unconfirmed' => 'Diese Übersetzung wurde nie gegen den Quelltext bestätigt — prüfe sie vor der Freigabe.',
    'admin_save' => 'Speichern',
    'admin_translate' => 'Aus :locale übersetzen',
    'admin_translate_running' => 'Übersetzung läuft…',
    'admin_mark_reviewed' => 'Als geprüft markieren',
    'admin_review_state' => 'Prüfstand:',
    'admin_discard' => 'Entwurf verwerfen',
    'admin_discard_confirm_title' => 'Entwurf verwerfen?',
    'admin_discard_confirm_body' => 'Der Entwurf für :locale wird gelöscht. Veröffentlichte Fassungen bleiben unberührt.',
    'admin_status_draft_discarded' => 'Der Entwurf ist verworfen.',
    'admin_status_source_not_discarded' => 'Der Quell-Entwurf kann nicht verworfen werden — jede Übersetzung misst ihre Aktualität an ihm.',
    'admin_status_no_draft_to_discard' => 'Für diese Sprache gibt es keinen Entwurf.',
    'admin_preview' => 'Vorschau',
    'granted_confirmation' => 'Einwilligung erteilt. Du kannst sie jederzeit widerrufen.',
    'withdrawn_confirmation' => 'Einwilligung widerrufen. Der Widerruf wirkt ab sofort.',
    'objected_confirmation' => 'Widerspruch erfasst — mit dem Zeitpunkt, zu dem du ihn abgeschickt hast.',
    'terminated_confirmation' => 'Kündigung erfasst — mit dem Zeitpunkt, zu dem du sie abgeschickt hast.',
    // The refusal a subject can actually reach — a stale page, a hand-built post or a custom
    // stub asking to withdraw something that was never a consent. It states the position
    // without the document key or the internal type: those are the operator's business, and
    // they go to the log.
    'not_withdrawable' => 'Dieses Dokument kannst du nicht widerrufen — widerrufen lässt sich nur eine Einwilligung, die du freiwillig gegeben hast.',

    // Re-consent submit confirmation + plain admin-stub labels (announced / localized).
    'reconsent_recorded' => 'Danke — deine Zustimmung wurde gespeichert.',
    'reconsent_changed' => 'Das Dokument wurde geändert, seit du diese Seite geöffnet hast. Bitte prüfe die aktuelle Fassung, bevor du zustimmst.',
    'reconsent_none_selected' => 'Bitte bestätige die aufgeführten Punkte, um fortzufahren.',
    'reconsent_box_unticked' => 'Noch nicht angekreuzt.',
    'admin_body_label' => 'Text (bereinigtes HTML)',
    'admin_preview_label' => 'Vorschau des veröffentlichten Texts',
    'admin_edit_for_state' => ':key bearbeiten (:locale) — :state',
    'admin_status_saved' => 'Gespeichert. Vor der Veröffentlichung ist eine Prüfung erforderlich.',
    'admin_status_not_saved' => 'Nicht gespeichert — :reason',
    'admin_status_source_not_translated' => 'Die Quellsprache wird verfasst, nicht übersetzt.',
    'admin_status_no_source' => 'Schreibe zuerst den Quelltext — es gibt nichts, wovon übersetzt werden könnte.',
    // The status a queued translation reports. It names where the result will appear rather than
    // asking the reader to do anything, because there is nothing for them to do: the page polls
    // while the job runs and stops when it stops.
    'admin_status_translation_queued' => 'Die Übersetzung läuft. Sie erscheint hier, sobald sie fertig ist.',
    'admin_status_translation_running' => 'Für diesen Entwurf läuft schon eine Übersetzung. Ihr Ergebnis erscheint hier, sobald sie fertig ist.',

    'admin_status_machine_translated' => 'Maschinell übersetzt. Ein Mensch muss den Text prüfen, bevor er veröffentlicht werden kann.',
    'admin_status_translation_failed' => 'Die Übersetzung ist nicht zu Ende gekommen. Der Entwurf ist unverändert; du kannst es erneut versuchen.',

    /** The button that closes the document dialog on a consent form. */
    'dialog_close' => 'Schließen',
    /** The link in the document dialog that opens the same text as its own page, in a new tab. */
    'dialog_open_page' => 'Als Seite öffnen',
    'dialog_loading' => 'Der Text wird geladen …',
    'dialog_failed' => 'Der Text konnte nicht geladen werden.',
    'admin_status_reviewed' => 'Als geprüft markiert. Dieser Text ist jetzt veröffentlichbar.',
    'admin_status_no_draft_to_review' => 'Für diese Sprache gibt es keinen Entwurf, der geprüft werden könnte.',
    'admin_status_changed_before_review' => 'Der Text wurde geändert, nachdem er hier angezeigt wurde, und ist deshalb nicht als geprüft markiert. Lade ihn neu und prüfe die aktuelle Fassung.',
    'admin_status_release_blocked' => '„:key“ wurde nicht veröffentlicht: :reasons',
    'admin_status_released' => '„:key“ in :count Sprache(n) veröffentlicht — betrifft :affects Person(en).',
    'admin_status_released_uncounted' => '„:key“ in :count Sprache(n) veröffentlicht. Die vorgesehene Zeit reichte nicht, um die betroffenen Personen zu zählen.',
    'admin_status_wording_changed' => 'Der Zustimmungssatz hat sich in :languages geändert, und jede Zustimmung ab jetzt hält den neuen fest.',
    'admin_save_and_release' => 'Speichern und veröffentlichen',
    'admin_release_dialog_title' => '„:document“ veröffentlichen',
    'admin_release_dialog_mode' => 'Wie freigegeben wird',
    'admin_release_dialog_languages' => 'Sprachen',
    'admin_release_dialog_left_out' => 'Bleiben draußen',
    'admin_release_dialog_version' => 'Version',
    'admin_release_dialog_dates' => 'Daten',
    'admin_release_dialog_regime' => 'Rechtsregime',
    'admin_release_dialog_heading' => 'Überschrift',
    'admin_release_dialog_sentence' => 'Zustimmungssatz',
    'admin_release_dialog_generic_sentence' => 'Der allgemeine Satz, weil dieses Dokument keinen eigenen hat. Einen eigenen gibst du ihm in lang/vendor/legal-consent/:locale/wording.php.',
    'admin_release_dialog_window' => 'Ankündigung :announce, Widerspruchsfrist :deadline, wirksam ab :enforce',
    'admin_release_dialog_now' => 'sofort wirksam',
    'admin_release_dialog_reviewer' => 'Mit der Bestätigung zeichnest du :language als von dir geprüft ab.',
    'admin_release_dialog_confirm' => 'Bestätigen und veröffentlichen',
    'admin_status_release_dialog_open' => 'Gespeichert. Prüfe, was die Freigabe tut, und bestätige sie.',
    'admin_status_mode_not_offered' => 'So lässt sich dieses Dokument nicht freigeben.',
    // The modes "save and publish" offers, by NoticeMode value: a label and its consequence.
    'admin_release_modes' => [
        'active_reconsent' => ['label' => 'Erneut zustimmen lassen', 'consequence' => 'Alle müssen der neuen Fassung zustimmen, bevor sie weitermachen. Sie wird eine neue Hauptversion.'],
        'deemed_consent' => ['label' => 'Mit Widerspruchsfenster', 'consequence' => 'Alle bekommen einen Hinweis und können bis zur Widerspruchsfrist widersprechen; wer schweigt, stimmt zu. Es gelten die Daten aus dem Formular mit Widerspruchsfenster.'],
        'info_push' => ['label' => 'Ankündigen', 'consequence' => 'Alle bekommen einen Hinweis, und niemand muss zustimmen.'],
        'silent_editorial' => ['label' => 'Still, als redaktionelle Änderung', 'consequence' => 'Niemand merkt etwas: eine redaktionelle Änderung ohne Hinweis und ohne Zustimmung, etwa ein Tippfehler.'],
    ],
    'admin_translation_heading' => ':language ergänzen',
    'admin_translation_explainer' => 'Ändert nichts am Vertrag, ergänzt nur die Sprache: :language bekommt die Fassung :version, die in den anderen Sprachen schon gilt. Niemand muss neu zustimmen.',
    'admin_translation_submit' => 'Sprache veröffentlichen',
    'admin_status_translation_published' => ':language ist veröffentlicht, in der Fassung :version. Am Vertrag ändert sich nichts.',
    'admin_status_translation_not_offered' => 'Diese Sprache lässt sich nicht allein ergänzen: Sie ist schon veröffentlicht, keine andere Sprache hat eine geltende Fassung, die das erlaubt, oder die Quelle trägt schon eine neue Fassung. Gib sie mit der nächsten Änderung frei.',
    'admin_first_publication_heading' => 'Erstmals veröffentlichen',
    // By the mode a first publication takes, DocumentType::materialChangeMode().
    'admin_first_publication_explainer' => [
        'active_reconsent' => 'Dieser Text ist noch nie veröffentlicht. Er gilt ab sofort, und jede Person stimmt ihm beim nächsten Besuch zum ersten Mal zu. Eine Frist gibt es dafür nicht, weil es noch nichts gibt, dem jemand widersprechen könnte.',
        'info_push' => 'Dieser Text ist noch nie veröffentlicht. Er gilt ab sofort und wird allen angekündigt, die er betrifft. Eine Frist gibt es dafür nicht, weil es noch nichts gibt, dem jemand widersprechen könnte.',
        'silent_editorial' => 'Dieser Text ist noch nie veröffentlicht. Er gilt ab sofort und geht still raus: Niemand muss zustimmen, und niemand bekommt einen Hinweis.',
        'deemed_consent' => 'Dieser Text ist noch nie veröffentlicht. Er gilt ab sofort. Eine Frist gibt es dafür nicht, weil es noch nichts gibt, dem jemand widersprechen könnte.',
    ],
    'admin_first_publication_submit' => 'Jetzt veröffentlichen',
    'admin_status_already_published' => 'Dieser Text ist schon veröffentlicht. Eine Änderung gibst du mit dem Formular darunter frei.',
    'admin_deemed_heading' => 'Mit Widerspruchsfenster freigeben',
    'admin_deemed_explainer' => 'Eine Änderung mit Zustimmungsfiktion bindet, wenn das Widerspruchsfenster ohne Widerspruch endet. Das Fenster muss mindestens die gesetzliche Vorlaufzeit einhalten.',
    'admin_deemed_announce' => 'Ankündigen am',
    'admin_deemed_deadline' => 'Widerspruchsfrist',
    'admin_deemed_enforce' => 'Wirksam ab',
    'admin_deemed_regime' => 'Rechtsregime',
    'admin_deemed_regime_none' => 'Nicht eingeordnet',
    'admin_deemed_change_class' => 'Änderungsklasse (freiwillig)',
    'admin_deemed_change_class_hint' => 'Ein eigenes Stichwort, unter dem du diese Änderung später wiederfindest, etwa bei einer Prüfung. Die Kommandozeile nimmt dasselbe Stichwort. Du kannst das Feld leer lassen.',
    'admin_deemed_offers_termination' => 'Räumt ein kostenloses Kündigungsrecht ein',
    'admin_deemed_keeps_unmodified' => 'Hält die unveränderte Fassung weiter bereit',
    'admin_deemed_offers_termination_hint' => 'Nötig, wo das Recht für eine solche Änderung ein kostenloses Kündigungsrecht gibt, etwa bei Zahlungsdiensten (§ 675g Abs. 2 BGB), digitalen Produkten (§ 327r Abs. 3 BGB) oder Plattformen für gewerbliche Nutzer (Art. 3 Abs. 2 VO (EU) 2019/1150).',
    'admin_deemed_keeps_unmodified_hint' => 'Wer widerspricht, behält die Fassung, die er schon hat. Bleibt ein digitales Produkt für Verbraucher so ohne Mehrkosten unverändert nutzbar, entfällt das Kündigungsrecht (§ 327r Abs. 4 BGB).',
    'admin_deemed_lead_mode' => '{1} Mindestens ein Tag zwischen Ankündigung und Widerspruchsfrist, der Maßstab für eine Zustimmungsfiktion (§ 308 Nr. 5 BGB).|[2,*] Mindestens :count Tage zwischen Ankündigung und Widerspruchsfrist, der Maßstab für eine Zustimmungsfiktion (§ 308 Nr. 5 BGB).',
    'admin_deemed_lead_statute' => '{1} Mindestens ein Tag zwischen Ankündigung und Widerspruchsfrist, gesetzlich festgelegt (:source).|[2,*] Mindestens :count Tage zwischen Ankündigung und Widerspruchsfrist, gesetzlich festgelegt (:source).',
    'admin_deemed_lead_statute_months' => '{1} Mindestens ein Monat nach dem Kalender, also mindestens :days Tage, zwischen Ankündigung und Widerspruchsfrist, gesetzlich festgelegt (:source).|[2,*] Mindestens :count Monate nach dem Kalender, also mindestens :days Tage, zwischen Ankündigung und Widerspruchsfrist, gesetzlich festgelegt (:source).',
    'admin_deemed_lead_regime_setting' => '{1} Mindestens ein Tag zwischen Ankündigung und Widerspruchsfrist, wie für dieses Rechtsregime eingestellt.|[2,*] Mindestens :count Tage zwischen Ankündigung und Widerspruchsfrist, wie für dieses Rechtsregime eingestellt.',
    'admin_deemed_lead_document' => '{1} Mindestens ein Tag zwischen Ankündigung und Widerspruchsfrist, wie für dieses Dokument eingestellt.|[2,*] Mindestens :count Tage zwischen Ankündigung und Widerspruchsfrist, wie für dieses Dokument eingestellt.',
    'admin_deemed_lead_none' => 'Für dieses Rechtsregime gilt keine Mindestfrist.',
    // The minimum's source where a statute sets it, by regime (LegalDocumentPublisher::STATUTORY_FLOORS).
    'admin_deemed_lead_sources' => [
        'psd2_675g' => '§ 675g Abs. 1 BGB',
        'p2b' => 'Art. 3 Abs. 2 VO (EU) 2019/1150',
        'eecc' => 'Art. 105 Abs. 4 RL (EU) 2018/1972',
    ],
    // The regimes of LegalDocumentPublisher::REGIMES, named and explained for the editor's form.
    'admin_regimes' => [
        'bgb_agb' => ['name' => 'AGB-Recht (§§ 305 ff. BGB)', 'description' => 'Allgemeine Geschäftsbedingungen nach deutschem Recht, die Regel für die meisten Verträge.'],
        'psd2_675g' => ['name' => 'Zahlungsdienste (§ 675g BGB)', 'description' => 'Ein Rahmenvertrag über Zahlungsdienste. Das Gesetz verlangt mindestens zwei Monate Vorlauf.'],
        'dcd_327r' => ['name' => 'Digitale Produkte (§ 327r BGB)', 'description' => 'Änderungen an digitalen Inhalten oder Diensten für Verbraucher, mit Hinweis in angemessener Frist.'],
        'gdpr' => ['name' => 'Datenschutz (DSGVO)', 'description' => 'Änderungen an einer Datenschutzerklärung. Die Frist ist eine Einstellung, keine gesetzliche Zahl.'],
        'p2b' => ['name' => 'Plattformen für gewerbliche Nutzer (P2B-VO)', 'description' => 'Bedingungen eines Online-Vermittlungsdienstes gegenüber gewerblichen Nutzern, mit mindestens 15 Tagen Vorlauf.'],
        'eecc' => ['name' => 'Telekommunikation (EKEK)', 'description' => 'Verträge über elektronische Kommunikationsdienste, mit mindestens einem Monat Vorlauf.'],
    ],
    'admin_preview_show' => 'Vorschau anzeigen',
    'admin_deemed_submit' => 'Mit Widerspruchsfenster freigeben',
    'admin_status_deemed_window_rejected' => 'Nicht freigegeben — das Widerspruchsfenster wurde abgelehnt: :reason',
    'admin_status_deemed_window_incomplete' => 'Nicht freigegeben — eine Freigabe mit Widerspruchsfenster braucht diese Felder: :fields',
    'admin_status_deemed_dates_unreadable' => 'Nicht freigegeben — diese Daten sind nicht lesbar: :fields. Erwartet wird JJJJ-MM-TT.',
    'lead_time_too_short' => 'eine wesentliche Änderung an „:document“ braucht mindestens :days Tage zwischen der Ankündigung (:announce) und dem Inkrafttreten (:enforce)',
    'lead_time_too_short_objection' => 'eine wesentliche Änderung an „:document“ braucht mindestens :days Tage zwischen der Ankündigung (:announce) und der Widerspruchsfrist (:deadline)',
    'notice_timeline_inverted' => 'die Ankündigung von „:document“ (:announce) liegt nach dem Inkrafttreten (:enforce); eine Änderung muss angekündigt sein, bevor sie in Kraft tritt',
    'publish_refused_version_taken_in_another_mode' => 'Version :version ist in :language mit diesem Text schon unter einem anderen Mitteilungsmodus veröffentlicht; ein anderer Modus braucht eine neue Version',
    'publish_refused_binding_document_became_informational' => 'die Seite ist jetzt als Informationsseite eingetragen, ihre aktive Version :active_version in :language verlangt aber eine Zustimmung; die Rechtsgrundlage beibehalten oder die Seite unter einem neuen Schlüssel eintragen',
    'publish_refused_version_lower_than_active' => 'Version :version liegt unter der aktiven Version :active_version in :language; stattdessen eine höhere Version mit dem älteren Text veröffentlichen',
    'publish_refused_mode_differs_across_languages' => 'die Hauptversion :major ist in :other_language schon unter einem anderen Mitteilungsmodus aktiv, und :language ginge unter diesem hinaus; alle Sprachen einer Änderung brauchen denselben Modus',
    'publish_refused_major_needs_reconsent' => 'Version :version hebt in :language die Hauptversion an und verlangt damit von allen eine erneute Zustimmung; als aktive erneute Zustimmung veröffentlichen',
    'publish_refused_gating_mode_keeps_the_major' => 'Version :version behält in :language die Hauptversion :major, eine erneute Zustimmung erreicht damit niemanden, der schon zugestimmt hat; als :next veröffentlichen',
    'publish_refused_objection_deadline_not_before_effective_date' => 'die Widerspruchsfrist (:deadline) muss vor dem Inkrafttreten (:enforce) enden',
    'publish_refused_change_class_too_long' => 'die Änderungsklasse hat :length Zeichen und darf höchstens :max haben',
    'publish_refused_title_is_the_key' => 'es hat in :language keinen Titel, und sein Schlüssel „:key“ bliebe die Überschrift der Version; den Titel in :path eintragen',
    'draft_refused_no_translator' => 'für diese Anwendung ist keine maschinelle Übersetzung eingerichtet; übersetze den Text von Hand oder bitte die Entwicklung, einen Übersetzer anzubinden',
    'draft_refused_too_large' => 'der Text hat :size KB, und ein Rechtstext darf höchstens :limit KB haben',
    'draft_refused_unparsable' => 'der Text konnte nicht bis zum Ende gelesen werden und wäre abgeschnitten worden; meist ist sehr tief verschachteltes Markup die Ursache',
    'draft_refused_translation_truncated' => 'die Antwort des Übersetzers wurde an einer seiner Grenzen abgeschnitten, und eine Übersetzung, die mitten in einer Klausel endet, wird nicht übernommen; teile oder kürze den Text und versuche es noch einmal, oder übersetze ihn von Hand',
    'draft_refused_translation_declined' => 'der Übersetzer hat diesen Text abgelehnt; versuche es noch einmal oder übersetze ihn von Hand',
    // The name of each ledger action, for a screen that shows an entry: ConsentAction::label().
    'actions' => [
        'granted' => 'Eingewilligt',
        'acknowledged' => 'Zur Kenntnis genommen',
        're_accepted' => 'Erneut akzeptiert',
        'withdrawn' => 'Widerrufen',
        'declined' => 'Abgelehnt',
        'parental' => 'Einwilligung der Eltern',
        'objected' => 'Widersprochen',
        'terminated' => 'Gekündigt',
        'deemed_accepted' => 'Als zugestimmt gewertet',
        'optin_requested' => 'Opt-in angefordert',
        'confirmed' => 'Opt-in bestätigt',
    ],
];

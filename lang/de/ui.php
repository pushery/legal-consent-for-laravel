<?php

declare(strict_types=1);

// UI strings for the publishable stubs (settings page + grace-period banner). The three legal
// types stay separately named (contracts / acknowledgements / consents). Informal tone (per Du).
return [
    'settings_heading' => 'Deine Zustimmungen',
    'contracts_heading' => 'Verträge',
    'acknowledgements_heading' => 'Zur Kenntnis genommen',
    'consents_heading' => 'Einwilligungen',

    // Empty states. A group with no entries used to render its heading over nothing, and that is
    // not the rare case it looks like: statusFor() reads the `legal_documents` table, which a
    // freshly installed package has NOTHING in until `legal-consent:publish` runs — so an empty
    // screen is the SHIPPING state every consumer meets first, and three bare headings read as
    // broken rather than as "nothing yet". `nothing_published` covers all three being empty at
    // once, where one sentence says more than three.
    'nothing_published' => 'Hier ist noch nichts — es sind keine Rechtstexte veröffentlicht.',
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
    'all_current' => 'Alles aktuell — nichts zu tun.',
    // The one state a settings screen must not blur: a new major version is waiting, so
    // this is the invitation to do voluntarily what the gate will otherwise compel.
    'action_required' => 'Aktion erforderlich',
    'retired' => 'Nicht mehr angeboten',

    // Grace-period remaining time (trans_choice): pluralisation + effective-today case.
    'days_left' => '{0}Ab heute wirksam|{1}Noch :count Tag|[2,*]Noch :count Tage',
    'updated_note' => 'Aktualisiert — keine Aktion erforderlich.',
    'object_review' => 'Ansehen oder widersprechen',

    // The withdraw confirmation. Art. 7(3) sentence 3: withdrawal must be as easy as
    // giving consent — so this asks once, states the consequence, and never nags.
    'withdraw_confirm_title' => 'Einwilligung widerrufen?',
    'withdraw_confirm_body' => 'Deine Einwilligung zu „:title“ wird widerrufen. Der Widerruf gilt ab sofort und lässt die Rechtmäßigkeit der bisherigen Verarbeitung unberührt.',
    'cancel' => 'Abbrechen',

    // Admin screens (LegalTextManager / LegalTextEditor).
    'admin_heading' => 'Rechtstexte',
    'admin_policy' => 'Texte werden je Sprache bearbeitet, von einem Menschen geprüft und dann über alle Sprachen zugleich freigegeben. Eine maschinelle Übersetzung kann nie publiziert werden, bevor sie jemand geprüft hat, und der Zustimmungssatz ist feste Copy — er wird nie maschinell übersetzt.',
    'admin_document' => 'Dokument',
    'admin_release' => 'Freigabe',
    'admin_not_written' => 'Nicht geschrieben',
    'review_state_draft' => 'Entwurf',
    'review_state_reviewed' => 'Geprüft',
    'blocking_no_draft' => 'es wurde noch kein Entwurf geschrieben',
    'blocking_not_reviewed' => 'noch nicht von einem Menschen geprüft',
    'blocking_stale_translation' => 'der Quelltext hat sich geändert, nachdem diese Übersetzung geprüft wurde',
    'blocking_no_change_description' => 'es wurde noch keine Änderungsbeschreibung geschrieben',
    'blocking_incomplete_change_description' => 'der Änderungsbeschreibung fehlt die Überschrift oder die Angabe der Auswirkung',
    'blocking_stale_change_description' => 'der Rechtstext hat sich geändert, nachdem diese Änderungsbeschreibung geschrieben wurde',
    'admin_machine' => 'Maschinell entworfen',
    'admin_needs_update' => 'Muss aktualisiert werden',
    'admin_unpublished' => 'Unveröffentlichte Änderungen',
    'admin_release_all' => 'Alle Sprachen freigeben',
    'admin_release_confirm_title' => 'Alle Sprachen freigeben?',
    'admin_release_confirm_body' => 'Alle Sprachen dieses Textes werden gemeinsam als eine Version publiziert. Das lässt sich nicht rückgängig machen — eine Änderung ist eine neue, höhere Version.',
    'admin_stale' => 'Der Quelltext hat sich geändert, nachdem diese Übersetzung geprüft wurde — prüfe sie vor der Freigabe erneut.',
    'admin_save' => 'Speichern',
    'admin_translate' => 'Aus :locale übersetzen',
    'admin_mark_reviewed' => 'Als geprüft markieren',
    'admin_preview' => 'Vorschau',
    'granted_confirmation' => 'Einwilligung erteilt. Du kannst sie jederzeit widerrufen.',
    'withdrawn_confirmation' => 'Einwilligung widerrufen. Der Widerruf wirkt ab sofort.',
    // The refusal a subject can actually reach — a stale page, a hand-built post or a custom
    // stub asking to withdraw something that was never a consent. It states the position
    // without the document key or the internal type: those are the operator's business, and
    // they go to the log.
    'not_withdrawable' => 'Dieses Dokument kannst du nicht widerrufen — widerrufen lässt sich nur eine Einwilligung, die du freiwillig gegeben hast.',

    // Re-consent submit confirmation + plain admin-stub labels (announced / localized).
    'reconsent_recorded' => 'Danke — deine Zustimmung wurde gespeichert.',
    'reconsent_changed' => 'Das Dokument wurde geändert, seit du diese Seite geöffnet hast. Bitte prüfe die aktuelle Fassung, bevor du zustimmst.',
    'reconsent_none_selected' => 'Bitte bestätige die aufgeführten Punkte, um fortzufahren.',
    'admin_body_label' => 'Text (bereinigtes HTML)',
    'admin_preview_label' => 'Vorschau des veröffentlichten Texts',
    'admin_edit' => 'bearbeiten',
    'admin_edit_for' => ':key bearbeiten (:locale)',
    'admin_status_saved' => 'Gespeichert. Vor der Veröffentlichung ist eine Prüfung erforderlich.',
    'admin_status_source_not_translated' => 'Die Quellsprache wird verfasst, nicht übersetzt.',
    'admin_status_no_source' => 'Schreibe zuerst den Quelltext — es gibt nichts, wovon übersetzt werden könnte.',
    'admin_status_machine_translated' => 'Maschinell übersetzt. Ein Mensch muss den Text prüfen, bevor er veröffentlicht werden kann.',
    'admin_status_reviewed' => 'Als geprüft markiert. Dieser Text ist jetzt veröffentlichbar.',
    'admin_status_release_blocked' => '„:key“ wurde nicht veröffentlicht: :reasons',
    'admin_status_released' => '„:key“ in :count Sprache(n) veröffentlicht — betrifft :affects Person(en).',
    'admin_deemed_heading' => 'Mit Widerspruchsfenster freigeben',
    'admin_deemed_explainer' => 'Eine Änderung mit Zustimmungsfiktion bindet, wenn das Widerspruchsfenster ohne Widerspruch endet. Das Fenster muss mindestens die gesetzliche Vorlaufzeit einhalten.',
    'admin_deemed_announce' => 'Ankündigen am',
    'admin_deemed_deadline' => 'Widerspruchsfrist',
    'admin_deemed_enforce' => 'Wirksam ab',
    'admin_deemed_offers_termination' => 'Räumt ein kostenloses Kündigungsrecht ein',
    'admin_deemed_keeps_unmodified' => 'Hält die unveränderte Fassung weiter bereit',
    'admin_deemed_submit' => 'Mit Widerspruchsfenster freigeben',
    'admin_status_deemed_window_rejected' => 'Nicht freigegeben — das Widerspruchsfenster wurde abgelehnt: :reason',
];

<?php

declare(strict_types=1);

// UI strings for the publishable stubs (settings page + grace-period banner). The three legal
// types stay separately named (contracts / acknowledgements / consents). Informal tone (per Du).
return [
    'settings_heading' => 'Deine Zustimmungen',
    'contracts_heading' => 'Verträge',
    'acknowledgements_heading' => 'Zur Kenntnis genommen',
    'consents_heading' => 'Einwilligungen',
    'withdraw' => 'Widerrufen',
    // Per-item accessible name for the withdraw control: with N consents, N buttons all
    // labelled just 'withdraw' are indistinguishable in a screen reader's button list
    // (WCAG 2.4.6). The grant was document-specific; the withdrawal must be too.
    'withdraw_for' => 'Widerrufen: :title',
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

    // Grace-Period-Restzeit (trans_choice): Pluralisierung + Stichtag-Fall.
    'days_left' => '{0}Ab heute wirksam|{1}Noch :count Tag|[2,*]Noch :count Tage',
    'updated_note' => 'Aktualisiert — keine Aktion erforderlich.',
    'object_review' => 'Ansehen oder widersprechen',

    // The withdraw confirmation. Art. 7(3) sentence 3: withdrawal must be as easy as
    // giving consent — so this asks once, states the consequence, and never nags.
    'withdraw_confirm_title' => 'Einwilligung widerrufen?',
    'withdraw_confirm_body' => 'Deine Einwilligung zu „:title" wird widerrufen. Der Widerruf gilt ab sofort und lässt die Rechtmäßigkeit der bisherigen Verarbeitung unberührt.',
    'cancel' => 'Abbrechen',

    // Admin screens (LegalTextManager / LegalTextEditor).
    'admin_heading' => 'Rechtstexte',
    'admin_policy' => 'Texte werden je Sprache bearbeitet, von einem Menschen geprüft und dann über alle Sprachen zugleich freigegeben. Eine maschinelle Übersetzung kann nie publiziert werden, bevor sie jemand geprüft hat, und der Zustimmungssatz ist feste Copy — er wird nie maschinell übersetzt.',
    'admin_document' => 'Dokument',
    'admin_release' => 'Freigabe',
    'admin_not_written' => 'Nicht geschrieben',
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
    'withdrawn_confirmation' => 'Einwilligung widerrufen. Sie wirkt ab sofort.',
];

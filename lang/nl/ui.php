<?php

declare(strict_types=1);

// UI strings for the publishable stubs (settings page + grace-period banner). The three
// legal kinds stay separately labelled (contracts / acknowledgements / consents). Informal (je).
return [
    'settings_heading' => 'Jouw toestemmingen',
    'contracts_heading' => 'Contracten',
    'acknowledgements_heading' => 'Kennisgenomen',
    'consents_heading' => 'Toestemmingen',
    'withdraw' => 'Intrekken',
    // Per-item accessible name for the withdraw control: with N consents, N buttons all
    // labelled just 'withdraw' are indistinguishable in a screen reader's button list
    // (WCAG 2.4.6). The grant was document-specific; the withdrawal must be too.
    'withdraw_for' => 'Intrekken: :title',
    'review' => 'Nu bekijken',
    // Accessible name for the banner region (it is a `complementary`/`region` landmark,
    // not a live region — a live region present at page load never announces anyway).
    'banner_label' => 'Juridische kennisgevingen',

    // The countdown's expired states — a statement about the subject's position, so each
    // is translated, never left to an English fallback on a legal surface.
    'enforced_now' => 'Nu van kracht',
    'objection_closed' => 'Bezwaartermijn verstreken',
    'submit' => 'Accepteren en doorgaan',
    'all_current' => 'Alles is up-to-date — niets te doen.',

    // Grace-period remaining time (trans_choice): pluralisation + effective-today case.
    'days_left' => '{0}Vandaag van kracht|{1}Nog :count dag|[2,*]Nog :count dagen',
    'updated_note' => 'Bijgewerkt — geen actie nodig.',
    'object_review' => 'Bekijken of bezwaar maken',

    // The withdraw confirmation. Art. 7(3) sentence 3: withdrawal must be as easy as
    // giving consent — so this asks once, states the consequence, and never nags.
    'withdraw_confirm_title' => 'Toestemming intrekken?',
    'withdraw_confirm_body' => 'Je toestemming voor ‘:title’ wordt ingetrokken. Dit gaat direct in en doet geen afbreuk aan de rechtmatigheid van de eerdere verwerking.',
    'cancel' => 'Annuleren',

    // Admin screens (LegalTextManager / LegalTextEditor).
    'admin_heading' => 'Juridische teksten',
    'admin_policy' => 'Teksten worden per taal bewerkt, door een mens gecontroleerd en dan in alle talen tegelijk vrijgegeven. Een machinevertaling kan nooit worden gepubliceerd voordat iemand haar heeft gecontroleerd, en de acceptatiezin is vaste tekst — die wordt nooit machinaal vertaald.',
    'admin_document' => 'Document',
    'admin_release' => 'Vrijgeven',
    'admin_not_written' => 'Niet geschreven',
    'admin_machine' => 'Automatisch concept',
    'admin_needs_update' => 'Moet bijgewerkt worden',
    'admin_unpublished' => 'Niet-gepubliceerde wijzigingen',
    'admin_release_all' => 'Alle talen vrijgeven',
    'admin_release_confirm_title' => 'Alle talen vrijgeven?',
    'admin_release_confirm_body' => 'Alle talen van deze tekst worden samen als één versie gepubliceerd. Dit kan niet ongedaan worden gemaakt — een wijziging is een nieuwe, hogere versie.',
    'admin_stale' => 'De brontekst is veranderd nadat deze vertaling was gecontroleerd — controleer haar opnieuw voor vrijgave.',
    'admin_save' => 'Opslaan',
    'admin_translate' => 'Vertalen vanuit :locale',
    'admin_mark_reviewed' => 'Als gecontroleerd markeren',
    'admin_preview' => 'Voorbeeld',
    'withdrawn_confirmation' => 'Toestemming ingetrokken. Het gaat direct in.',

    // Re-consent submit confirmation + plain admin-stub labels (announced / localized).
    'reconsent_recorded' => 'Bedankt — je toestemming is vastgelegd.',
    'reconsent_changed' => 'Dit document is gewijzigd sinds je deze pagina hebt geopend. Bekijk de huidige versie voordat je toestemming geeft.',
    'admin_body_label' => 'Tekst (opgeschoonde HTML)',
    'admin_preview_label' => 'Voorbeeld van de gepubliceerde tekst',
    'admin_edit' => 'bewerken',
    'admin_edit_for' => ':key bewerken (:locale)',
    'admin_status_saved' => 'Opgeslagen. Voor publicatie is een controle vereist.',
    'admin_status_source_not_translated' => 'De brontaal wordt geschreven, niet vertaald.',
    'admin_status_no_source' => 'Schrijf eerst de brontekst — er is niets om uit te vertalen.',
    'admin_status_machine_translated' => 'Machinaal vertaald. Iemand moet de tekst controleren voordat die gepubliceerd kan worden.',
    'admin_status_reviewed' => 'Als gecontroleerd gemarkeerd. Deze tekst kan nu gepubliceerd worden.',
    'admin_status_release_blocked' => '\':key\' is niet gepubliceerd: :reasons',
    'admin_status_released' => '\':key\' gepubliceerd in :count taal/talen — betreft :affects perso(o)n(en).',
];

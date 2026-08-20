<?php

declare(strict_types=1);

// UI strings for the publishable stubs (settings page + grace-period banner). The three
// legal kinds stay separately labeled (contracts / acknowledgements / consents).
return [
    'settings_heading' => 'Your consents',
    'contracts_heading' => 'Contracts',
    'acknowledgements_heading' => 'Acknowledged',
    'consents_heading' => 'Consents',
    'grant' => 'Give',
    'withdraw' => 'Withdraw',
    // Per-item accessible name for the withdraw control: with N consents, N buttons all
    // labeled just 'withdraw' are indistinguishable in a screen reader's button list
    // (WCAG 2.4.6). The grant was document-specific; the withdrawal must be too.
    'grant_for' => 'Give: :title',
    'withdraw_for' => 'Withdraw: :title',
    // Accessible name for the link to a document's full text. Every row that shows a document
    // carries one, and with N rows a link labeled just 'read' is indistinguishable in a screen
    // reader's link list (WCAG 2.4.4) — the title is what makes each one nameable.
    'read_document' => 'Read :title',
    'review' => 'Review now',
    // Accessible name for the banner region (it is a `complementary`/`region` landmark,
    // not a live region — a live region present at page load never announces anyway).
    'banner_label' => 'Legal notices',

    // The countdown's expired states — a statement about the subject's position, so each
    // is translated, never left to an English fallback on a legal surface.
    'enforced_now' => 'In effect now',
    'objection_closed' => 'Objection period closed',
    'submit' => 'Accept and continue',
    'all_current' => 'Everything is up to date — nothing to do.',
    // The one state a settings screen must not blur: a new major version is waiting, so
    // this is the invitation to do voluntarily what the gate will otherwise compel.
    'action_required' => 'Action required',

    // Grace-period remaining time (trans_choice): pluralisation + effective-today case.
    'days_left' => '{0}Effective today|{1}:count day left|[2,*]:count days left',
    'updated_note' => 'Updated — no action required.',
    'object_review' => 'Review or object',

    // The withdraw confirmation. Art. 7(3) sentence 3: withdrawal must be as easy as
    // giving consent — so this asks once, states the consequence, and never nags.
    'withdraw_confirm_title' => 'Withdraw consent?',
    'withdraw_confirm_body' => 'Your consent to “:title” will be withdrawn. It takes effect immediately and does not affect the lawfulness of processing before it.',
    'cancel' => 'Cancel',

    // Admin screens (LegalTextManager / LegalTextEditor).
    'admin_heading' => 'Legal texts',
    'admin_policy' => 'Texts are edited per locale, reviewed by a human, then released across every locale at once. A machine translation can never be published until someone reviews it, and the acceptance sentence is fixed copy — it is never machine-translated.',
    'admin_document' => 'Document',
    'admin_release' => 'Release',
    'admin_not_written' => 'Not written',
    'admin_machine' => 'Machine-drafted',
    'admin_needs_update' => 'Needs update',
    'admin_unpublished' => 'Unpublished changes',
    'admin_release_all' => 'Release all locales',
    'admin_release_confirm_title' => 'Release every locale?',
    'admin_release_confirm_body' => 'All locales of this text are published together as one version. This cannot be undone — a change is a new, higher version.',
    'admin_stale' => 'The source text changed after this translation was reviewed — review it again before releasing.',
    'admin_save' => 'Save',
    'admin_translate' => 'Translate from :locale',
    'admin_mark_reviewed' => 'Mark reviewed',
    'admin_preview' => 'Preview',
    'granted_confirmation' => 'Consent given. You can withdraw it at any time.',
    'withdrawn_confirmation' => 'Consent withdrawn. It takes effect immediately.',

    // Re-consent submit confirmation + plain admin-stub labels (announced / localized).
    'reconsent_recorded' => 'Thank you — your consent has been recorded.',
    'reconsent_changed' => 'This document changed since you opened this page. Please review the current version before consenting.',
    'reconsent_none_selected' => 'Please tick the box for each item you agree to before continuing.',
    'admin_body_label' => 'Text (sanitized HTML)',
    'admin_preview_label' => 'Preview of the published text',
    'admin_edit' => 'edit',
    'admin_edit_for' => 'Edit :key (:locale)',
    'admin_status_saved' => 'Saved. Review is required before this text can be published.',
    'admin_status_source_not_translated' => 'The source locale is authored, not translated.',
    'admin_status_no_source' => 'Write the source text first — there is nothing to translate from.',
    'admin_status_machine_translated' => 'Machine-translated. A human must review it before it can be published.',
    'admin_status_reviewed' => 'Marked reviewed. This text is now publishable.',
    'admin_status_release_blocked' => '\':key\' was not released: :reasons',
    'admin_status_released' => 'Released \':key\' across :count locale(s) — affects :affects subject(s).',
];

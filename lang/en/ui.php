<?php

declare(strict_types=1);

// UI strings for the publishable stubs (settings page + grace-period banner). The three
// legal kinds stay separately labeled (contracts / acknowledgements / consents).
return [
    'settings_heading' => 'Your consents',
    'contracts_heading' => 'Contracts',
    'acknowledgements_heading' => 'Acknowledged',
    'consents_heading' => 'Consents',

    // Empty states. A group with no entries used to render its heading over nothing, and that is
    // not the rare case it looks like: statusFor() reads the `legal_documents` table, which a
    // freshly installed package has NOTHING in until `legal-consent:publish` runs — so an empty
    // screen is the SHIPPING state every consumer meets first, and three bare headings read as
    // broken rather than as "nothing yet". `nothing_published` covers all three being empty at
    // once, where one sentence says more than three.
    'nothing_published' => 'Nothing here yet — no legal documents have been published.',
    'contracts_empty' => 'No contracts.',
    'acknowledgements_empty' => 'Nothing acknowledged.',
    'consents_empty' => 'No consents.',
    // The double opt-in's middle state. It needs saying because it is the one position the screen
    // cannot show any other way: entered but not yet confirmed looks exactly like never entered,
    // so without this the subject is invited to enter themselves again — and the second request
    // supersedes the first, which stops the confirmation link already in their inbox from working.
    'confirmation_pending' => 'Confirmation pending — check your inbox.',
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
    'retired' => 'No longer offered',

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
    // The refusal a subject can actually reach — a stale page, a hand-built post or a custom
    // stub asking to withdraw something that was never a consent. It states the position
    // without the document key or the internal type: those are the operator's business, and
    // they go to the log.
    'not_withdrawable' => 'This document cannot be withdrawn — only a consent you gave voluntarily can be.',

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
    'admin_status_release_blocked' => '“:key” was not released: :reasons',
    'admin_status_released' => 'Released “:key” across :count locale(s) — affects :affects subject(s).',
];

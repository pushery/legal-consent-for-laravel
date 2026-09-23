<?php

declare(strict_types=1);

// UI strings for the publishable stubs (settings page + grace-period banner). The three
// legal kinds stay separately labeled ('contracts' / 'acknowledgements' / 'consents').
return [
    // The version line of the document-fragment stub. It is not decoration: somebody reading that
    // fragment in a dialog is about to agree to it, and WHICH version they read is the fact a ledger
    // row will later claim. A fragment showing the text and hiding the version would leave that row
    // unverifiable from the only side that matters — the reader's.
    'document_version' => 'Version :version',
    'document_language' => 'Language of this text: :language',

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
    // The WireKit panel shows the all-empty case as an empty state: a title, and what will appear there.
    'nothing_published_title' => 'No legal documents yet',
    'nothing_published_description' => 'Your contracts, the documents you acknowledged and your consents will appear here once legal documents are published.',
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
    // Shown beside the submit while the request is in flight; the button itself carries
    // aria-busy, so this is the sighted half of the same state.
    'working' => 'Working…',
    'all_current' => 'Everything is up to date — nothing to do.',
    // The one state a settings screen must not blur: a new major version is waiting, so
    // this is the invitation to do voluntarily what the gate will otherwise compel.
    'action_required' => 'Action required',
    'retired' => 'No longer offered',
    // Whether a voluntary consent is given, said in words beside its title. A screen that offers no
    // Give button otherwise shows a consent that is not given as a bare title.
    'consent_given' => 'Given',
    'consent_not_given' => 'Not given',

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
    'admin_not_written_short' => 'None',
    'review_state_draft' => 'Draft',
    'review_state_draft_description' => 'Written, but nobody has vouched for it yet. A document people agree to is not released while any of its languages is still a draft.',
    'review_state_draft_short' => 'Draft',
    'review_state_reviewed' => 'Reviewed',
    'review_state_reviewed_description' => 'A person has read this language and vouched for it. It is not live until the document is released.',
    'review_state_reviewed_short' => 'Reviewed',
    'blocking_not_draft_backed' => 'this document does not take its text from the draft store',
    'blocking_no_draft' => 'no draft has been written',
    'blocking_not_reviewed' => 'not reviewed by a human yet',
    'blocking_stale_translation' => 'the source text changed after this translation was reviewed',
    'blocking_no_change_description' => 'no change description has been written',
    'blocking_incomplete_change_description' => 'the change description has no headline or no impact statement',
    'blocking_stale_change_description' => 'the legal text changed after this change description was written',
    'admin_machine' => 'Machine-drafted',
    'admin_machine_short' => 'Machine',
    'admin_needs_update' => 'Needs update',
    'admin_needs_update_short' => 'Stale',
    'admin_unpublished' => 'Unpublished changes',
    'admin_unpublished_short' => 'Unpublished',
    'admin_release_all' => 'Release all locales',
    'admin_release_confirm_title' => 'Release every locale?',
    'admin_release_confirm_body' => 'All locales of this text are published together as one version. This cannot be undone — a change is a new, higher version.',
    'admin_stale' => 'The source text changed after this translation was reviewed — review it again before releasing.',
    'admin_stale_unconfirmed' => 'This translation has never been confirmed against the source text — review it before releasing.',
    'admin_save' => 'Save',
    'admin_translate' => 'Translate from :locale',
    // The same button while a translation of this draft runs, disabled: it names the wait
    // instead of offering a second run of the same text.
    'admin_translate_running' => 'Translation running…',
    'admin_mark_reviewed' => 'Mark reviewed',
    'admin_review_state' => 'Review state:',
    'admin_discard' => 'Discard draft',
    'admin_discard_confirm_title' => 'Discard the draft?',
    'admin_discard_confirm_body' => 'The draft for :locale will be deleted. Published versions are untouched.',
    'admin_status_draft_discarded' => 'The draft has been discarded.',
    'admin_status_source_not_discarded' => 'The source draft cannot be discarded — every translation measures its freshness against it.',
    'admin_status_no_draft_to_discard' => 'There is no draft for this locale.',
    'admin_preview' => 'Preview',
    'granted_confirmation' => 'Consent given. You can withdraw it at any time.',
    'withdrawn_confirmation' => 'Consent withdrawn. It takes effect immediately.',
    'objected_confirmation' => 'Objection recorded, with the time you sent it.',
    'terminated_confirmation' => 'Termination recorded, with the time you sent it.',
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
    'admin_edit_for_state' => 'Edit :key (:locale) — :state',
    'admin_status_saved' => 'Saved. Review is required before this text can be published.',
    'admin_status_not_saved' => 'Not saved — :reason',
    'admin_status_source_not_translated' => 'The source locale is authored, not translated.',
    'admin_status_no_source' => 'Write the source text first — there is nothing to translate from.',
    // The status a QUEUED translation reports. It names where the result will appear rather than
    // asking the reader to do anything, because there is nothing for them to do: the page polls
    // while the job runs and stops when it stops.
    'admin_status_translation_queued' => 'The translation is running. It appears here once it is done.',
    'admin_status_translation_running' => 'A translation of this draft is already running. Its result appears here once it is done.',

    'admin_status_machine_translated' => 'Machine-translated. A human must review it before it can be published.',
    'admin_status_translation_failed' => 'The translation did not finish. The draft is unchanged, and you can try again.',

    /** The button that closes the document dialog on a consent form. */
    'dialog_close' => 'Close',
    /** The link in the document dialog that opens the same text as its own page, in a new tab. */
    'dialog_open_page' => 'Open as a page',
    'dialog_loading' => 'Loading the text …',
    'dialog_failed' => 'The text could not be loaded.',
    'admin_status_reviewed' => 'Marked reviewed. This text is now publishable.',
    'admin_status_release_blocked' => '“:key” was not released: :reasons',
    'admin_status_released' => 'Released “:key” across :count locale(s) — affects :affects subject(s).',
    'admin_deemed_heading' => 'Release with an objection window',
    'admin_deemed_explainer' => 'A deemed-consent change binds if the objection window closes without an objection. The window must be at least the statutory lead time.',
    'admin_deemed_announce' => 'Announce on',
    'admin_deemed_deadline' => 'Objection deadline',
    'admin_deemed_enforce' => 'Enforce from',
    'admin_deemed_regime' => 'Legal regime',
    'admin_deemed_regime_none' => 'Not classified',
    'admin_deemed_change_class' => 'Change class',
    'admin_deemed_change_class_hint' => 'Your own classification tag, the same one the command line takes — for example agb_minor_peripheral or privacy_material.',
    'admin_deemed_offers_termination' => 'Grants a free right to terminate',
    'admin_deemed_keeps_unmodified' => 'Keeps the unmodified version on offer',
    'admin_deemed_submit' => 'Release with an objection window',
    'admin_status_deemed_window_rejected' => 'Not released — the objection window was rejected: :reason',
    'admin_status_deemed_window_incomplete' => 'Not released — a release with an objection window needs these fields: :fields',
    'admin_status_deemed_dates_unreadable' => 'Not released — these dates could not be read: :fields. Expected YYYY-MM-DD.',
    'lead_time_too_short' => 'a material change to “:document” needs at least :days days between the announcement (:announce) and enforcement (:enforce)',
    'lead_time_too_short_objection' => 'a material change to “:document” needs at least :days days between the announcement (:announce) and the deadline for objections (:deadline)',
    'notice_timeline_inverted' => 'the announcement of “:document” (:announce) falls after the date it takes effect (:enforce); a change has to be announced before it takes effect',
    'publish_refused_version_taken_in_another_mode' => 'version :version is already released in :language with this text under another notice mode; a different mode needs a new version',
    'publish_refused_binding_document_became_informational' => 'it is registered as an informational page now, while its active version :active_version in :language asks people to accept it; keep its legal basis, or register the page under a new key',
    'publish_refused_version_lower_than_active' => 'version :version is lower than the active version :active_version in :language; release a higher version with the older text instead',
    'publish_refused_mode_differs_across_languages' => 'major version :major is already active in :other_language under another notice mode, and :language would go out under this one; every language of one change needs the same mode',
    'publish_refused_major_needs_reconsent' => 'version :version raises the major version in :language, which asks everybody to accept the change again; release it as an active re-consent',
    'publish_refused_gating_mode_keeps_the_major' => 'version :version keeps major version :major in :language, so a re-consent would reach nobody who accepted it; release it as :next',
    'publish_refused_objection_deadline_not_before_effective_date' => 'the objection deadline (:deadline) has to fall before the day the change takes effect (:enforce)',
];

<?php

declare(strict_types=1);

// UI strings for the publishable stubs (settings page + grace-period banner). The three
// legal kinds stay separately labeled ('contracts' / 'acknowledgements' / 'consents').
return [
    // The version line of the document-fragment stub. It is not decoration: somebody reading that
    // fragment in a dialog is about to agree to it, and which version they read is the fact a ledger
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
    // freshly installed package has nothing in until `legal-consent:publish` runs — so an empty
    // screen is the shipping state every consumer meets first, and three bare headings read as
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
    'required_legend' => 'Required',
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

    // Grace-period remaining time (trans_choice): pluralization + effective-today case.
    'days_left' => '{0}Effective today|{1}:count day left|[2,*]:count days left',
    'updated_note' => 'Updated — no action required.',
    'object_review' => 'Review or object',

    // The withdraw confirmation. Art. 7(3) sentence 3: withdrawal must be as easy as
    // giving consent — so this asks once, states the consequence, and never nags.
    'withdraw_confirm_title' => 'Withdraw consent?',
    'withdraw_confirm_body' => 'Your consent to “:title” will be withdrawn. It takes effect immediately and does not affect the lawfulness of processing before it.',
    'cancel' => 'Cancel',
    // The deemed-consent changes on the re-consent form, the page the notice and the banner send the
    // subject to, until they take effect. Objecting and terminating each write an irreversible row,
    // so each asks once.
    'upcoming_changes_heading' => 'Upcoming contract changes',
    'object' => 'Object',
    'object_for' => 'Object: :title',
    'terminate' => 'Terminate free of charge',
    'terminate_for' => 'Terminate free of charge: :title',
    'object_confirm_title' => 'Object to the change?',
    'object_confirm_body' => 'The change to “:title” will then not count as your agreement. Your objection is recorded with the time you send it.',
    'terminate_confirm_title' => 'Terminate free of charge?',
    'terminate_confirm_body' => 'You terminate the contract “:title” free of charge before the change takes effect. Your termination is recorded with the time you send it.',

    // Admin screens (LegalTextManager / LegalTextEditor).
    'admin_heading' => 'Legal texts',
    'admin_policy' => 'Texts are edited per locale, reviewed by a human, then released across every locale at once. A machine translation can never be published until someone reviews it, and the acceptance sentence is fixed copy — it is never machine-translated.',
    'admin_document' => 'Document',
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
    'blocking_nothing_to_release' => 'unchanged since its last release',
    'admin_machine' => 'Machine-drafted',
    'admin_machine_short' => 'Machine',
    'admin_needs_update' => 'Needs update',
    'admin_needs_update_short' => 'Stale',
    'admin_unpublished' => 'Unpublished changes',
    'admin_unpublished_short' => 'Unpublished',
    'admin_release_all' => 'Release all locales',
    'admin_release_confirm_title' => 'Release every locale?',
    'admin_release_confirm_body' => 'All locales of this text are published together as one version. This cannot be undone — a change is a new, higher version.',
    'admin_release_leaves_out' => 'This release leaves out :languages. They keep the version already published in them, if there is one, until their own translation is released.',
    'admin_release_headings' => 'Headings: :headings',
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
    'reconsent_box_unticked' => 'Not ticked yet.',
    'admin_body_label' => 'Text (sanitized HTML)',
    'admin_preview_label' => 'Preview of the published text',
    'admin_edit_for_state' => 'Edit :key (:locale) — :state',
    'admin_cell_name' => ':shown — :name',
    'admin_status_saved' => 'Saved. Review is required before this text can be published.',
    'admin_status_not_saved' => 'Not saved — :reason',
    'admin_status_source_not_translated' => 'The source locale is authored, not translated.',
    'admin_status_no_source' => 'Write the source text first — there is nothing to translate from.',
    // The status a queued translation reports. It names where the result will appear rather than
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
    'admin_status_no_draft_to_review' => 'There is no draft for this locale, so there is nothing to mark reviewed.',
    'admin_status_changed_before_review' => 'The text was written after it was shown here, so it was not marked reviewed. Reload it and review the current text.',
    'admin_status_release_blocked' => '“:key” was not released: :reasons',
    'admin_status_released' => 'Released “:key” across :count locale(s) — affects :affects subject(s).',
    'admin_status_released_uncounted' => 'Released “:key” across :count locale(s). The time allowed was not enough to count the affected subjects.',
    'admin_status_wording_changed' => 'The acceptance sentence changed in :languages, and every consent given from now on records the new one.',
    'admin_save_and_release' => 'Save and publish',
    'admin_release_dialog_title' => 'Publish “:document”',
    'admin_release_dialog_mode' => 'How it is released',
    'admin_release_dialog_languages' => 'Languages',
    'admin_release_dialog_left_out' => 'Left out',
    'admin_release_dialog_version' => 'Version',
    'admin_release_dialog_dates' => 'Dates',
    'admin_release_dialog_regime' => 'Legal regime',
    'admin_release_dialog_heading' => 'Heading',
    'admin_release_dialog_sentence' => 'Acceptance sentence',
    'admin_release_dialog_generic_sentence' => 'The generic sentence, because this document has none of its own. Give it one in lang/vendor/legal-consent/:locale/wording.php.',
    'admin_release_dialog_window' => 'announced :announce, objection deadline :deadline, effective :enforce',
    'admin_release_dialog_now' => 'effective at once',
    'admin_release_dialog_reviewer' => 'Confirming also signs off :language as reviewed by you.',
    'admin_release_dialog_confirm' => 'Confirm and publish',
    'admin_status_release_dialog_open' => 'Saved. Check what the release does, then confirm it.',
    'admin_status_mode_not_offered' => 'This document cannot be released that way.',
    // The modes "save and publish" offers, by NoticeMode value: a label and its consequence.
    'admin_release_modes' => [
        'active_reconsent' => ['label' => 'Ask everyone to accept it again', 'consequence' => 'Everyone has to accept the new version before they go on. It becomes a new major version.'],
        'deemed_consent' => ['label' => 'With an objection window', 'consequence' => 'Everyone is notified and can object until the objection deadline; whoever stays silent accepts. The dates of the form with the objection window apply.'],
        'info_push' => ['label' => 'Announce it', 'consequence' => 'Everyone is notified, and nobody has to accept anything.'],
        'silent_editorial' => ['label' => 'Silently, as an editorial change', 'consequence' => 'Nobody notices: an editorial change without a notice and without acceptance, such as a typo.'],
    ],
    'admin_translation_heading' => 'Add :language',
    'admin_translation_explainer' => 'Changes nothing about the contract and only adds the language: :language gets version :version, which already applies in the other languages. Nobody has to accept it again.',
    'admin_translation_submit' => 'Publish the language',
    'admin_status_translation_published' => ':language is published, in version :version. Nothing about the contract changes.',
    'admin_status_translation_not_offered' => 'This language cannot be added on its own: it is published already, no other language has a version in force that allows it, or the source carries a new version. Release it with the next change.',
    'admin_first_publication_heading' => 'Publish for the first time',
    // By the mode a first publication takes, DocumentType::materialChangeMode().
    'admin_first_publication_explainer' => [
        'active_reconsent' => 'This text has never been published. It applies from now on, and everyone accepts it for the first time on their next visit. No period applies, because there is nothing anyone could object to yet.',
        'info_push' => 'This text has never been published. It applies from now on and is announced to everyone it concerns. No period applies, because there is nothing anyone could object to yet.',
        'silent_editorial' => 'This text has never been published. It applies from now on and goes out silently: nobody has to accept it, and nobody is notified.',
        'deemed_consent' => 'This text has never been published. It applies from now on. No period applies, because there is nothing anyone could object to yet.',
    ],
    'admin_first_publication_submit' => 'Publish now',
    'admin_status_already_published' => 'This text is already published. Release a change to it with the form below.',
    'admin_deemed_heading' => 'Release with an objection window',
    'admin_deemed_explainer' => 'A deemed-consent change binds if the objection window closes without an objection. The window must be at least the statutory lead time.',
    'admin_deemed_announce' => 'Announce on',
    'admin_deemed_deadline' => 'Objection deadline',
    'admin_deemed_enforce' => 'Enforce from',
    'admin_deemed_regime' => 'Legal regime',
    'admin_deemed_regime_none' => 'Not classified',
    'admin_deemed_change_class' => 'Change class (optional)',
    'admin_deemed_change_class_hint' => 'A tag of your own to find this change by later, for example in an audit. The command line takes the same tag. You can leave it empty.',
    'admin_deemed_offers_termination' => 'Grants a free right to terminate',
    'admin_deemed_keeps_unmodified' => 'Keeps the unmodified version on offer',
    'admin_deemed_offers_termination_hint' => 'Needed where the law grants a free right to terminate for such a change, for example for payment services (§ 675g Abs. 2 BGB), digital products (§ 327r Abs. 3 BGB) or platforms for business users (Art. 3(2) Regulation (EU) 2019/1150).',
    'admin_deemed_keeps_unmodified_hint' => 'Whoever objects keeps the version they already hold. Where a digital product for consumers stays usable unchanged at no extra cost this way, there is no right to terminate (§ 327r Abs. 4 BGB).',
    'admin_deemed_lead_mode' => '{1} At least one day between the announcement and the objection deadline, the benchmark for a deemed consent (§ 308 Nr. 5 BGB).|[2,*] At least :count days between the announcement and the objection deadline, the benchmark for a deemed consent (§ 308 Nr. 5 BGB).',
    'admin_deemed_lead_statute' => '{1} At least one day between the announcement and the objection deadline, fixed by law (:source).|[2,*] At least :count days between the announcement and the objection deadline, fixed by law (:source).',
    'admin_deemed_lead_statute_months' => '{1} At least one calendar month, so at least :days days, between the announcement and the objection deadline, fixed by law (:source).|[2,*] At least :count calendar months, so at least :days days, between the announcement and the objection deadline, fixed by law (:source).',
    'admin_deemed_lead_regime_setting' => '{1} At least one day between the announcement and the objection deadline, as set for this regime.|[2,*] At least :count days between the announcement and the objection deadline, as set for this regime.',
    'admin_deemed_lead_document' => '{1} At least one day between the announcement and the objection deadline, as set for this document.|[2,*] At least :count days between the announcement and the objection deadline, as set for this document.',
    'admin_deemed_lead_none' => 'No minimum period applies to this regime.',
    // The minimum's source where a statute sets it, by regime (LegalDocumentPublisher::STATUTORY_FLOORS).
    'admin_deemed_lead_sources' => [
        'psd2_675g' => '§ 675g Abs. 1 BGB',
        'p2b' => 'Art. 3(2) Regulation (EU) 2019/1150',
        'eecc' => 'Art. 105(4) Directive (EU) 2018/1972',
    ],
    // The regimes of LegalDocumentPublisher::REGIMES, named and explained for the editor's form.
    'admin_regimes' => [
        'bgb_agb' => ['name' => 'German standard terms (§§ 305 ff. BGB)', 'description' => 'Standard business terms under German law, the rule for most contracts.'],
        'psd2_675g' => ['name' => 'Payment services (§ 675g BGB)', 'description' => 'A framework contract for payment services. The law demands at least two months of notice.'],
        'dcd_327r' => ['name' => 'Digital products (§ 327r BGB)', 'description' => 'Changes to digital content or services for consumers, announced within a reasonable period.'],
        'gdpr' => ['name' => 'Data protection (GDPR)', 'description' => 'Changes to a privacy notice. The period is a setting, not a number fixed by law.'],
        'p2b' => ['name' => 'Platforms for business users (P2B Regulation)', 'description' => 'Terms of an online intermediation service towards business users, with at least 15 days of notice.'],
        'eecc' => ['name' => 'Electronic communications (EECC)', 'description' => 'Contracts for electronic communications services, with at least one month of notice.'],
    ],
    'admin_preview_show' => 'Show the preview',
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
    'publish_refused_change_class_too_long' => 'the change class has :length characters and can have at most :max',
    'publish_refused_title_is_the_key' => 'it has no title in :language, so its key \':key\' would stay the heading of the version; add the title to :path',
    'draft_refused_no_translator' => 'no machine translation is set up for this application; translate the text by hand, or ask a developer to connect a translator',
    'draft_refused_too_large' => 'the text has :size KB, and a legal text can have at most :limit KB',
    'draft_refused_unparsable' => 'the text could not be read to its end and would have been cut short; markup nested very deeply is the usual cause',
    'draft_refused_translation_truncated' => 'the answer of the translator was cut off at one of its limits, and a translation that stops in the middle of a clause is not kept; split or shorten the text and try again, or translate it by hand',
    'draft_refused_translation_declined' => 'the translator declined this text; try again, or translate it by hand',
    // The name of each ledger action, for a screen that shows an entry: ConsentAction::label().
    'actions' => [
        'granted' => 'Consent given',
        'acknowledged' => 'Acknowledged',
        're_accepted' => 'Accepted again',
        'withdrawn' => 'Withdrawn',
        'declined' => 'Declined',
        'parental' => 'Parental consent',
        'objected' => 'Objected',
        'terminated' => 'Terminated',
        'deemed_accepted' => 'Deemed accepted',
        'optin_requested' => 'Opt-in requested',
        'confirmed' => 'Opt-in confirmed',
    ],
];

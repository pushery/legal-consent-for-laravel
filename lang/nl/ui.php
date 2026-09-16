<?php

declare(strict_types=1);

// UI strings for the publishable stubs (settings page + grace-period banner). The three
// legal kinds stay separately labeled ('contracts' / 'acknowledgements' / 'consents'). Informal (je).
return [
    // The version line of the document-fragment stub. It is not decoration: somebody reading that
    // fragment in a dialog is about to agree to it, and WHICH version they read is the fact a ledger
    // row will later claim. A fragment showing the text and hiding the version would leave that row
    // unverifiable from the only side that matters — the reader's.
    'document_version' => 'Versie :version',

    'settings_heading' => 'Jouw toestemmingen',
    'contracts_heading' => 'Contracten',
    'acknowledgements_heading' => 'Kennisgenomen',
    'consents_heading' => 'Toestemmingen',

    // Empty states. A group with no entries used to render its heading over nothing, and that is
    // not the rare case it looks like: statusFor() reads the `legal_documents` table, which a
    // freshly installed package has NOTHING in until `legal-consent:publish` runs — so an empty
    // screen is the SHIPPING state every consumer meets first, and three bare headings read as
    // broken rather than as "nothing yet". `nothing_published` covers all three being empty at
    // once, where one sentence says more than three.
    'nothing_published' => 'Hier is nog niets — er zijn geen juridische teksten gepubliceerd.',
    'nothing_published_title' => 'Nog geen juridische teksten',
    'nothing_published_description' => 'Jouw contracten, de documenten waarvan je kennis hebt genomen en jouw toestemmingen verschijnen hier zodra er juridische teksten zijn gepubliceerd.',
    'contracts_empty' => 'Geen contracten.',
    'acknowledgements_empty' => 'Niets kennisgenomen.',
    'consents_empty' => 'Geen toestemmingen.',
    // The double opt-in's middle state. It needs saying because it is the one position the screen
    // cannot show any other way: entered but not yet confirmed looks exactly like never entered,
    // so without this the subject is invited to enter themselves again — and the second request
    // supersedes the first, which stops the confirmation link already in their inbox from working.
    'confirmation_pending' => 'Bevestiging open — kijk in je inbox.',
    'grant' => 'Geven',
    'withdraw' => 'Intrekken',
    // Per-item accessible name for the withdraw control: with N consents, N buttons all
    // labeled just 'withdraw' are indistinguishable in a screen reader's button list
    // (WCAG 2.4.6). The grant was document-specific; the withdrawal must be too.
    'grant_for' => 'Geven: :title',
    'withdraw_for' => 'Intrekken: :title',
    // Accessible name for the link to a document's full text. Every row that shows a document
    // carries one, and with N rows a link labeled just 'read' is indistinguishable in a screen
    // reader's link list (WCAG 2.4.4) — the title is what makes each one nameable.
    'read_document' => ':title lezen',
    'review' => 'Nu bekijken',
    // Accessible name for the banner region (it is a `complementary`/`region` landmark,
    // not a live region — a live region present at page load never announces anyway).
    'banner_label' => 'Juridische kennisgevingen',

    // The countdown's expired states — a statement about the subject's position, so each
    // is translated, never left to an English fallback on a legal surface.
    'enforced_now' => 'Nu van kracht',
    'objection_closed' => 'Bezwaartermijn verstreken',
    'submit' => 'Accepteren en doorgaan',
    // Shown beside the submit while the request is in flight; the button itself carries
    // aria-busy, so this is the sighted half of the same state.
    'working' => 'Even geduld…',
    'all_current' => 'Alles is up-to-date — niets te doen.',
    // The one state a settings screen must not blur: a new major version is waiting, so
    // this is the invitation to do voluntarily what the gate will otherwise compel.
    'action_required' => 'Actie vereist',
    'retired' => 'Niet langer aangeboden',
    'consent_given' => 'Gegeven',
    'consent_not_given' => 'Niet gegeven',

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
    'admin_not_written_short' => 'Ontbreekt',
    'review_state_draft' => 'Concept',
    'review_state_draft_short' => 'Concept',
    'review_state_reviewed' => 'Nagekeken',
    'review_state_reviewed_short' => 'Nagekeken',
    'blocking_no_draft' => 'er is nog geen concept geschreven',
    'blocking_not_reviewed' => 'nog niet door een mens nagekeken',
    'blocking_stale_translation' => 'de brontekst is gewijzigd nadat deze vertaling was nagekeken',
    'blocking_no_change_description' => 'er is nog geen wijzigingsbeschrijving geschreven',
    'blocking_incomplete_change_description' => 'de wijzigingsbeschrijving mist een kop of de impact',
    'blocking_stale_change_description' => 'de juridische tekst is gewijzigd nadat deze beschrijving was geschreven',
    'admin_machine' => 'Automatisch concept',
    'admin_machine_short' => 'Automatisch',
    'admin_needs_update' => 'Moet bijgewerkt worden',
    'admin_needs_update_short' => 'Verouderd',
    'admin_unpublished' => 'Niet-gepubliceerde wijzigingen',
    'admin_unpublished_short' => 'Ongepubliceerd',
    'admin_release_all' => 'Alle talen vrijgeven',
    'admin_release_confirm_title' => 'Alle talen vrijgeven?',
    'admin_release_confirm_body' => 'Alle talen van deze tekst worden samen als één versie gepubliceerd. Dit kan niet ongedaan worden gemaakt — een wijziging is een nieuwe, hogere versie.',
    'admin_stale' => 'De brontekst is veranderd nadat deze vertaling was gecontroleerd — controleer haar opnieuw voor vrijgave.',
    'admin_save' => 'Opslaan',
    'admin_translate' => 'Vertalen vanuit :locale',
    'admin_mark_reviewed' => 'Als gecontroleerd markeren',
    'admin_preview' => 'Voorbeeld',
    'granted_confirmation' => 'Toestemming gegeven. Je kunt die op elk moment intrekken.',
    'withdrawn_confirmation' => 'Toestemming ingetrokken. Het gaat direct in.',
    'objected_confirmation' => 'Bezwaar vastgelegd, met het tijdstip waarop je het hebt verstuurd.',
    'terminated_confirmation' => 'Opzegging vastgelegd, met het tijdstip waarop je die hebt verstuurd.',
    // The refusal a subject can actually reach — a stale page, a hand-built post or a custom
    // stub asking to withdraw something that was never a consent. It states the position
    // without the document key or the internal type: those are the operator's business, and
    // they go to the log.
    'not_withdrawable' => 'Dit document kun je niet intrekken — je kunt alleen toestemming intrekken die je vrijwillig hebt gegeven.',

    // Re-consent submit confirmation + plain admin-stub labels (announced / localized).
    'reconsent_recorded' => 'Bedankt — je toestemming is vastgelegd.',
    'reconsent_changed' => 'Dit document is gewijzigd sinds je deze pagina hebt geopend. Bekijk de huidige versie voordat je toestemming geeft.',
    'reconsent_none_selected' => 'Vink elk item aan waarmee je akkoord gaat voordat je verdergaat.',
    'admin_body_label' => 'Tekst (opgeschoonde HTML)',
    'admin_preview_label' => 'Voorbeeld van de gepubliceerde tekst',
    'admin_edit' => 'bewerken',
    'admin_edit_for' => ':key bewerken (:locale)',
    'admin_status_saved' => 'Opgeslagen. Voor publicatie is een controle vereist.',
    'admin_status_not_saved' => 'Niet opgeslagen — :reason',
    'admin_status_source_not_translated' => 'De brontaal wordt geschreven, niet vertaald.',
    'admin_status_no_source' => 'Schrijf eerst de brontekst — er is niets om uit te vertalen.',
    // The status a QUEUED translation reports. It names where the result will appear rather than
    // asking the reader to do anything, because there is nothing for them to do: the page polls
    // while the job runs and stops when it stops.
    'admin_status_translation_queued' => 'De vertaling loopt. Hij verschijnt hier zodra hij klaar is.',

    'admin_status_machine_translated' => 'Machinaal vertaald. Iemand moet de tekst controleren voordat die gepubliceerd kan worden.',
    'admin_status_reviewed' => 'Als gecontroleerd gemarkeerd. Deze tekst kan nu gepubliceerd worden.',
    'admin_status_release_blocked' => '‘:key’ is niet gepubliceerd: :reasons',
    'admin_status_released' => '‘:key’ gepubliceerd in :count taal/talen — betreft :affects perso(o)n(en).',
    'admin_deemed_heading' => 'Publiceren met bezwaartermijn',
    'admin_deemed_explainer' => 'Een wijziging met stilzwijgende instemming bindt als de bezwaartermijn zonder bezwaar afloopt. De termijn moet minstens de wettelijke aankondigingstermijn aanhouden.',
    'admin_deemed_announce' => 'Aankondigen op',
    'admin_deemed_deadline' => 'Uiterste bezwaardatum',
    'admin_deemed_enforce' => 'Van kracht vanaf',
    'admin_deemed_offers_termination' => 'Geeft een kosteloos opzegrecht',
    'admin_deemed_keeps_unmodified' => 'Houdt de ongewijzigde versie beschikbaar',
    'admin_deemed_submit' => 'Publiceren met bezwaartermijn',
    'admin_status_deemed_window_rejected' => 'Niet gepubliceerd — de bezwaartermijn is afgewezen: :reason',
    'lead_time_too_short' => 'een wezenlijke wijziging van ‘:document’ vereist minstens :days dagen tussen de aankondiging (:announce) en de inwerkingtreding (:enforce)',
    'lead_time_too_short_objection' => 'een wezenlijke wijziging van ‘:document’ vereist minstens :days dagen tussen de aankondiging (:announce) en de bezwaartermijn (:deadline)',
];

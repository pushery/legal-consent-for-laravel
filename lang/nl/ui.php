<?php

declare(strict_types=1);

// UI strings for the publishable stubs (settings page + grace-period banner). The three
// legal kinds stay separately labeled ('contracts' / 'acknowledgements' / 'consents'). Informal (je).
return [
    // The version line of the document-fragment stub. It is not decoration: somebody reading that
    // fragment in a dialog is about to agree to it, and which version they read is the fact a ledger
    // row will later claim. A fragment showing the text and hiding the version would leave that row
    // unverifiable from the only side that matters — the reader's.
    'document_version' => 'Versie :version',
    'document_language' => 'Taal van deze tekst: :language',

    'settings_heading' => 'Jouw toestemmingen',
    'contracts_heading' => 'Contracten',
    'acknowledgements_heading' => 'Kennisgenomen',
    'consents_heading' => 'Toestemmingen',

    // Empty states. A group with no entries used to render its heading over nothing, and that is
    // not the rare case it looks like: statusFor() reads the `legal_documents` table, which a
    // freshly installed package has nothing in until `legal-consent:publish` runs — so an empty
    // screen is the shipping state every consumer meets first, and three bare headings read as
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

    // Grace-period remaining time (trans_choice): pluralization + effective-today case.
    'days_left' => '{0}Vandaag van kracht|{1}Nog :count dag|[2,*]Nog :count dagen',
    'updated_note' => 'Bijgewerkt — geen actie nodig.',
    'object_review' => 'Bekijken of bezwaar maken',

    // The withdraw confirmation. Art. 7(3) sentence 3: withdrawal must be as easy as
    // giving consent — so this asks once, states the consequence, and never nags.
    'withdraw_confirm_title' => 'Toestemming intrekken?',
    'withdraw_confirm_body' => 'Je toestemming voor ‘:title’ wordt ingetrokken. Dit gaat direct in en doet geen afbreuk aan de rechtmatigheid van de eerdere verwerking.',
    'cancel' => 'Annuleren',
    // The deemed-consent changes on the re-consent form, the page the notice and the banner send the
    // subject to, until they take effect. Objecting and terminating each write an irreversible row,
    // so each asks once.
    'upcoming_changes_heading' => 'Aankomende contractwijzigingen',
    'object' => 'Bezwaar maken',
    'object_for' => 'Bezwaar maken: :title',
    'terminate' => 'Kosteloos opzeggen',
    'terminate_for' => 'Kosteloos opzeggen: :title',
    'object_confirm_title' => 'Bezwaar maken tegen de wijziging?',
    'object_confirm_body' => 'De wijziging van ‘:title’ geldt dan niet als jouw akkoord. Je bezwaar wordt vastgelegd met het tijdstip waarop je het verstuurt.',
    'terminate_confirm_title' => 'Kosteloos opzeggen?',
    'terminate_confirm_body' => 'Je zegt het contract ‘:title’ kosteloos op voordat de wijziging ingaat. Je opzegging wordt vastgelegd met het tijdstip waarop je die verstuurt.',

    // Admin screens (LegalTextManager / LegalTextEditor).
    'admin_heading' => 'Juridische teksten',
    'admin_policy' => 'Teksten worden per taal bewerkt, door een mens gecontroleerd en dan in alle talen tegelijk vrijgegeven. Een machinevertaling kan nooit worden gepubliceerd voordat iemand haar heeft gecontroleerd, en de acceptatiezin is vaste tekst — die wordt nooit machinaal vertaald.',
    'admin_document' => 'Document',
    'admin_not_written' => 'Niet geschreven',
    'admin_not_written_short' => 'Ontbreekt',
    'review_state_draft' => 'Concept',
    'review_state_draft_description' => 'Geschreven, maar nog niemand staat ervoor in. Een document waarmee mensen instemmen wordt niet vrijgegeven zolang een van zijn talen nog concept is.',
    'review_state_draft_short' => 'Concept',
    'review_state_reviewed' => 'Nagekeken',
    'review_state_reviewed_description' => 'Iemand heeft deze taal gelezen en staat ervoor in. Ze staat pas live als het document wordt vrijgegeven.',
    'review_state_reviewed_short' => 'Nagekeken',
    'blocking_not_draft_backed' => 'dit document haalt zijn tekst niet uit de conceptopslag',
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
    'admin_release_leaves_out' => 'Deze publicatie laat :languages buiten beschouwing. Die talen houden de al gepubliceerde versie, als die er is, tot hun eigen vertaling is vrijgegeven.',
    'admin_release_headings' => 'Koppen: :headings',
    'admin_stale' => 'De brontekst is veranderd nadat deze vertaling was gecontroleerd — controleer haar opnieuw voor vrijgave.',
    'admin_stale_unconfirmed' => 'Deze vertaling is nooit tegen de brontekst bevestigd — controleer haar voor vrijgave.',
    'admin_save' => 'Opslaan',
    'admin_translate' => 'Vertalen vanuit :locale',
    'admin_translate_running' => 'Vertaling loopt…',
    'admin_mark_reviewed' => 'Als gecontroleerd markeren',
    'admin_review_state' => 'Beoordelingsstatus:',
    'admin_discard' => 'Concept verwijderen',
    'admin_discard_confirm_title' => 'Concept verwijderen?',
    'admin_discard_confirm_body' => 'Het concept voor :locale wordt verwijderd. Gepubliceerde versies blijven ongemoeid.',
    'admin_status_draft_discarded' => 'Het concept is verwijderd.',
    'admin_status_source_not_discarded' => 'Het bronconcept kan niet worden verwijderd — elke vertaling meet haar actualiteit eraan af.',
    'admin_status_no_draft_to_discard' => 'Voor deze taal bestaat geen concept.',
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
    'reconsent_box_unticked' => 'Nog niet aangevinkt.',
    'admin_body_label' => 'Tekst (opgeschoonde HTML)',
    'admin_preview_label' => 'Voorbeeld van de gepubliceerde tekst',
    'admin_edit_for_state' => ':key bewerken (:locale) — :state',
    'admin_status_saved' => 'Opgeslagen. Voor publicatie is een controle vereist.',
    'admin_status_not_saved' => 'Niet opgeslagen — :reason',
    'admin_status_source_not_translated' => 'De brontaal wordt geschreven, niet vertaald.',
    'admin_status_no_source' => 'Schrijf eerst de brontekst — er is niets om uit te vertalen.',
    // The status a queued translation reports. It names where the result will appear rather than
    // asking the reader to do anything, because there is nothing for them to do: the page polls
    // while the job runs and stops when it stops.
    'admin_status_translation_queued' => 'De vertaling loopt. Hij verschijnt hier zodra hij klaar is.',
    'admin_status_translation_running' => 'Voor dit concept loopt al een vertaling. Het resultaat verschijnt hier zodra die klaar is.',

    'admin_status_machine_translated' => 'Machinaal vertaald. Iemand moet de tekst controleren voordat die gepubliceerd kan worden.',
    'admin_status_translation_failed' => 'De vertaling is niet afgerond. Het concept is ongewijzigd en je kunt het opnieuw proberen.',

    /** The button that closes the document dialog on a consent form. */
    'dialog_close' => 'Sluiten',
    /** The link in the document dialog that opens the same text as its own page, in a new tab. */
    'dialog_open_page' => 'Openen als pagina',
    'dialog_loading' => 'De tekst wordt geladen …',
    'dialog_failed' => 'De tekst kon niet worden geladen.',
    'admin_status_reviewed' => 'Als gecontroleerd gemarkeerd. Deze tekst kan nu gepubliceerd worden.',
    'admin_status_no_draft_to_review' => 'Voor deze taal bestaat geen concept, dus er is niets om als gecontroleerd te markeren.',
    'admin_status_changed_before_review' => 'De tekst is gewijzigd nadat hij hier werd getoond, en is daarom niet als gecontroleerd gemarkeerd. Laad hem opnieuw en controleer de huidige versie.',
    'admin_status_release_blocked' => '‘:key’ is niet gepubliceerd: :reasons',
    'admin_status_released' => '‘:key’ gepubliceerd in :count taal/talen — betreft :affects perso(o)n(en).',
    'admin_status_released_uncounted' => '‘:key’ gepubliceerd in :count taal/talen. De ingestelde tijd was te kort om de betrokken personen te tellen.',
    'admin_status_wording_changed' => 'De acceptatiezin is gewijzigd in :languages, en elke toestemming vanaf nu legt de nieuwe vast.',
    'admin_save_and_release' => 'Opslaan en publiceren',
    'admin_release_dialog_title' => '„:document” publiceren',
    'admin_release_dialog_mode' => 'Hoe het wordt gepubliceerd',
    'admin_release_dialog_languages' => 'Talen',
    'admin_release_dialog_left_out' => 'Blijven buiten',
    'admin_release_dialog_version' => 'Versie',
    'admin_release_dialog_dates' => 'Data',
    'admin_release_dialog_regime' => 'Rechtsregime',
    'admin_release_dialog_heading' => 'Kop',
    'admin_release_dialog_sentence' => 'Akkoordzin',
    'admin_release_dialog_generic_sentence' => 'De algemene zin, omdat dit document geen eigen zin heeft. Geef het er een in lang/vendor/legal-consent/:locale/wording.php.',
    'admin_release_dialog_window' => 'aankondiging :announce, bezwaartermijn :deadline, geldig vanaf :enforce',
    'admin_release_dialog_now' => 'direct geldig',
    'admin_release_dialog_reviewer' => 'Met de bevestiging teken je :language ook als door jou gecontroleerd af.',
    'admin_release_dialog_confirm' => 'Bevestigen en publiceren',
    'admin_status_release_dialog_open' => 'Opgeslagen. Controleer wat de publicatie doet en bevestig haar.',
    'admin_status_mode_not_offered' => 'Dit document kan zo niet worden gepubliceerd.',
    // The modes "save and publish" offers, by NoticeMode value: a label and its consequence.
    'admin_release_modes' => [
        'active_reconsent' => ['label' => 'Iedereen opnieuw laten accepteren', 'consequence' => 'Iedereen moet de nieuwe versie accepteren voordat hij verdergaat. Het wordt een nieuwe hoofdversie.'],
        'deemed_consent' => ['label' => 'Met bezwaartermijn', 'consequence' => 'Iedereen krijgt een melding en kan tot de bezwaartermijn bezwaar maken; wie zwijgt, stemt in. De data uit het formulier met bezwaartermijn gelden.'],
        'info_push' => ['label' => 'Aankondigen', 'consequence' => 'Iedereen krijgt een melding, en niemand hoeft iets te accepteren.'],
        'silent_editorial' => ['label' => 'Stil, als redactionele wijziging', 'consequence' => 'Niemand merkt iets: een redactionele wijziging zonder melding en zonder akkoord, zoals een typfout.'],
    ],
    'admin_translation_heading' => ':language toevoegen',
    'admin_translation_explainer' => 'Verandert niets aan de overeenkomst en voegt alleen de taal toe: :language krijgt versie :version, die in de andere talen al geldt. Niemand hoeft opnieuw te accepteren.',
    'admin_translation_submit' => 'Taal publiceren',
    'admin_status_translation_published' => ':language is gepubliceerd, in versie :version. Aan de overeenkomst verandert niets.',
    'admin_status_translation_not_offered' => 'Deze taal kan niet los worden toegevoegd: ze is al gepubliceerd, geen andere taal heeft een geldende versie die dat toelaat, of de bron draagt al een nieuwe versie. Publiceer haar met de volgende wijziging.',
    'admin_first_publication_heading' => 'Voor het eerst publiceren',
    // By the mode a first publication takes, DocumentType::materialChangeMode().
    'admin_first_publication_explainer' => [
        'active_reconsent' => 'Deze tekst is nog nooit gepubliceerd. Hij geldt vanaf nu, en iedereen accepteert hem bij het volgende bezoek voor het eerst. Er geldt geen termijn, want er is nog niets waartegen iemand bezwaar kan maken.',
        'info_push' => 'Deze tekst is nog nooit gepubliceerd. Hij geldt vanaf nu en wordt aangekondigd aan iedereen die hij aangaat. Er geldt geen termijn, want er is nog niets waartegen iemand bezwaar kan maken.',
        'silent_editorial' => 'Deze tekst is nog nooit gepubliceerd. Hij geldt vanaf nu en gaat stil uit: niemand hoeft hem te accepteren, en niemand krijgt een melding.',
        'deemed_consent' => 'Deze tekst is nog nooit gepubliceerd. Hij geldt vanaf nu. Er geldt geen termijn, want er is nog niets waartegen iemand bezwaar kan maken.',
    ],
    'admin_first_publication_submit' => 'Nu publiceren',
    'admin_status_already_published' => 'Deze tekst is al gepubliceerd. Een wijziging publiceer je met het formulier hieronder.',
    'admin_deemed_heading' => 'Publiceren met bezwaartermijn',
    'admin_deemed_explainer' => 'Een wijziging met stilzwijgende instemming bindt als de bezwaartermijn zonder bezwaar afloopt. De termijn moet minstens de wettelijke aankondigingstermijn aanhouden.',
    'admin_deemed_announce' => 'Aankondigen op',
    'admin_deemed_deadline' => 'Uiterste bezwaardatum',
    'admin_deemed_enforce' => 'Van kracht vanaf',
    'admin_deemed_regime' => 'Juridisch regime',
    'admin_deemed_regime_none' => 'Niet geclassificeerd',
    'admin_deemed_change_class' => 'Wijzigingsklasse (optioneel)',
    'admin_deemed_change_class_hint' => 'Een eigen label om deze wijziging later terug te vinden, bijvoorbeeld bij een controle. De opdrachtregel neemt hetzelfde label. Je kunt het veld leeg laten.',
    'admin_deemed_offers_termination' => 'Geeft een kosteloos opzegrecht',
    'admin_deemed_keeps_unmodified' => 'Houdt de ongewijzigde versie beschikbaar',
    'admin_deemed_offers_termination_hint' => 'Nodig waar de wet bij zo’n wijziging een kosteloos opzegrecht geeft, bijvoorbeeld bij betaaldiensten (§ 675g Abs. 2 BGB), digitale producten (§ 327r Abs. 3 BGB) of platforms voor zakelijke gebruikers (art. 3, lid 2, Verordening (EU) 2019/1150).',
    'admin_deemed_keeps_unmodified_hint' => 'Wie bezwaar maakt, houdt de versie die hij al heeft. Blijft een digitaal product voor consumenten zo zonder extra kosten ongewijzigd bruikbaar, dan vervalt het opzegrecht (§ 327r Abs. 4 BGB).',
    'admin_deemed_lead_mode' => '{1} Minstens één dag tussen de aankondiging en de bezwaartermijn, de maatstaf voor een fictieve toestemming (§ 308 Nr. 5 BGB).|[2,*] Minstens :count dagen tussen de aankondiging en de bezwaartermijn, de maatstaf voor een fictieve toestemming (§ 308 Nr. 5 BGB).',
    'admin_deemed_lead_statute' => '{1} Minstens één dag tussen de aankondiging en de bezwaartermijn, wettelijk vastgelegd (:source).|[2,*] Minstens :count dagen tussen de aankondiging en de bezwaartermijn, wettelijk vastgelegd (:source).',
    'admin_deemed_lead_statute_months' => '{1} Minstens één kalendermaand, dus minstens :days dagen, tussen de aankondiging en de bezwaartermijn, wettelijk vastgelegd (:source).|[2,*] Minstens :count kalendermaanden, dus minstens :days dagen, tussen de aankondiging en de bezwaartermijn, wettelijk vastgelegd (:source).',
    'admin_deemed_lead_regime_setting' => '{1} Minstens één dag tussen de aankondiging en de bezwaartermijn, zoals ingesteld voor dit regime.|[2,*] Minstens :count dagen tussen de aankondiging en de bezwaartermijn, zoals ingesteld voor dit regime.',
    'admin_deemed_lead_document' => '{1} Minstens één dag tussen de aankondiging en de bezwaartermijn, zoals ingesteld voor dit document.|[2,*] Minstens :count dagen tussen de aankondiging en de bezwaartermijn, zoals ingesteld voor dit document.',
    'admin_deemed_lead_none' => 'Voor dit regime geldt geen minimumtermijn.',
    // The minimum's source where a statute sets it, by regime (LegalDocumentPublisher::STATUTORY_FLOORS).
    'admin_deemed_lead_sources' => [
        'psd2_675g' => '§ 675g Abs. 1 BGB',
        'p2b' => 'art. 3, lid 2, Verordening (EU) 2019/1150',
        'eecc' => 'art. 105, lid 4, Richtlijn (EU) 2018/1972',
    ],
    // The regimes of LegalDocumentPublisher::REGIMES, named and explained for the editor's form.
    'admin_regimes' => [
        'bgb_agb' => ['name' => 'Algemene voorwaarden (§§ 305 e.v. BGB)', 'description' => 'Algemene voorwaarden naar Duits recht, de regel voor de meeste overeenkomsten.'],
        'psd2_675g' => ['name' => 'Betaaldiensten (§ 675g BGB)', 'description' => 'Een raamovereenkomst voor betaaldiensten. De wet eist minstens twee maanden vooraf.'],
        'dcd_327r' => ['name' => 'Digitale producten (§ 327r BGB)', 'description' => 'Wijzigingen in digitale inhoud of diensten voor consumenten, binnen een redelijke termijn aangekondigd.'],
        'gdpr' => ['name' => 'Gegevensbescherming (AVG)', 'description' => 'Wijzigingen in een privacyverklaring. De termijn is een instelling, geen getal uit de wet.'],
        'p2b' => ['name' => 'Platforms voor zakelijke gebruikers (P2B-verordening)', 'description' => 'Voorwaarden van een onlinetussenhandelsdienst tegenover zakelijke gebruikers, met minstens 15 dagen vooraf.'],
        'eecc' => ['name' => 'Elektronische communicatie (ECCC)', 'description' => 'Overeenkomsten voor elektronische-communicatiediensten, met minstens een maand vooraf.'],
    ],
    'admin_preview_show' => 'Voorbeeld tonen',
    'admin_deemed_submit' => 'Publiceren met bezwaartermijn',
    'admin_status_deemed_window_rejected' => 'Niet gepubliceerd — de bezwaartermijn is afgewezen: :reason',
    'admin_status_deemed_window_incomplete' => 'Niet gepubliceerd — een publicatie met bezwaartermijn heeft deze velden nodig: :fields',
    'admin_status_deemed_dates_unreadable' => 'Niet gepubliceerd — deze datums zijn niet leesbaar: :fields. Verwacht: JJJJ-MM-DD.',
    'lead_time_too_short' => 'een wezenlijke wijziging van ‘:document’ vereist minstens :days dagen tussen de aankondiging (:announce) en de inwerkingtreding (:enforce)',
    'lead_time_too_short_objection' => 'een wezenlijke wijziging van ‘:document’ vereist minstens :days dagen tussen de aankondiging (:announce) en de bezwaartermijn (:deadline)',
    'notice_timeline_inverted' => 'de aankondiging van ‘:document’ (:announce) valt na de inwerkingtreding (:enforce); een wijziging moet worden aangekondigd voordat die ingaat',
    'publish_refused_version_taken_in_another_mode' => 'versie :version is voor :language al met deze tekst gepubliceerd onder een andere mededelingsvorm; een andere vorm vraagt om een nieuwe versie',
    'publish_refused_binding_document_became_informational' => 'de pagina staat nu als informatieve pagina geregistreerd, terwijl haar actieve versie :active_version voor :language om instemming vraagt; houd de rechtsgrond aan of registreer de pagina onder een nieuwe sleutel',
    'publish_refused_version_lower_than_active' => 'versie :version is lager dan de actieve versie :active_version voor :language; publiceer in plaats daarvan een hogere versie met de oudere tekst',
    'publish_refused_mode_differs_across_languages' => 'hoofdversie :major is voor :other_language al actief onder een andere mededelingsvorm, en :language zou onder deze vorm uitgaan; alle talen van één wijziging vragen om dezelfde vorm',
    'publish_refused_major_needs_reconsent' => 'versie :version verhoogt voor :language de hoofdversie en vraagt daarmee iedereen opnieuw in te stemmen; publiceer haar als actieve nieuwe instemming',
    'publish_refused_gating_mode_keeps_the_major' => 'versie :version houdt voor :language hoofdversie :major, dus een nieuwe instemming bereikt niemand die al heeft ingestemd; publiceer haar als :next',
    'publish_refused_objection_deadline_not_before_effective_date' => 'de uiterste bezwaardatum (:deadline) moet vóór de dag van inwerkingtreding (:enforce) liggen',
    'publish_refused_change_class_too_long' => 'de wijzigingsklasse heeft :length tekens en mag er hoogstens :max hebben',
    'publish_refused_title_is_the_key' => 'het heeft geen titel voor :language, dus zijn sleutel ‘:key’ zou de kop van de versie blijven; voeg de titel toe in :path',
    'draft_refused_no_translator' => 'voor deze applicatie is geen machinevertaling ingesteld; vertaal de tekst met de hand of vraag een ontwikkelaar om een vertaler aan te sluiten',
    'draft_refused_too_large' => 'de tekst is :size KB, en een juridische tekst mag hoogstens :limit KB zijn',
    'draft_refused_unparsable' => 'de tekst kon niet tot het einde worden gelezen en zou zijn afgekapt; meestal is zeer diep geneste opmaak de oorzaak',
    'draft_refused_translation_truncated' => 'het antwoord van de vertaler is bij een van zijn grenzen afgekapt, en een vertaling die midden in een clausule stopt wordt niet bewaard; splits of verkort de tekst en probeer het opnieuw, of vertaal hem met de hand',
    'draft_refused_translation_declined' => 'de vertaler heeft deze tekst geweigerd; probeer het opnieuw of vertaal hem met de hand',
    // The name of each ledger action, for a screen that shows an entry: ConsentAction::label().
    'actions' => [
        'granted' => 'Toestemming gegeven',
        'acknowledged' => 'Kennisgenomen',
        're_accepted' => 'Opnieuw geaccepteerd',
        'withdrawn' => 'Ingetrokken',
        'declined' => 'Geweigerd',
        'parental' => 'Ouderlijke toestemming',
        'objected' => 'Bezwaar gemaakt',
        'terminated' => 'Opgezegd',
        'deemed_accepted' => 'Stilzwijgend geaccepteerd',
        'optin_requested' => 'Opt-in aangevraagd',
        'confirmed' => 'Opt-in bevestigd',
    ],
];

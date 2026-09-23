<?php

declare(strict_types=1);

// UI strings for the publishable stubs (settings page + grace-period banner). The three
// legal kinds stay separately labeled ('contracts' / 'acknowledgements' / 'consents'). Informal (tú).
return [
    // The version line of the document-fragment stub. It is not decoration: somebody reading that
    // fragment in a dialog is about to agree to it, and WHICH version they read is the fact a ledger
    // row will later claim. A fragment showing the text and hiding the version would leave that row
    // unverifiable from the only side that matters — the reader's.
    'document_version' => 'Versión :version',
    'document_language' => 'Idioma de este texto: :language',

    'settings_heading' => 'Tus consentimientos',
    'contracts_heading' => 'Contratos',
    'acknowledgements_heading' => 'Documentos leídos',
    'consents_heading' => 'Consentimientos',

    // Empty states. A group with no entries used to render its heading over nothing, and that is
    // not the rare case it looks like: statusFor() reads the `legal_documents` table, which a
    // freshly installed package has NOTHING in until `legal-consent:publish` runs — so an empty
    // screen is the SHIPPING state every consumer meets first, and three bare headings read as
    // broken rather than as "nothing yet". `nothing_published` covers all three being empty at
    // once, where one sentence says more than three.
    'nothing_published' => 'Aquí todavía no hay nada: no se ha publicado ningún texto legal.',
    'nothing_published_title' => 'Todavía no hay textos legales',
    'nothing_published_description' => 'Tus contratos, los documentos leídos y tus consentimientos aparecerán aquí en cuanto se publiquen textos legales.',
    'contracts_empty' => 'Ningún contrato.',
    'acknowledgements_empty' => 'Nada leído.',
    'consents_empty' => 'Ningún consentimiento.',
    // The double opt-in's middle state. It needs saying because it is the one position the screen
    // cannot show any other way: entered but not yet confirmed looks exactly like never entered,
    // so without this the subject is invited to enter themselves again — and the second request
    // supersedes the first, which stops the confirmation link already in their inbox from working.
    'confirmation_pending' => 'Pendiente de confirmación: revisa tu buzón.',
    'grant' => 'Dar',
    'withdraw' => 'Retirar',
    // Per-item accessible name for the withdraw control: with N consents, N buttons all
    // labeled just 'withdraw' are indistinguishable in a screen reader's button list
    // (WCAG 2.4.6). The grant was document-specific; the withdrawal must be too.
    'grant_for' => 'Dar: :title',
    'withdraw_for' => 'Retirar: :title',
    // Accessible name for the link to a document's full text. Every row that shows a document
    // carries one, and with N rows a link labeled just 'read' is indistinguishable in a screen
    // reader's link list (WCAG 2.4.4) — the title is what makes each one nameable.
    'read_document' => 'Leer :title',
    'review' => 'Revisar ahora',
    // Accessible name for the banner region (it is a `complementary`/`region` landmark,
    // not a live region — a live region present at page load never announces anyway).
    'banner_label' => 'Avisos legales',

    // The countdown's expired states — a statement about the subject's position, so each
    // is translated, never left to an English fallback on a legal surface.
    'enforced_now' => 'En vigor desde ahora',
    'objection_closed' => 'Plazo de oposición cerrado',
    'submit' => 'Aceptar y continuar',
    // Shown beside the submit while the request is in flight; the button itself carries
    // aria-busy, so this is the sighted half of the same state.
    'working' => 'Un momento…',
    'all_current' => 'Todo está al día — no hay nada que hacer.',
    // The one state a settings screen must not blur: a new major version is waiting, so
    // this is the invitation to do voluntarily what the gate will otherwise compel.
    'action_required' => 'Acción necesaria',
    'retired' => 'Ya no se ofrece',
    'consent_given' => 'Otorgado',
    'consent_not_given' => 'No otorgado',

    // Grace-period remaining time (trans_choice): pluralisation + effective-today case.
    'days_left' => '{0}En vigor hoy|{1}Queda :count día|[2,*]Quedan :count días',
    'updated_note' => 'Actualizado — no se requiere ninguna acción.',
    'object_review' => 'Revisar u oponerte',

    // The withdraw confirmation. Art. 7(3) sentence 3: withdrawal must be as easy as
    // giving consent — so this asks once, states the consequence, and never nags.
    'withdraw_confirm_title' => '¿Retirar el consentimiento?',
    'withdraw_confirm_body' => 'Se retirará tu consentimiento para «:title». Surte efecto de inmediato y no afecta a la licitud del tratamiento anterior.',
    'cancel' => 'Cancelar',

    // Admin screens (LegalTextManager / LegalTextEditor).
    'admin_heading' => 'Textos legales',
    'admin_policy' => 'Los textos se editan por idioma, los revisa una persona y luego se publican en todos los idiomas a la vez. Una traducción automática nunca puede publicarse hasta que alguien la revise, y la frase de aceptación es texto fijo: nunca se traduce automáticamente.',
    'admin_document' => 'Documento',
    'admin_release' => 'Publicar',
    'admin_not_written' => 'Sin redactar',
    'admin_not_written_short' => 'Sin texto',
    'review_state_draft' => 'Borrador',
    'review_state_draft_description' => 'Escrito, pero todavía nadie responde por él. Un documento que las personas aceptan no se publica mientras alguno de sus idiomas siga siendo un borrador.',
    'review_state_draft_short' => 'Borrador',
    'review_state_reviewed' => 'Revisado',
    'review_state_reviewed_description' => 'Una persona ha leído este idioma y responde por él. No está en línea hasta que se publica el documento.',
    'review_state_reviewed_short' => 'Revisado',
    'blocking_not_draft_backed' => 'este documento no toma su texto del almacén de borradores',
    'blocking_no_draft' => 'todavía no se ha escrito ningún borrador',
    'blocking_not_reviewed' => 'todavía no lo ha revisado una persona',
    'blocking_stale_translation' => 'el texto original cambió después de revisar esta traducción',
    'blocking_no_change_description' => 'todavía no se ha escrito ninguna descripción del cambio',
    'blocking_incomplete_change_description' => 'a la descripción del cambio le falta el titular o el impacto',
    'blocking_stale_change_description' => 'el texto legal cambió después de escribir esta descripción del cambio',
    'admin_machine' => 'Borrador automático',
    'admin_machine_short' => 'Automático',
    'admin_needs_update' => 'Necesita actualización',
    'admin_needs_update_short' => 'Desfasado',
    'admin_unpublished' => 'Cambios sin publicar',
    'admin_unpublished_short' => 'Inédito',
    'admin_release_all' => 'Publicar todos los idiomas',
    'admin_release_confirm_title' => '¿Publicar todos los idiomas?',
    'admin_release_confirm_body' => 'Todos los idiomas de este texto se publican juntos como una versión. Esto no se puede deshacer: un cambio es una versión nueva y superior.',
    'admin_stale' => 'El texto de origen cambió después de revisar esta traducción: revísala de nuevo antes de publicar.',
    'admin_stale_unconfirmed' => 'Esta traducción nunca se ha confirmado con el texto de origen: revísala antes de publicar.',
    'admin_save' => 'Guardar',
    'admin_translate' => 'Traducir desde :locale',
    'admin_mark_reviewed' => 'Marcar como revisado',
    'admin_review_state' => 'Estado de revisión:',
    'admin_discard' => 'Descartar borrador',
    'admin_discard_confirm_title' => '¿Descartar el borrador?',
    'admin_discard_confirm_body' => 'Se eliminará el borrador de :locale. Las versiones publicadas no se tocan.',
    'admin_status_draft_discarded' => 'El borrador ha sido descartado.',
    'admin_status_source_not_discarded' => 'El borrador de origen no puede descartarse: cada traducción mide su actualidad con él.',
    'admin_status_no_draft_to_discard' => 'No hay borrador para este idioma.',
    'admin_preview' => 'Vista previa',
    'granted_confirmation' => 'Consentimiento otorgado. Puedes retirarlo cuando quieras.',
    'withdrawn_confirmation' => 'Consentimiento retirado. Surte efecto de inmediato.',
    'objected_confirmation' => 'Oposición registrada, con la hora en que la enviaste.',
    'terminated_confirmation' => 'Rescisión registrada, con la hora en que la enviaste.',
    // The refusal a subject can actually reach — a stale page, a hand-built post or a custom
    // stub asking to withdraw something that was never a consent. It states the position
    // without the document key or the internal type: those are the operator's business, and
    // they go to the log.
    'not_withdrawable' => 'Este documento no se puede retirar — solo se puede retirar un consentimiento que diste voluntariamente.',

    // Re-consent submit confirmation + plain admin-stub labels (announced / localized).
    'reconsent_recorded' => 'Gracias — tu consentimiento se ha registrado.',
    'reconsent_changed' => 'Este documento cambió desde que abriste esta página. Revisa la versión actual antes de dar tu consentimiento.',
    'reconsent_none_selected' => 'Marca cada elemento que aceptas antes de continuar.',
    'admin_body_label' => 'Texto (HTML saneado)',
    'admin_preview_label' => 'Vista previa del texto publicado',
    'admin_edit' => 'editar',
    'admin_edit_for' => 'Editar :key (:locale)',
    'admin_edit_for_state' => 'Editar :key (:locale) — :state',
    'admin_status_saved' => 'Guardado. Se requiere una revisión antes de poder publicar este texto.',
    'admin_status_not_saved' => 'No se ha guardado — :reason',
    'admin_status_source_not_translated' => 'El idioma de origen se redacta, no se traduce.',
    'admin_status_no_source' => 'Escribe primero el texto de origen — no hay nada que traducir.',
    // The status a QUEUED translation reports. It names where the result will appear rather than
    // asking the reader to do anything, because there is nothing for them to do: the page polls
    // while the job runs and stops when it stops.
    'admin_status_translation_queued' => 'La traducción está en curso. Aparecerá aquí en cuanto termine.',

    'admin_status_machine_translated' => 'Traducido automáticamente. Una persona debe revisarlo antes de poder publicarlo.',
    'admin_status_translation_failed' => 'La traducción no ha terminado. El borrador no ha cambiado y puedes volver a intentarlo.',

    /** The button that closes the document dialog on a consent form. */
    'dialog_close' => 'Cerrar',
    /** The link in the document dialog that opens the same text as its own page, in a new tab. */
    'dialog_open_page' => 'Abrir como página',
    'dialog_loading' => 'Cargando el texto …',
    'dialog_failed' => 'No se ha podido cargar el texto.',
    'admin_status_reviewed' => 'Marcado como revisado. Este texto ya se puede publicar.',
    'admin_status_release_blocked' => '«:key» no se publicó: :reasons',
    'admin_status_released' => '«:key» publicado en :count idioma(s) — afecta a :affects persona(s).',
    'admin_deemed_heading' => 'Publicar con plazo de oposición',
    'admin_deemed_explainer' => 'Un cambio con consentimiento tácito vincula si el plazo de oposición termina sin oposición. El plazo debe respetar como mínimo el preaviso legal.',
    'admin_deemed_announce' => 'Anunciar el',
    'admin_deemed_deadline' => 'Fecha límite de oposición',
    'admin_deemed_enforce' => 'En vigor desde',
    'admin_deemed_regime' => 'Régimen jurídico',
    'admin_deemed_regime_none' => 'Sin clasificar',
    'admin_deemed_change_class' => 'Clase de cambio',
    'admin_deemed_change_class_hint' => 'Su propia etiqueta de clasificación, la misma que acepta la línea de comandos — por ejemplo agb_minor_peripheral o privacy_material.',
    'admin_deemed_offers_termination' => 'Concede un derecho de rescisión gratuito',
    'admin_deemed_keeps_unmodified' => 'Mantiene disponible la versión sin modificar',
    'admin_deemed_submit' => 'Publicar con plazo de oposición',
    'admin_status_deemed_window_rejected' => 'No publicado — se rechazó el plazo de oposición: :reason',
    'admin_status_deemed_window_incomplete' => 'No publicado — una publicación con plazo de oposición necesita estos campos: :fields',
    'admin_status_deemed_dates_unreadable' => 'No publicado — estas fechas no se pueden leer: :fields. Se espera AAAA-MM-DD.',
    'lead_time_too_short' => 'un cambio sustancial en «:document» necesita al menos :days días entre el anuncio (:announce) y la entrada en vigor (:enforce)',
    'lead_time_too_short_objection' => 'un cambio sustancial en «:document» necesita al menos :days días entre el anuncio (:announce) y el plazo para oponerse (:deadline)',
    'notice_timeline_inverted' => 'el anuncio de «:document» (:announce) es posterior a su entrada en vigor (:enforce); un cambio debe anunciarse antes de entrar en vigor',
];

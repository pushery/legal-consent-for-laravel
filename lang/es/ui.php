<?php

declare(strict_types=1);

// UI strings for the publishable stubs (settings page + grace-period banner). The three
// legal kinds stay separately labelled (contracts / acknowledgements / consents). Informal (tú).
return [
    'settings_heading' => 'Tus consentimientos',
    'contracts_heading' => 'Contratos',
    'acknowledgements_heading' => 'Documentos leídos',
    'consents_heading' => 'Consentimientos',
    'withdraw' => 'Retirar',
    // Per-item accessible name for the withdraw control: with N consents, N buttons all
    // labelled just 'withdraw' are indistinguishable in a screen reader's button list
    // (WCAG 2.4.6). The grant was document-specific; the withdrawal must be too.
    'withdraw_for' => 'Retirar: :title',
    'review' => 'Revisar ahora',
    // Accessible name for the banner region (it is a `complementary`/`region` landmark,
    // not a live region — a live region present at page load never announces anyway).
    'banner_label' => 'Avisos legales',

    // The countdown's expired states — a statement about the subject's position, so each
    // is translated, never left to an English fallback on a legal surface.
    'enforced_now' => 'En vigor desde ahora',
    'objection_closed' => 'Plazo de oposición cerrado',
    'submit' => 'Aceptar y continuar',
    'all_current' => 'Todo está al día — no hay nada que hacer.',

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
    'admin_machine' => 'Borrador automático',
    'admin_needs_update' => 'Necesita actualización',
    'admin_unpublished' => 'Cambios sin publicar',
    'admin_release_all' => 'Publicar todos los idiomas',
    'admin_release_confirm_title' => '¿Publicar todos los idiomas?',
    'admin_release_confirm_body' => 'Todos los idiomas de este texto se publican juntos como una versión. Esto no se puede deshacer: un cambio es una versión nueva y superior.',
    'admin_stale' => 'El texto de origen cambió después de revisar esta traducción: revísala de nuevo antes de publicar.',
    'admin_save' => 'Guardar',
    'admin_translate' => 'Traducir desde :locale',
    'admin_mark_reviewed' => 'Marcar como revisado',
    'admin_preview' => 'Vista previa',
    'withdrawn_confirmation' => 'Consentimiento retirado. Surte efecto de inmediato.',

    // Re-consent submit confirmation + plain admin-stub labels (announced / localized).
    'reconsent_recorded' => 'Gracias — tu consentimiento se ha registrado.',
    'reconsent_changed' => 'Este documento cambió desde que abriste esta página. Revisa la versión actual antes de dar tu consentimiento.',
    'admin_body_label' => 'Texto (HTML saneado)',
    'admin_preview_label' => 'Vista previa del texto publicado',
    'admin_edit' => 'editar',
    'admin_edit_for' => 'Editar :key (:locale)',
    'admin_status_saved' => 'Guardado. Se requiere una revisión antes de poder publicar este texto.',
    'admin_status_source_not_translated' => 'El idioma de origen se redacta, no se traduce.',
    'admin_status_no_source' => 'Escribe primero el texto de origen — no hay nada que traducir.',
    'admin_status_machine_translated' => 'Traducido automáticamente. Una persona debe revisarlo antes de poder publicarlo.',
    'admin_status_reviewed' => 'Marcado como revisado. Este texto ya se puede publicar.',
    'admin_status_release_blocked' => '«:key» no se publicó: :reasons',
    'admin_status_released' => '«:key» publicado en :count idioma(s) — afecta a :affects persona(s).',
];

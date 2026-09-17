<?php

declare(strict_types=1);

// UI strings for the publishable stubs (settings page + grace-period banner). The three
// legal kinds stay separately labeled ('contracts' / 'acknowledgements' / 'consents'). Informal (tu).
return [
    // The version line of the document-fragment stub. It is not decoration: somebody reading that
    // fragment in a dialog is about to agree to it, and WHICH version they read is the fact a ledger
    // row will later claim. A fragment showing the text and hiding the version would leave that row
    // unverifiable from the only side that matters — the reader's.
    'document_version' => 'Versão :version',

    'settings_heading' => 'Os teus consentimentos',
    'contracts_heading' => 'Contratos',
    'acknowledgements_heading' => 'Tomado conhecimento',
    'consents_heading' => 'Consentimentos',

    // Empty states. A group with no entries used to render its heading over nothing, and that is
    // not the rare case it looks like: statusFor() reads the `legal_documents` table, which a
    // freshly installed package has NOTHING in until `legal-consent:publish` runs — so an empty
    // screen is the SHIPPING state every consumer meets first, and three bare headings read as
    // broken rather than as "nothing yet". `nothing_published` covers all three being empty at
    // once, where one sentence says more than three.
    'nothing_published' => 'Ainda não há nada aqui — não foi publicado nenhum texto legal.',
    'nothing_published_title' => 'Ainda não há textos legais',
    'nothing_published_description' => 'Os teus contratos, os documentos de que tomaste conhecimento e os teus consentimentos aparecem aqui assim que forem publicados textos legais.',
    'contracts_empty' => 'Nenhum contrato.',
    'acknowledgements_empty' => 'Nada lido.',
    'consents_empty' => 'Nenhum consentimento.',
    // The double opt-in's middle state. It needs saying because it is the one position the screen
    // cannot show any other way: entered but not yet confirmed looks exactly like never entered,
    // so without this the subject is invited to enter themselves again — and the second request
    // supersedes the first, which stops the confirmation link already in their inbox from working.
    'confirmation_pending' => 'À espera de confirmação — vê a tua caixa de entrada.',
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
    'read_document' => 'Ler :title',
    'review' => 'Rever agora',
    // Accessible name for the banner region (it is a `complementary`/`region` landmark,
    // not a live region — a live region present at page load never announces anyway).
    'banner_label' => 'Avisos legais',

    // The countdown's expired states — a statement about the subject's position, so each
    // is translated, never left to an English fallback on a legal surface.
    'enforced_now' => 'Em vigor agora',
    'objection_closed' => 'Prazo de oposição encerrado',
    'submit' => 'Aceitar e continuar',
    // Shown beside the submit while the request is in flight; the button itself carries
    // aria-busy, so this is the sighted half of the same state.
    'working' => 'Um momento…',
    'all_current' => 'Está tudo em dia — nada a fazer.',
    // The one state a settings screen must not blur: a new major version is waiting, so
    // this is the invitation to do voluntarily what the gate will otherwise compel.
    'action_required' => 'Ação necessária',
    'retired' => 'Já não é oferecido',
    'consent_given' => 'Dado',
    'consent_not_given' => 'Não dado',

    // Grace-period remaining time (trans_choice): pluralisation + effective-today case.
    'days_left' => '{0}Em vigor hoje|{1}Falta :count dia|[2,*]Faltam :count dias',
    'updated_note' => 'Atualizado — não é necessária qualquer ação.',
    'object_review' => 'Ver ou opor-te',

    // The withdraw confirmation. Art. 7(3) sentence 3: withdrawal must be as easy as
    // giving consent — so this asks once, states the consequence, and never nags.
    'withdraw_confirm_title' => 'Retirar o consentimento?',
    'withdraw_confirm_body' => 'O teu consentimento para «:title» será retirado. Produz efeitos imediatos e não afeta a licitude do tratamento anterior.',
    'cancel' => 'Cancelar',

    // Admin screens (LegalTextManager / LegalTextEditor).
    'admin_heading' => 'Textos legais',
    'admin_policy' => 'Os textos são editados por idioma, revistos por uma pessoa e depois publicados em todos os idiomas ao mesmo tempo. Uma tradução automática nunca pode ser publicada até alguém a rever, e a frase de aceitação é texto fixo — nunca é traduzida automaticamente.',
    'admin_document' => 'Documento',
    'admin_release' => 'Publicar',
    'admin_not_written' => 'Por escrever',
    'admin_not_written_short' => 'Sem texto',
    'review_state_draft' => 'Rascunho',
    'review_state_draft_short' => 'Rascunho',
    'review_state_reviewed' => 'Revisto',
    'review_state_reviewed_short' => 'Revisto',
    'blocking_not_draft_backed' => 'este documento não obtém o seu texto do arquivo de rascunhos',
    'blocking_no_draft' => 'ainda não foi escrito nenhum rascunho',
    'blocking_not_reviewed' => 'ainda não foi revisto por uma pessoa',
    'blocking_stale_translation' => 'o texto de origem mudou depois de esta tradução ser revista',
    'blocking_no_change_description' => 'ainda não foi escrita nenhuma descrição da alteração',
    'blocking_incomplete_change_description' => 'falta o título ou o impacto na descrição da alteração',
    'blocking_stale_change_description' => 'o texto legal mudou depois de esta descrição ser escrita',
    'admin_machine' => 'Rascunho automático',
    'admin_machine_short' => 'Automático',
    'admin_needs_update' => 'Precisa de atualização',
    'admin_needs_update_short' => 'Desatualizado',
    'admin_unpublished' => 'Alterações não publicadas',
    'admin_unpublished_short' => 'Inédito',
    'admin_release_all' => 'Publicar todos os idiomas',
    'admin_release_confirm_title' => 'Publicar todos os idiomas?',
    'admin_release_confirm_body' => 'Todos os idiomas deste texto são publicados juntos como uma versão. Isto não pode ser revertido — uma alteração é uma versão nova e superior.',
    'admin_stale' => 'O texto de origem mudou depois de esta tradução ser revista — revê-a de novo antes de publicar.',
    'admin_stale_unconfirmed' => 'Esta tradução nunca foi confirmada face ao texto de origem — revê-a antes de publicar.',
    'admin_save' => 'Guardar',
    'admin_translate' => 'Traduzir de :locale',
    'admin_mark_reviewed' => 'Marcar como revisto',
    'admin_review_state' => 'Estado de revisão:',
    'admin_discard' => 'Descartar rascunho',
    'admin_discard_confirm_title' => 'Descartar o rascunho?',
    'admin_discard_confirm_body' => 'O rascunho de :locale será eliminado. As versões publicadas mantêm-se.',
    'admin_status_draft_discarded' => 'O rascunho foi descartado.',
    'admin_status_source_not_discarded' => 'O rascunho de origem não pode ser descartado — cada tradução mede nele a sua atualidade.',
    'admin_status_no_draft_to_discard' => 'Não existe rascunho para este idioma.',
    'admin_preview' => 'Pré-visualização',
    'granted_confirmation' => 'Consentimento dado. Podes retirá-lo quando quiseres.',
    'withdrawn_confirmation' => 'Consentimento retirado. Produz efeito de imediato.',
    'objected_confirmation' => 'Oposição registada, com a hora a que a enviaste.',
    'terminated_confirmation' => 'Rescisão registada, com a hora a que a enviaste.',
    // The refusal a subject can actually reach — a stale page, a hand-built post or a custom
    // stub asking to withdraw something that was never a consent. It states the position
    // without the document key or the internal type: those are the operator's business, and
    // they go to the log.
    'not_withdrawable' => 'Este documento não pode ser retirado — só podes retirar um consentimento que deste voluntariamente.',

    // Re-consent submit confirmation + plain admin-stub labels (announced / localized).
    'reconsent_recorded' => 'Obrigado — o teu consentimento foi registado.',
    'reconsent_changed' => 'Este documento mudou desde que abriste esta página. Revê a versão atual antes de consentires.',
    'reconsent_none_selected' => 'Marca cada item que aceitas antes de continuar.',
    'admin_body_label' => 'Texto (HTML higienizado)',
    'admin_preview_label' => 'Pré-visualização do texto publicado',
    'admin_edit' => 'editar',
    'admin_edit_for' => 'Editar :key (:locale)',
    'admin_edit_for_state' => 'Editar :key (:locale) — :state',
    'admin_status_saved' => 'Guardado. É necessária uma revisão antes de este texto poder ser publicado.',
    'admin_status_not_saved' => 'Não guardado — :reason',
    'admin_status_source_not_translated' => 'O idioma de origem é redigido, não traduzido.',
    'admin_status_no_source' => 'Escreve primeiro o texto de origem — não há nada a partir do qual traduzir.',
    // The status a QUEUED translation reports. It names where the result will appear rather than
    // asking the reader to do anything, because there is nothing for them to do: the page polls
    // while the job runs and stops when it stops.
    'admin_status_translation_queued' => 'A tradução está em curso. Aparecerá aqui assim que terminar.',

    'admin_status_machine_translated' => 'Traduzido automaticamente. Uma pessoa tem de o rever antes de poder ser publicado.',
    'admin_status_translation_failed' => 'A tradução não chegou ao fim. O rascunho está inalterado e podes tentar novamente.',

    /** The button that closes the document dialog on a consent form. */
    'dialog_close' => 'Fechar',
    'dialog_loading' => 'A carregar o texto …',
    'dialog_failed' => 'Não foi possível carregar o texto. A ligação ao lado abre a página.',
    'admin_status_reviewed' => 'Marcado como revisto. Este texto já pode ser publicado.',
    'admin_status_release_blocked' => '«:key» não foi publicado: :reasons',
    'admin_status_released' => '«:key» publicado em :count idioma(s) — afeta :affects pessoa(s).',
    'admin_deemed_heading' => 'Publicar com prazo de oposição',
    'admin_deemed_explainer' => 'Uma alteração com consentimento tácito vincula se o prazo de oposição terminar sem oposição. O prazo tem de respeitar pelo menos o pré-aviso legal.',
    'admin_deemed_announce' => 'Anunciar a',
    'admin_deemed_deadline' => 'Prazo de oposição',
    'admin_deemed_enforce' => 'Em vigor a partir de',
    'admin_deemed_regime' => 'Regime jurídico',
    'admin_deemed_regime_none' => 'Não classificada',
    'admin_deemed_change_class' => 'Classe de alteração',
    'admin_deemed_change_class_hint' => 'A sua própria etiqueta de classificação, a mesma que a linha de comandos aceita — por exemplo agb_minor_peripheral ou privacy_material.',
    'admin_deemed_offers_termination' => 'Concede um direito de rescisão gratuito',
    'admin_deemed_keeps_unmodified' => 'Mantém disponível a versão não alterada',
    'admin_deemed_submit' => 'Publicar com prazo de oposição',
    'admin_status_deemed_window_rejected' => 'Não publicado — o prazo de oposição foi recusado: :reason',
    'lead_time_too_short' => 'uma alteração substancial em «:document» exige pelo menos :days dias entre o anúncio (:announce) e a entrada em vigor (:enforce)',
    'lead_time_too_short_objection' => 'uma alteração substancial em «:document» exige pelo menos :days dias entre o anúncio (:announce) e o prazo para oposição (:deadline)',
];

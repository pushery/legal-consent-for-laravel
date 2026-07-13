<?php

declare(strict_types=1);

// Re-consent notification, split by legal basis: a contract asks for renewed AGREEMENT,
// a privacy policy only for ACKNOWLEDGEMENT (never "agree" — EDPB 05/2020 §122). The
// consequence line satisfies § 308 Nr. 5 lit. b BGB.
return [
    'contract' => [
        'subject' => 'Importante: termos de utilização atualizados',
        'intro' => 'Atualizámos os nossos termos de utilização e pedimos-te que os aceites novamente.',
        'cta' => 'Rever e aceitar agora',
        'consequence' => 'Aceita a tempo — caso contrário, a utilização ficará restrita a partir da data de entrada em vigor.',
    ],
    'acknowledgement' => [
        'subject' => 'Importante: política de privacidade atualizada',
        'intro' => 'Atualizámos a nossa política de privacidade e pedimos-te que tomes conhecimento da nova versão.',
        'cta' => 'Rever e confirmar a leitura agora',
        'consequence' => 'Toma conhecimento a tempo — caso contrário, a utilização ficará restrita a partir da data de entrada em vigor.',
    ],
];

<?php

declare(strict_types=1);

// Two notifications, kept legally distinct:
//  - `contract`      ReconsentRequired: a material CONTRACT change asks for renewed
//                    AGREEMENT; the consequence line satisfies § 308 Nr. 5 lit. b BGB.
//  - `informational` LegalChangeInformational: an INFO-ONLY change — NO action required,
//                    never a threat of restriction. A privacy notice is acknowledged, never
//                    agreed to (EDPB 05/2020 § 122).
return [
    'contract' => [
        'subject' => 'Importante: termos de utilização atualizados',
        'intro' => 'Atualizámos os nossos termos de utilização e pedimos-te que os aceites novamente.',
        'cta' => 'Rever e aceitar agora',
        'consequence' => 'Aceita a tempo — caso contrário, a utilização ficará restrita a partir da data de entrada em vigor.',
    ],
    'informational' => [
        'contract' => [
            'subject' => 'Alterações ao nosso contrato',
            'intro' => 'Atualizámos o nosso contrato. Não é necessária qualquer ação da tua parte.',
            'cta' => 'Ver as alterações',
            'effective' => 'As alterações entram em vigor a :deadline.',
            'objection' => 'Se não concordares com as alterações, podes rescindir gratuitamente até :deadline.',
        ],
        'acknowledgement' => [
            'subject' => 'Política de privacidade atualizada',
            'intro' => 'Atualizámos a nossa política de privacidade. Toma conhecimento da nova versão — não é necessária qualquer ação.',
            'cta' => 'Ver a nova versão',
            'effective' => 'A versão atualizada aplica-se a partir de :deadline.',
            'objection' => 'Podes opor-te ao tratamento a qualquer momento.',
        ],
    ],
    'deemed' => [
        'subject' => 'Uma alteração ao nosso contrato',
        'intro' => 'Vamos atualizar o nosso contrato («:title»).',
        'warning' => 'Se não te opuseres até :deadline, tal será considerado a tua aceitação das alterações.',
        'cta' => 'Ver as alterações e opor-te, se quiseres',
        'termination' => 'Podes rescindir o contrato gratuitamente até :effective.',
    ],
];

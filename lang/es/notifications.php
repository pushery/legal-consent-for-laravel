<?php

declare(strict_types=1);

// Dos notificaciones, jurídicamente distintas: `contract` (ReconsentRequired, nueva
// aceptación de un cambio material del contrato — § 308 Nr. 5 lit. b BGB) e `informational`
// (LegalChangeInformational, cambio SOLO INFORMATIVO, sin acción, sin amenaza de
// restricción; una política de privacidad se toma en conocimiento, nunca se «acepta» —
// EDPB 05/2020 § 122). Registro informal (tú).
return [
    'contract' => [
        'subject' => 'Importante: condiciones de uso actualizadas',
        'intro' => 'Hemos actualizado nuestras condiciones de uso y necesitamos que las aceptes de nuevo.',
        'cta' => 'Revisar y aceptar ahora',
        'consequence' => 'Por favor, acepta a tiempo — de lo contrario, el uso quedará restringido a partir de la fecha de entrada en vigor.',
    ],
    'informational' => [
        'contract' => [
            'subject' => 'Cambios en nuestro contrato',
            'intro' => 'Hemos actualizado nuestro contrato. No es necesaria ninguna acción por tu parte.',
            'cta' => 'Ver los cambios',
            'effective' => 'Los cambios entran en vigor el :deadline.',
            'objection' => 'Si no estás de acuerdo con los cambios, puedes cancelar de forma gratuita hasta el :deadline.',
        ],
        'acknowledgement' => [
            'subject' => 'Política de privacidad actualizada',
            'intro' => 'Hemos actualizado nuestra política de privacidad. Toma nota de la nueva versión — no es necesaria ninguna acción.',
            'cta' => 'Ver la nueva versión',
            'effective' => 'La versión actualizada se aplica a partir del :deadline.',
            'objection' => 'Puedes oponerte al tratamiento en cualquier momento.',
        ],
    ],
    'deemed' => [
        'subject' => 'Una modificación de nuestro contrato',
        'intro' => 'Vamos a actualizar nuestro contrato («:title»).',
        'warning' => 'Si no te opones antes del :deadline, se considerará que aceptas los cambios.',
        'cta' => 'Revisar los cambios y oponerte si lo deseas',
        'termination' => 'Puedes rescindir el contrato de forma gratuita hasta el :effective.',
    ],
];

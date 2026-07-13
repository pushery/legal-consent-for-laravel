<?php

declare(strict_types=1);

// Re-consent notification, split by legal basis: a contract asks for renewed AGREEMENT,
// a privacy policy only for ACKNOWLEDGEMENT (never "agree" — EDPB 05/2020 §122). The
// consequence line satisfies § 308 Nr. 5 lit. b BGB.
return [
    'contract' => [
        'subject' => 'Importante: condiciones de uso actualizadas',
        'intro' => 'Hemos actualizado nuestras condiciones de uso y necesitamos que las aceptes de nuevo.',
        'cta' => 'Revisar y aceptar ahora',
        'consequence' => 'Por favor, acepta a tiempo — de lo contrario, el uso quedará restringido a partir de la fecha de entrada en vigor.',
    ],
    'acknowledgement' => [
        'subject' => 'Importante: política de privacidad actualizada',
        'intro' => 'Hemos actualizado nuestra política de privacidad y te pedimos que tomes nota de la nueva versión.',
        'cta' => 'Revisar y confirmar la lectura ahora',
        'consequence' => 'Por favor, toma nota a tiempo — de lo contrario, el uso quedará restringido a partir de la fecha de entrada en vigor.',
    ],
];

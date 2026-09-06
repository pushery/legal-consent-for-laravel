<?php

declare(strict_types=1);

// Acceptance sentences per document type. IMPORTANT: for privacy never say "I consent" —
// a privacy policy is information (Art. 13), not consent (EDPB 05/2020 §122).
return [
    'terms' => 'Acepto las condiciones de uso.',
    'privacy' => 'He leído la política de privacidad.',
    // `el boletín`, not `la newsletter`: the Spanish TITLE is `Boletín`, so the loanword in the
    // sentence left the heading and the acceptance text naming the same document differently — and
    // it was the only one of 21 locale/type pairs where the title does not appear in its own
    // sentence, so the name could not become the link the way it does everywhere else. Dutch is the
    // other locale that translates the term, and it translates both halves.
    'newsletter' => 'Quiero recibir el boletín (voluntario, revocable en cualquier momento).',
    'default' => 'He leído y acepto las condiciones.',
];

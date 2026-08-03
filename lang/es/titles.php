<?php

declare(strict_types=1);

// Document headings, per document key. Vendor copy, never draft content — so the heading of a
// frozen version can never be machine-translated into the ledger by accident. A consumer with
// additional document keys publishes this file and adds them; an unknown key falls back to the
// key itself.
//
// The last three are `informational` pages: published, kept current, and binding nobody. They
// are here because every consumer that registers one names it something, and without a heading
// the page renders under its raw key ("imprint"). Rename or remove them freely — they are
// conventional keys, not reserved ones.
return [
    'terms' => 'Condiciones de uso',
    'privacy' => 'Política de privacidad',
    'newsletter' => 'Boletín',
    'imprint' => 'Aviso legal',
    'cookies' => 'Política de cookies',
    'accessibility' => 'Declaración de accesibilidad',
];

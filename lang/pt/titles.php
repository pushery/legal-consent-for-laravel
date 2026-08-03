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
    'terms' => 'Termos de utilização',
    'privacy' => 'Política de privacidade',
    'newsletter' => 'Newsletter',
    'imprint' => 'Informação legal',
    'cookies' => 'Política de cookies',
    'accessibility' => 'Declaração de acessibilidade',
];

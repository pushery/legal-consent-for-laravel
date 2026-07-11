<?php

declare(strict_types=1);

// Re-consent notification, split by legal basis: a contract asks for renewed AGREEMENT,
// a privacy policy only for ACKNOWLEDGEMENT (never "agree" — EDPB 05/2020 §122). The
// consequence line satisfies § 308 Nr. 5 lit. b BGB.
return [
    'contract' => [
        'subject' => 'Important: updated terms of use',
        'intro' => 'We have updated our terms of use and need your renewed agreement.',
        'cta' => 'Review and agree now',
        'consequence' => 'Please agree in time — otherwise continued use will be restricted from the effective date.',
    ],
    'acknowledgement' => [
        'subject' => 'Important: updated privacy policy',
        'intro' => 'We have updated our privacy policy and ask you to take note of the new version.',
        'cta' => 'Review and acknowledge now',
        'consequence' => 'Please take note in time — otherwise continued use will be restricted from the effective date.',
    ],
];

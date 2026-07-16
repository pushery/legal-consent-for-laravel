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
        'subject' => 'Important: updated terms of use',
        'intro' => 'We have updated our terms of use and need your renewed agreement.',
        'cta' => 'Review and agree now',
        'consequence' => 'Please agree in time — otherwise continued use will be restricted from the effective date.',
    ],
    'informational' => [
        'contract' => [
            'subject' => 'Changes to our contract',
            'intro' => 'We have updated our contract. No action is required on your part.',
            'cta' => 'Review the changes',
            'effective' => 'The changes take effect on :deadline.',
            'objection' => 'If you do not agree with the changes, you can terminate free of charge until :deadline.',
        ],
        'acknowledgement' => [
            'subject' => 'Updated privacy policy',
            'intro' => 'We have updated our privacy policy. Please take note of the new version — no action is required.',
            'cta' => 'View the new version',
            'effective' => 'The updated version applies from :deadline.',
            'objection' => 'You can object to the processing at any time.',
        ],
    ],
    // DeemedConsentNotice: a minor/peripheral contract change with deemed consent. The
    // `warning` line is the § 308 Nr. 5 lit. b BGB special warning — a validity condition, not
    // courtesy copy. Contract only (silence never binds a privacy notice or a real consent).
    'deemed' => [
        'subject' => 'A change to our contract',
        'intro' => 'We are updating our contract (":title").',
        'warning' => 'If you do not object by :deadline, this will be treated as your agreement to the changes.',
        'cta' => 'Review the changes and object if you wish',
        'termination' => 'You can terminate the contract free of charge until :effective.',
    ],
];

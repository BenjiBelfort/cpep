<?php

return [
    'smtp_host' => 'ssl0.ovh.net',
    'smtp_port' => 465,
    'smtp_secure' => 'ssl',

    'smtp_user' => 'formulaire@cpep.fr',
    'smtp_pass' => 'TON_MOT_DE_PASSE_EMAIL_OVH',

    'mail_from' => 'formulaire@cpep.fr',
    'mail_from_name' => 'CPEP',

    'mail_to' => 'contact@cpep.fr',
    'mail_to_name' => 'CPEP',


    // Anti-spam
    'min_submit_time' => 3,
    'max_submit_time' => 3600,
    'rate_limit_window' => 3600,
    'rate_limit_max' => 5,

    // Validation
    'max_name_length' => 120,
    'max_email_length' => 180,
    'max_phone_length' => 40,
    'max_company_length' => 160,
    'max_message_length' => 4000,
    'max_links_in_message' => 2,
];
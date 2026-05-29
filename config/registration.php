<?php

return [
    /*
  |--------------------------------------------------------------------------
  | Bot protection (registration)
  |--------------------------------------------------------------------------
  */
    'honeypot_field' => 'contact_website',

    'min_submit_seconds' => 3,

    'max_form_age_seconds' => 7200,

    'rate_limit' => [
        'per_ip_per_day' => 10,
        'global_per_hour' => 50,
    ],

    'name' => [
        'require_two_words' => true,
        'min_word_length' => 2,
        'max_single_word_length' => 24,
    ],

    'disposable_domains' => [
        'mailinator.com',
        'guerrillamail.com',
        'guerrillamail.net',
        'guerrillamail.org',
        'sharklasers.com',
        'grr.la',
        'guerrillamailblock.com',
        'pokemail.net',
        'spam4.me',
        'temp-mail.org',
        'tempmail.com',
        'tempmail.net',
        'throwaway.email',
        'yopmail.com',
        'yopmail.fr',
        '10minutemail.com',
        '10minutemail.net',
        'minutemail.com',
        'getnada.com',
        'dispostable.com',
        'maildrop.cc',
        'trashmail.com',
        'trashmail.me',
        'fakeinbox.com',
        'mintemail.com',
        'emailondeck.com',
        'tempinbox.com',
        'moakt.com',
        'mailnesia.com',
        'spamgourmet.com',
        'mytemp.email',
        'tmpmail.org',
        'tmpmail.net',
        'burnermail.io',
        'inboxkitten.com',
        'mailcatch.com',
        'mohmal.com',
        'harakirimail.com',
        'mail.tm',
        'emailfake.com',
        'crazymailing.com',
        'tempr.email',
        'dropmail.me',
        'mailsac.com',
        'mailpoof.com',
        'fakemailgenerator.com',
        'mailinator.net',
        'mailinator.org',
        'mailinator2.com',
    ],

    'check_mx_record' => false,
];

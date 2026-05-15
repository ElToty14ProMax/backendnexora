<?php

return [
    'env' => env('NEXORA_ENV', env('APP_ENV', 'local')),
    'admin_token' => env('NEXORA_ADMIN_TOKEN', 'dev-admin-token-change-me'),
    'admin_pix_key' => env('NEXORA_ADMIN_PIX_KEY'),
    'data_key_b64' => env('NEXORA_DATA_KEY_B64'),
    'cpf_pepper' => env('NEXORA_CPF_PEPPER', 'nexora-local-dev-cpf-pepper-change-before-production'),
    'super_admin_email' => strtolower(trim(env('NEXORA_SUPER_ADMIN_EMAIL', 'admin@nexora.local'))),
    'super_admin_cpf' => preg_replace('/\D+/', '', env('NEXORA_SUPER_ADMIN_CPF', '00000000000')),
    'super_admin_password' => env('NEXORA_SUPER_ADMIN_PASSWORD'),
    'founder_emails' => array_values(array_filter(array_map(
        fn (string $email) => strtolower(trim($email)),
        explode(',', env('NEXORA_FOUNDER_EMAILS', env('NEXORA_SUPER_ADMIN_EMAIL', 'admin@nexora.local')))
    ))),
];

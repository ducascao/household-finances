<?php

return [
    /*
     * Disco dos comprovantes. Vazio = Google Drive conectado do lar.
     * Em desenvolvimento sem conta Google dá para usar "local".
     */
    'disk' => env('ATTACHMENTS_DISK') ?: null,

    'max_kb' => 10240,

    'mime_types' => [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/heic',
        'image/heif',
    ],

    'default_root_folder' => 'Finanças de Casa',
];

<?php

return [
    'storage' => env('PAYMENT_RECEIPT_STORAGE', 'local'),
    'local_disk' => 'local',
    'supabase' => [
        'url' => env('SUPABASE_URL'),
        'secret_key' => env('SUPABASE_SECRET_KEY'),
        'bucket' => env('SUPABASE_STORAGE_BUCKET', 'payment-receipts'),
    ],
];

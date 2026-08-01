<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Chrome Binary
    |--------------------------------------------------------------------------
    |
    | Path ke binary Chromium/Chrome di server (aaPanel).
    | Biarkan kosong agar library mendeteksi otomatis (google-chrome,
    | chromium, chrome, dst). Set di .env bila perlu:
    |
    |   WA_CHROME_BINARY=/usr/bin/google-chrome-stable
    |
    */

    'binary' => env('WA_CHROME_BINARY', ''),

    /*
    |--------------------------------------------------------------------------
    | Browser Flags
    |--------------------------------------------------------------------------
    */

    'headless' => env('WA_HEADLESS', true),
    'no_sandbox' => env('WA_NO_SANDBOX', true),
    'window_size' => [1920, 1040],
    'custom_flags' => [
        '--disable-dev-shm-usage',
        '--disable-gpu',
    ],

    /*
    |--------------------------------------------------------------------------
    | Session Storage
    |--------------------------------------------------------------------------
    |
    | Direktori user-data-dir Chromium agar cookie & local storage sesi
    | WhatsApp Web tetap tersimpan antar eksekusi.
    |
    */

    'session_dir' => storage_path('app/chrome_wa_session'),

    /*
    |--------------------------------------------------------------------------
    | QR Login
    |--------------------------------------------------------------------------
    */

    'qr_screenshot' => storage_path('app/wa_qr.png'),
    'login_poll_interval' => env('WA_LOGIN_POLL_INTERVAL', 3),
    'login_poll_retries' => env('WA_LOGIN_POLL_RETRIES', 60),

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Selectors
    |--------------------------------------------------------------------------
    */

    'selectors' => [
        'qrcode' => '[data-testid="link-device-qr-code"]',
        'app_ready' => 'div[data-testid="wa-web-main-screen"], div[data-testid="chat-list"], div[data-testid="conversation-panel-wrapper"]',
        'send_button' => 'button[data-tab="11"]',
        'sent_check' => 'div[data-testid="conversation-panel-wrapper"]',
    ],

    /*
    |--------------------------------------------------------------------------
    | Timeouts (detik)
    |--------------------------------------------------------------------------
    */

    'timeout' => [
        'page_load' => env('WA_TIMEOUT_PAGE_LOAD', 45),
        'send_button' => env('WA_TIMEOUT_SEND_BUTTON', 45),
        'sent_check' => env('WA_TIMEOUT_SENT_CHECK', 15),
        'status_ready' => env('WA_TIMEOUT_STATUS_READY', 8),
        'status_qr' => env('WA_TIMEOUT_STATUS_QR', 20),
    ],

];

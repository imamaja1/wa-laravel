<?php

namespace App\Services;

use HeadlessChromium\Exception\OperationTimedOut;
use Illuminate\Support\Facades\Log;

class WaSession
{
    public const CACHE_KEY = 'wa.status';

    public const CHECK_LOCK_KEY = 'wa.status.checking';

    public function __construct(
        private readonly WaBrowser $browserManager,
    ) {
    }

    /**
     * Cek status login WhatsApp Web dan ambil QR code bila belum login.
     *
     * Browser PERSISTEN (keepAlive): jangan pernah ditutup di sini, karena QR
     * code hanya valid selama halaman yang menampilkannya masih hidup.
     *
     * @return array{logged_in: bool, qr_base64: ?string, checked_at: string, error: ?string}
     */
    public function status(): array
    {
        try {
            $browser = $this->browserManager->browser();
            $page = $this->browserManager->waPage($browser);

            // Tunggu salah satu kondisi muncul: halaman utama (sudah login)
            // atau QR code (belum login). Yang pertama muncul menentukan status.
            try {
                $this->browserManager->waitUntilAny($page, [
                    config('wa.selectors.app_ready'),
                    config('wa.selectors.qrcode'),
                ], config('wa.timeout.status_qr', 20) * 1000);
            } catch (OperationTimedOut) {
                // Belum jelas; fall back ke pemeriksaan langsung.
            }

            // Sudah login → sidebar utama tampil.
            if ($this->browserManager->isLoggedIn($page)) {
                return $this->result(loggedIn: true);
            }

            // Belum login → QR dari halaman live (selalu fresh, terikat sesi aktif).
            $qr = $this->captureQr($page);

            return $this->result(loggedIn: false, qrBase64: $qr);
        } catch (\Throwable $e) {
            Log::channel(WaEngine::LOG_CHANNEL)->error('status_check_failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->result(loggedIn: false, qrBase64: null, error: $e->getMessage());
        }
        // NOTE: tanpa finally close — browser harus tetap hidup agar QR bisa discan.
    }

    /**
     * @return array{logged_in: bool, qr_base64: ?string, checked_at: string, error: ?string}
     */
    private function result(bool $loggedIn, ?string $qrBase64 = null, ?string $error = null): array
    {
        return [
            'logged_in' => $loggedIn,
            'qr_base64' => $qrBase64,
            'checked_at' => now()->toIso8601String(),
            'error' => $error,
        ];
    }

    private function captureQr(\HeadlessChromium\Page $page): ?string
    {
        try {
            $canvas = $page->evaluate(<<<'JS'
                (() => {
                    const canvas = document.querySelector('[data-testid="link-device-qr-code"] canvas');
                    return canvas ? canvas.toDataURL('image/png') : null;
                })()
            JS)->getReturnValue();

            if (is_string($canvas) && $canvas !== '') {
                return $canvas;
            }
        } catch (\Throwable) {
            // Elemen QR belum siap; tunggu sebentar lalu coba lagi.
        }

        $page->waitUntilContainsElement(
            config('wa.selectors.qrcode'),
            config('wa.timeout.status_qr', 20) * 1000,
        );

        $canvas = $page->evaluate(<<<'JS'
            (() => {
                const canvas = document.querySelector('[data-testid="link-device-qr-code"] canvas');
                return canvas ? canvas.toDataURL('image/png') : null;
            })()
        JS)->getReturnValue();

        return is_string($canvas) && $canvas !== '' ? $canvas : null;
    }
}

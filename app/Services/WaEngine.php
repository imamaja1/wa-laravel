<?php

namespace App\Services;

use HeadlessChromium\Browser;
use HeadlessChromium\Exception\OperationTimedOut;
use HeadlessChromium\Page;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class WaEngine
{
    public const LOG_CHANNEL = 'wa-benchmark';

    private float $t0;

    public function __construct(
        private readonly string $phone,
        private readonly string $message,
        private readonly WaBrowser $browserManager,
    ) {
        $this->t0 = microtime(true);
    }

    /**
     * Kirim pesan WhatsApp Web dan catat metrik benchmark.
     *
     * Login dikonfirmasi lewat halaman utama (persisten), lalu pesan dikirim
     * lewat TAB KERJA terpisah. Navigasi /send pada halaman utama memicu full
     * reload (~46s) dan membuat Chrome hang; pada tab kerja WhatsApp Web
     * memindahkan sesi aktif dan pengiriman selesai dalam hitungan detik.
     *
     * Setelah tab kerja ditutup, halaman utama berubah menjadi layar "sesi
     * dipakai tab lain" — WaBrowser::waPage() mendeteksinya (isUsable) dan
     * membuat halaman baru secara otomatis.
     *
     * @throws \Throwable
     */
    public function send(): void
    {
        $this->log('job_started', ['phone' => $this->phone]);

        // Satu browser + satu tab kerja: pengiriman HARUS serial. Dua job
        // paralel berebut workPage yang sama dan saling membatalkan navigasi
        // (gejala: composer tidak pernah muncul).
        $lock = Cache::lock('wa_send_lock', 120);

        try {
            $lock->block(110);
        } catch (LockTimeoutException) {
            throw new RuntimeException('Pengiriman lain masih berlangsung. Coba lagi nanti.');
        }

        $page = null;
        $browser = null;

        try {
            $browser = $this->browserManager->browser();
            $this->log('browser_connected');

            // Cek login via halaman utama persisten.
            $waPage = $this->browserManager->waPage($browser);

            try {
                $this->browserManager->waitUntilAny($waPage, [
                    config('wa.selectors.app_ready'),
                    config('wa.selectors.qrcode'),
                ], config('wa.timeout.status_ready', 8) * 1000);
            } catch (OperationTimedOut) {
                // Lanjut; isLoggedIn akan memastikan.
            }

            if (! $this->browserManager->isLoggedIn($waPage)) {
                throw new RuntimeException('Sesi WhatsApp belum login. Scan QR di halaman /wa terlebih dahulu.');
            }

            $this->log('logged_in_confirmed');

            // Tab kerja: sesi aktif pindah ke sini, kirim lebih cepat.
            $page = $this->browserManager->workPage($browser, $this->phone);

            // Navigasi TANPA ?text=: WA Web butuh ~5 detik me-render teks dari
            // parameter URL (bottleneck). Lebih cepat: buka chat dulu, lalu
            // ketik manual langsung ke composer via execCommand.
            //
            // Tab kerja yang di-reuse SUDAH berada di chat target (URL /send?phone
            // atau /c/), jadi navigasi ulang TIDAK perlu — navigasi ke /send dari
            // tab yang sudah punya sesi justru memicu full reload ~46 detik.
            $needNavigate = true;

            try {
                $currentUrl = (string) $page->getCurrentUrl();

                $needNavigate = ! ($this->browserManager->isSendUrl($currentUrl, $this->phone)
                    || $this->browserManager->isChatUrl($currentUrl, $this->phone));
            } catch (\Throwable) {
                // URL tidak bisa dibaca; navigate seperti biasa.
            }

            if ($needNavigate) {
                $url = 'https://web.whatsapp.com/send?phone='.rawurlencode($this->phone);

                $navigation = $page->navigate($url);

                try {
                    $navigation->waitForNavigation(Page::DOM_CONTENT_LOADED, config('wa.timeout.page_load', 45) * 1000);
                } catch (OperationTimedOut) {
                    // Halaman mungkin sudah load; lanjut tunggu elemen.
                }

                // Tandai halaman sebagai tab kerja di ORIGIN whatsapp (sessionStorage
                // per-origin: marker yang diset di chrome://newtab hilang saat
                // navigate). Marker ini membuat job berikutnya REUSE tab ini
                // alih-alih membuat tab baru (~5 detik lebih cepat).
                try {
                    $page->evaluate('sessionStorage.setItem(\'wa_role\', \'work\')');
                } catch (\Throwable) {
                    // Abaikan; reuse hanya bonus kecepatan.
                }
            }

            $this->log('page_loaded');

            // Tunggu composer siap (elemen ADA — chat /send sudah dibuka).
            $this->waitComposerVisible($page);
            $this->log('composer_visible');

            // Ketik pesan manual; jauh lebih cepat daripada menunggu
            // render ?text= dari URL.
            $this->typeMessage($page);
            $this->log('typed');

            // Verifikasi teks benar-benar masuk ke composer (klik terlalu
            // cepat = pesan tidak pernah dikirim).
            $this->waitComposerFilled($page);
            $this->log('composer_ready');

            $page->evaluate(<<<'JS'
                const button = document.querySelector('button[data-tab="11"]');
                if (button) button.click();
            JS);
            $this->log('send_clicked');

            // Tunggu konfirmasi terkirim: pesan harus MUNCUL di panel chat.
            // Composer kosong saja TIDAK cukup — WhatsApp mengosongkan composer
            // lokal sebelum pengiriman selesai; kalau tab ditutup terlalu cepat,
            // pesan jatuh jadi draf dan tidak pernah terkirim.
            $this->waitForSent($page);

            // Beri waktu sinkronisasi ke server sebelum tab kerja ditutup.
            usleep(1_000_000);
        } catch (\Throwable $e) {
            // Tab kerja yang DIPAKAI job ini rusak (navigasi gagal dll.) —
            // tutup agar tidak menumpuk; job berikutnya buat yang baru.
            if ($page instanceof Page) {
                try {
                    $page->close();
                } catch (\Throwable) {
                    // Halaman mungkin sudah mati.
                }
            }

            if ($e instanceof RuntimeException) {
                throw $e;
            }

            $this->log('job_failed', ['error' => $e->getMessage()]);

            throw $e;
        } finally {
            // Tab kerja TIDAK ditutup: tab yang sudah memuat WhatsApp Web
            // di-reuse oleh job berikutnya (workPage() mencari marker
            // sessionStorage) — ini memangkas ~5 detik load per kirim.
            // Tab yang benar-benar rusak akan otomatis dibuat ulang.
            //
            // Sesi aktif dikembalikan ke halaman utama (bringToFront) agar
            // status check /wa/status tetap cepat — WhatsApp hanya memberi
            // sesi aktif ke satu tab.
            if ($browser instanceof Browser) {
                try {
                    $this->browserManager->focusMain($browser);
                } catch (\Throwable) {
                    // Gagal fokus = status check butuh recovery; tidak fatal.
                }
            }

            $this->log('job_finished', [
                'total_seconds' => round(microtime(true) - $this->t0, 4),
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            ]);

            $lock->release();
        }
    }

    /**
     * Tunggu hingga elemen composer muncul di chat /send yang baru dibuka.
     *
     * @throws OperationTimedOut
     */
    private function waitComposerVisible(Page $page): void
    {
        $deadline = microtime(true) + (int) config('wa.timeout.send_button', 45);

        while (microtime(true) < $deadline) {
            try {
                $exists = $page->evaluate(
                    '!!document.querySelector(\'footer [contenteditable="true"]\')'
                )->getReturnValue();

                if ($exists === true) {
                    return;
                }
            } catch (\Throwable) {
                // Halaman masih sibuk; polling lanjut.
            }

            usleep(250_000);
        }

        throw new OperationTimedOut('Composer WhatsApp tidak muncul dalam '.config('wa.timeout.send_button', 45).'s.');
    }

    /**
     * Ketik pesan langsung ke composer via execCommand (insertText).
     *
     * execCommand menembakkan event input yang dimengerti React — sama
     * seperti pengetikan manual pengguna, jadi tombol kirim ikut aktif.
     */
    private function typeMessage(Page $page): void
    {
        $json = json_encode($this->message, JSON_UNESCAPED_UNICODE);

        $page->evaluate(<<<JS
            (() => {
                const editor = document.querySelector('footer [contenteditable="true"]');
                if (!editor) return 'no-editor';
                editor.focus();
                document.execCommand('insertText', false, {$json});
                return 'typed';
            })()
        JS);
    }

    /**
     * Tunggu hingga composer terisi teks pesan DAN tombol kirim aktif.
     *
     * @throws OperationTimedOut
     */
    private function waitComposerFilled(Page $page): void
    {
        $needle = mb_substr($this->message, 0, 30);
        $deadline = microtime(true) + (int) config('wa.timeout.send_button', 45);

        while (microtime(true) < $deadline) {
            try {
                $state = $page->evaluate(<<<'JS'
                    (() => {
                        const editor = document.querySelector('footer [contenteditable="true"]');
                        const text = editor ? editor.innerText.trim() : '';
                        const btn = document.querySelector('button[data-tab="11"]');
                        const btnIcon = btn ? btn.querySelector('[data-icon]')?.getAttribute('data-icon') : null;
                        const ariaDisabled = btn ? btn.getAttribute('aria-disabled') : null;
                        return JSON.stringify({text, btnIcon, ariaDisabled});
                    })()
                JS)->getReturnValue();

                $d = is_string($state) ? json_decode($state, true) : null;

                if (is_array($d) && is_string($d['text']) && $d['text'] !== '') {
                    // Composer terisi teks kita; pastikan tombol bukan mic.
                    if ($d['ariaDisabled'] !== 'true' && $d['btnIcon'] !== 'mic' && str_contains($d['text'], $needle)) {
                        return;
                    }
                }
            } catch (\Throwable) {
                // Halaman masih sibuk; polling lanjut.
            }

            usleep(250_000);
        }

        throw new OperationTimedOut('Composer tidak terisi teks pesan dalam '.config('wa.timeout.send_button', 45).'s.');
    }

    /**
     * Tunggu hingga pesan BENAR-BENAR muncul di panel chat (terkirim).
     *
     * Composer kosong saja tidak cukup: WhatsApp Web mengosongkan composer
     * seketika setelah klik, padahal pengiriman masih berjalan asinkron.
     * Konfirmasi sejati = pesan dengan teks kita muncul di panel chat.
     *
     * @throws OperationTimedOut
     */
    private function waitForSent(Page $page): void
    {
        $needle = mb_substr($this->message, 0, 30);
        $deadline = microtime(true) + (int) config('wa.timeout.sent_check', 15);

        while (microtime(true) < $deadline) {
            try {
                $state = $page->evaluate(<<<'JS'
                    (() => {
                        const rows = document.querySelectorAll('[data-testid="conversation-panel-body"] [data-pre-plain-text]');
                        const texts = [];
                        for (let i = Math.max(0, rows.length - 5); i < rows.length; i++) {
                            texts.push(rows[i].innerText);
                        }
                        return JSON.stringify({texts});
                    })()
                JS)->getReturnValue();

                $d = is_string($state) ? json_decode($state, true) : null;

                if (is_array($d) && is_array($d['texts'])) {
                    foreach ($d['texts'] as $text) {
                        if (is_string($text) && str_contains($text, $needle)) {
                            $this->log('sent_confirmed', ['preview' => mb_substr($text, 0, 60)]);

                            return;
                        }
                    }
                }
            } catch (\Throwable) {
                // Halaman sibuk; polling lanjut.
            }

            usleep(500_000);
        }

        $this->log('sent_confirmation_timeout');

        throw new OperationTimedOut('Pesan tidak terkonfirmasi terkirim dalam '.config('wa.timeout.sent_check', 15).'s.');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(string $event, array $context = []): void
    {
        $context = array_merge([
            'elapsed_seconds' => round(microtime(true) - $this->t0, 4),
            'memory_current_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        ], $context);

        Log::channel(self::LOG_CHANNEL)->info($event, $context);
    }
}

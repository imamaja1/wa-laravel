<?php

namespace App\Services;

use HeadlessChromium\Browser;
use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Communication\Message;
use HeadlessChromium\Exception\OperationTimedOut;
use HeadlessChromium\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Kelola satu instance browser persisten (keepAlive) untuk sesi WhatsApp Web.
 *
 * QR code WhatsApp Web hanya valid selama halaman yang menampilkannya masih
 * hidup, jadi browser TIDAK BOLEH ditutup setelah QR di-capture. Setelah
 * request PHP selesai, Chrome tetap berjalan (keepAlive) dan request
 * berikutnya menyambung kembali lewat socket URI yang disimpan.
 */
class WaBrowser
{
    public const SOCKET_KEY = 'wa.browser.socket';

    public const WA_URL = 'https://web.whatsapp.com';

    private const SOCKET_FILE = 'app/wa.socket';

    /**
     * Ambil browser persisten: reconnect bila sudah ada, spawn bila mati.
     *
     * @throws \Throwable
     */
    public function browser(): Browser
    {
        $uri = $this->readSocketUri();

        if ($uri !== null && $uri !== '') {
            try {
                return BrowserFactory::connectToBrowser($uri);
            } catch (\Throwable $e) {
                Log::channel(WaEngine::LOG_CHANNEL)->warning('browser_reconnect_failed', [
                    'error' => $e->getMessage(),
                ]);
                $this->forgetSocketUri();
            }
        }

        return $this->spawn();
    }

    /**
     * Ambil halaman WhatsApp Web yang sudah ada di browser, atau buat baru.
     *
     * Halaman yang tidak responsif (mis. ditinggal WhatsApp Web setelah tab
     * kerja ditutup) ditutup lalu diganti dengan halaman baru.
     *
     * @throws \Throwable
     */
    public function waPage(Browser $browser): Page
    {
        // Target CDP bisa saja belum ter-populate saat reconnect; coba sebentar.
        for ($i = 0; $i < 20; $i++) {
            foreach ($browser->getPages() as $page) {
                if ($this->isWaUrl($page)) {
                    if ($this->isUsable($page)) {
                        return $page;
                    }

                    // Halaman menampilkan layar tanpa login/QR (mis. "sesi
                    // dipakai tab lain", "memuat ulang"); tutup dan buat baru.
                    Log::channel(WaEngine::LOG_CHANNEL)->warning('wa_page_stuck_recovered', [
                        'url' => $this->safeUrl($page),
                    ]);

                    try {
                        $page->close();
                    } catch (\Throwable) {
                        // Tetap lanjut; halaman mungkin sudah mati.
                    }

                    break;
                }
            }

            usleep(100_000);
        }

        $page = $browser->createPage();
        $navigation = $page->navigate(self::WA_URL);

        try {
            $navigation->waitForNavigation(Page::DOM_CONTENT_LOADED, config('wa.timeout.page_load', 45) * 1000);
        } catch (OperationTimedOut) {
            // Halaman mungkin sudah load; lanjut tunggu elemen di pemanggil.
        }

        return $page;
    }

    /**
     * Halaman kerja persisten untuk operasi kirim pesan.
     *
     * Memuat ulang WhatsApp Web dari nol di tab baru memakan ~5 detik.
     * Agar pengiriman beruntun lebih cepat, satu tab kerja di-REUSE: tab
     * ditandai lewat sessionStorage, dan job berikutnya memakainya lagi
     * tanpa membuat tab baru.
     *
     * Catatan: tab kedua aman setelah sesi LOGIN. Saat belum login tab kedua
     * akan mencuri sesi QR — oleh karena itu kirim pesan dilarang sebelum login.
     *
     * @throws \Throwable
     */
    /**
     * Halaman kerja untuk mengirim ke $phone tertentu.
     *
     * Navigasi /send di tab yang sudah punya sesi = full reload lambat
     * (~46 detik); dari tab BARU = cepat (~2 detik). Karena itu tab kerja
     * hanya di-reuse bila URL-nya SUDAH menuju chat target (format /send?phone
     * atau /c/). Tab kerja untuk chat lain ditutup; tab baru dibuat agar
     * navigate /send tetap cepat.
     *
     * @throws \Throwable
     */
    public function workPage(Browser $browser, string $phone): Page
    {
        $stale = [];

        foreach ($browser->getPages() as $page) {
            if (! $this->isWorkPage($page)) {
                continue;
            }

            $url = $this->safeUrl($page);

            if (is_string($url) && ($this->isSendUrl($url, $phone) || $this->isChatUrl($url, $phone))) {
                return $page;
            }

            $stale[] = $page;
        }

        foreach ($stale as $page) {
            try {
                $page->close();
            } catch (\Throwable) {
                // Halaman mungkin sudah mati.
            }
        }

        // Marker sessionStorage diset oleh WaEngine setelah halaman mencapai
        // origin whatsapp (sessionStorage per-origin).
        return $browser->createPage();
    }

    public function isSendUrl(string $url, string $phone): bool
    {
        return str_contains($url, '/send?phone='.$phone);
    }

    public function isChatUrl(string $url, string $phone): bool
    {
        return str_contains($url, '/c/'.$phone);
    }

    /**
     * Pindahkan sesi aktif WhatsApp kembali ke halaman utama.
     *
     * WhatsApp Web memberi sesi aktif hanya ke SATU tab. Saat tab kerja
     * hidup, halaman utama menampilkan layar "sesi dipakai tab lain" dan
     * status check jadi lambat. Mem-fokus-kan halaman utama (Page.bringToFront,
     * setara dengan klik pada tab di browser normal) membuat WhatsApp
     * mengalihkan sesi kembali — tanpa reload.
     */
    public function focusMain(Browser $browser): bool
    {
        foreach ($browser->getPages() as $page) {
            if ($this->isWorkPage($page)) {
                continue;
            }

            if (! $this->isWaUrl($page)) {
                continue;
            }

            try {
                $page->getSession()->sendMessageSync(new Message('Page.bringToFront'), 5_000);

                return true;
            } catch (\Throwable) {
                // Halaman mungkin sedang sibuk; abaikan.
            }
        }

        return false;
    }

    /**
     * Cek apakah halaman adalah tab kerja (ditandai sessionStorage).
     */
    private function isWorkPage(Page $page): bool
    {
        try {
            $role = $page->evaluate('sessionStorage.getItem(\'wa_role\')')->getReturnValue();

            return $role === 'work';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Cek apakah halaman sudah login (sidebar utama tampil).
     */
    public function isLoggedIn(Page $page): bool
    {
        try {
            $result = $page->evaluate(
                '!!document.querySelector('.json_encode(config('wa.selectors.app_ready')).')'
            );

            return $result->getReturnValue() === true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Tunggu hingga salah satu selector muncul.
     *
     * Toleran terhadap timeout evaluate per-iterasi: halaman yang sedang
     * sibuk (navigasi SPA, re-render) akan mengabaikan panggilan CDP, tapi
     * polling tetap berlanjut sampai deadline.
     *
     * @param  string[]  $selectors
     *
     * @throws \HeadlessChromium\Exception\OperationTimedOut
     */
    public function waitUntilAny(Page $page, array $selectors, int $timeoutMs): void
    {
        $deadline = microtime(true) + ($timeoutMs / 1000);

        while (microtime(true) < $deadline) {
            foreach ($selectors as $selector) {
                try {
                    $found = $page->evaluate(
                        '!!document.querySelector('.json_encode($selector).')'
                    )->getReturnValue();

                    if ($found === true) {
                        return;
                    }
                } catch (\Throwable) {
                    // Halaman sibuk; polling tetap lanjut.
                }
            }

            usleep(250_000);
        }

        throw new OperationTimedOut('None of the selectors appeared within '.$timeoutMs.'ms.');
    }

    /**
     * Spawn browser baru dengan keepAlive dan simpan socket URI-nya.
     *
     * @throws \Throwable
     */
    private function spawn(): Browser
    {
        $options = [
            'headless' => (bool) config('wa.headless', true),
            'noSandbox' => (bool) config('wa.no_sandbox', true),
            'windowSize' => config('wa.window_size', [1920, 1040]),
            'userDataDir' => config('wa.session_dir'),
            'customFlags' => config('wa.custom_flags', []),
            'startupTimeout' => 30,
            'keepAlive' => true,
            // PHP built-in server: env implisit rusak (0x277A Winsock);
            // kirim env eksplisit agar Chrome bisa buka socket devtools.
            'envVariables' => getenv(),
        ];

        $binary = (string) config('wa.binary');

        $factory = $binary === ''
            ? new BrowserFactory()
            : new BrowserFactory($binary);

        try {
            $browser = $factory->createBrowser($options);
        } catch (\Throwable $e) {
            // Mungkin masih ada Chrome zombie yang mengunci userDataDir;
            // matikan proses lama yang memakai session dir lalu spawn ulang.
            if ($this->killStaleBrowsers() > 0) {
                usleep(500_000);
                $browser = $factory->createBrowser($options);
            } else {
                throw $e;
            }
        }

        $uri = $browser->getSocketUri();

        Cache::forever(self::SOCKET_KEY, $uri);
        $this->writeSocketFile($uri);

        return $browser;
    }

    private function isWaUrl(Page $page): bool
    {
        try {
            return str_contains((string) $page->getCurrentUrl(), 'web.whatsapp.com');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Halaman berguna = menampilkan layar login ATAU QR (bukan layar "sesi
     * dipakai tab lain" / blank). Tunggu singkat untuk memberi kesempatan
     * halaman memuat kembali sesi.
     */
    private function isUsable(Page $page): bool
    {
        $deadline = microtime(true) + 6;

        do {
            try {
                $state = $page->evaluate(
                    'JSON.stringify({ready: !!document.querySelector('.json_encode(config('wa.selectors.app_ready')).'), qr: !!document.querySelector('.json_encode(config('wa.selectors.qrcode')).')})'
                )->getReturnValue();
            } catch (\Throwable) {
                return false;
            }

            if (is_string($state)) {
                $decoded = json_decode($state, true);

                if (is_array($decoded) && ($decoded['ready'] === true || $decoded['qr'] === true)) {
                    return true;
                }
            }

            usleep(200_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    private function safeUrl(Page $page): ?string
    {
        try {
            return (string) $page->getCurrentUrl();
        } catch (\Throwable) {
            return null;
        }
    }

    private function readSocketUri(): ?string
    {
        $cached = Cache::get(self::SOCKET_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $file = storage_path(self::SOCKET_FILE);

        if (is_file($file)) {
            $content = @file_get_contents($file);

            return is_string($content) && $content !== '' ? trim($content) : null;
        }

        return null;
    }

    private function forgetSocketUri(): void
    {
        Cache::forget(self::SOCKET_KEY);
        @unlink(storage_path(self::SOCKET_FILE));
    }

    private function writeSocketFile(string $uri): void
    {
        @file_put_contents(storage_path(self::SOCKET_FILE), $uri);
    }

    /**
     * Matikan proses Chrome yang masih memakai session dir (Windows).
     *
     * @return int jumlah proses yang dimatikan
     */
    private function killStaleBrowsers(): int
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            return 0;
        }

        try {
            $process = new Process([
                'powershell',
                '-NoProfile',
                '-Command',
                "Get-CimInstance Win32_Process -Filter 'Name = \"chrome.exe\"' | Where-Object { \$_.CommandLine -match 'chrome_wa_session' } | ForEach-Object { Stop-Process -Id \$_.ProcessId -Force; \$_.ProcessId }",
            ]);

            $process->setTimeout(15);
            $process->run();

            $output = trim($process->getOutput());

            return $output === '' ? 0 : substr_count($output, PHP_EOL) + 1;
        } catch (\Throwable) {
            return 0;
        }
    }
}

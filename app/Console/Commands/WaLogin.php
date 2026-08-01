<?php

namespace App\Console\Commands;

use App\Services\WaBrowser;
use HeadlessChromium\Browser;
use HeadlessChromium\Page;
use Illuminate\Console\Command;

class WaLogin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wa:login
                            {--scan : Cetak hasil URL/storage QR, tidak menunggu login}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Siapkan sesi WhatsApp Web: tampilkan QR code untuk login pertama kali';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $browser = (new WaBrowser)->browser();

            $page = (new WaBrowser)->waPage($browser);

            // Jika sidebar langsung tampil, sesi sudah tersimpan.
            if ($this->isLoggedIn($page)) {
                $this->info('Sesi WhatsApp Web sudah tersimpan, tidak perlu login ulang.');

                return self::SUCCESS;
            }

            $this->info('QR code WhatsApp Web siap.');
            $this->info('Menunggu login...');

            if ($this->option('scan')) {
                $path = config('wa.qr_screenshot');
                $page->screenshot()->saveToFile($path);

                $this->info('Screenshot QR tersimpan di: '.$path);
                $this->info('Buka file tersebut lalu scan menggunakan WhatsApp di HP Anda.');

                return self::SUCCESS;
            }

            $interval = (int) config('wa.login_poll_interval', 3);
            $retries = (int) config('wa.login_poll_retries', 60);

            for ($i = 1; $i <= $retries; $i++) {
                if ($this->isLoggedIn($page)) {
                    $this->info('Login berhasil! Sesi tersimpan di '.config('wa.session_dir'));

                    return self::SUCCESS;
                }

                sleep($interval);
            }

            $this->error('Waktu tunggu login habis. Jalankan ulang "php artisan wa:login".');

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('Gagal menyiapkan login: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function isLoggedIn(Page $page): bool
    {
        return (new WaBrowser)->isLoggedIn($page);
    }
}

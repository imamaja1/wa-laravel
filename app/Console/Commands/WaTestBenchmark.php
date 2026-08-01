<?php

namespace App\Console\Commands;

use App\Jobs\SendWhatsAppJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WaTestBenchmark extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wa:test
                            {case : Test case (a|b|c)}
                            {phone : Nomor tujuan, format internasional tanpa + (misal 6281234567890)}
                            {message : Isi pesan}
                            {--count= : Jumlah job (default: a=1, b=10, c=20)}
                            {--timeout=600 : Batas waktu tunggu antrian selesai (detik)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Jalankan benchmark WhatsApp (Test Case A/B/C) via Laravel Queue';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $case = strtolower((string) $this->argument('case'));

        $counts = ['a' => 1, 'b' => 10, 'c' => 20];
        $count = (int) ($this->option('count') ?? $counts[$case] ?? 1);
        $timeout = (int) $this->option('timeout');

        if (! array_key_exists($case, $counts)) {
            $this->error('Test case harus a, b, atau c.');

            return self::INVALID;
        }

        $phone = $this->argument('phone');
        $message = $this->argument('message');

        $this->info("Test Case [$case] — $count job, 1 pesan/job");
        $this->line("Tujuan: $phone");

        $start = microtime(true);

        for ($i = 0; $i < $count; $i++) {
            SendWhatsAppJob::dispatch((string) Str::uuid(), $phone, $message);
        }

        $this->line("Dispatched $count job dalam ".round(microtime(true) - $start, 4).'s');
        $this->line('Menunggu worker menyelesaikan antrian...');

        if (! $this->waitForQueueToDrain($timeout)) {
            $this->error('Timeout menunggu antrian kosong.');

            return self::FAILURE;
        }

        $elapsed = round(microtime(true) - $start, 4);
        $avg = round($elapsed / $count, 4);

        $this->newLine();
        $this->info("Selesai: total {$elapsed}s, rata-rata {$avg}s/pesan");
        $this->line('Detail benchmark: storage/logs/wa-benchmark.log');
        $this->line('Gagal (jika ada): '.DB::table('failed_jobs')->count().' job di tabel failed_jobs.');

        return self::SUCCESS;
    }

    private function waitForQueueToDrain(int $timeout): bool
    {
        $start = time();
        $failedBefore = DB::table('failed_jobs')->count();

        while (true) {
            $pending = DB::table('jobs')->count();
            $failed = DB::table('failed_jobs')->count();

            if ($pending === 0 && $failed === $failedBefore) {
                return true;
            }

            if (time() - $start >= $timeout) {
                return false;
            }

            sleep(1);
        }
    }
}

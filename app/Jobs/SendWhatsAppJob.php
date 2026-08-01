<?php

namespace App\Jobs;

use App\Services\WaBrowser;
use App\Services\WaEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendWhatsAppJob implements ShouldQueue
{
    use Queueable;

    /**
     * Jumlah percobaan maksimal sebelum job ditandai gagal.
     */
    public int $tries = 2;

    /**
     * Batas waktu eksekusi job (detik).
     */
    public int $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $jobId,
        public string $phone,
        public string $message,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->setStatus('processing');

        try {
            (new WaEngine($this->phone, $this->message, new WaBrowser))->send();

            $this->setStatus('sent');
        } catch (Throwable $e) {
            Log::channel(WaEngine::LOG_CHANNEL)->error('job_failed', [
                'phone' => $this->phone,
                'error' => $e->getMessage(),
                'attempts' => $this->attempts(),
            ]);

            $this->setStatus('failed', $e->getMessage());

            throw $e;
        }
    }

    /**
     * Simpan status job agar bisa dipantau dari frontend.
     */
    private function setStatus(string $status, ?string $error = null): void
    {
        Cache::put('wa.job.'.$this->jobId, [
            'status' => $status,
            'error' => $error,
            'phone' => $this->phone,
            'checked_at' => now()->toIso8601String(),
        ], now()->addMinutes(10));
    }
}

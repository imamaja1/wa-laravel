<?php

namespace App\Http\Controllers;

use App\Jobs\SendWhatsAppJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SendWhatsAppController extends Controller
{
    /**
     * Dispatch job pengiriman pesan WhatsApp.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:/^[0-9]{6,15}$/'],
            'message' => ['required', 'string', 'max:4000'],
        ]);

        $start = microtime(true);

        $jobId = (string) Str::uuid();

        $job = new SendWhatsAppJob($jobId, $data['phone'], $data['message']);

        dispatch($job);

        Cache::put('wa.job.'.$jobId, [
            'status' => 'queued',
            'error' => null,
            'phone' => $data['phone'],
            'checked_at' => now()->toIso8601String(),
        ], now()->addMinutes(10));

        Log::channel('wa-benchmark')->info('http_dispatched', [
            'phone' => $data['phone'],
            'dispatch_seconds' => round(microtime(true) - $start, 4),
        ]);

        return response()->json([
            'status' => 'queued',
            'job_id' => $jobId,
            'phone' => $data['phone'],
            'message_length' => strlen($data['message']),
            'queue' => config('queue.default'),
        ], 202);
    }

    /**
     * Status eksekusi job pengiriman (untuk polling dari frontend).
     */
    public function show(string $jobId): JsonResponse
    {
        $status = Cache::get('wa.job.'.$jobId);

        if (! is_array($status)) {
            return response()->json([
                'status' => 'not_found',
                'error' => 'Job tidak ditemukan.',
                'checked_at' => now()->toIso8601String(),
            ], 404);
        }

        return response()->json($status);
    }
}

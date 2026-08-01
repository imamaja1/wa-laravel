<?php

namespace App\Http\Controllers;

use App\Services\WaBrowser;
use App\Services\WaSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class WaController extends Controller
{
    /**
     * Tampilkan halaman integrasi WhatsApp.
     */
    public function index(): Response
    {
        return Inertia::render('Wa', [
            'session_dir' => config('wa.session_dir'),
        ]);
    }

    /**
     * Status login & QR code WhatsApp Web.
     */
    public function status(Request $request): JsonResponse
    {
        $force = $request->boolean('refresh');

        $cached = $force ? null : Cache::get(WaSession::CACHE_KEY);

        if (is_array($cached)) {
            return response()->json($cached);
        }

        // Guard anti-konkuren: cegah spawn browser ganda pada userDataDir yang sama.
        // Durasi status check bisa sampai ~80 detik, jadi lock harus lebih panjang.
        if (! Cache::add(WaSession::CHECK_LOCK_KEY, true, 120)) {
            return response()->json([
                'logged_in' => false,
                'qr_base64' => null,
                'checked_at' => now()->toIso8601String(),
                'error' => 'Status sedang diperiksa oleh proses lain, coba lagi.',
            ], 423);
        }

        try {
            $status = (new WaSession(new WaBrowser))->status();

            // Jangan cache hasil gagal berlama-lama agar bisa segera dicoba ulang.
            $ttl = $status['error'] === null ? 30 : 5;

            Cache::put(WaSession::CACHE_KEY, $status, now()->addSeconds($ttl));
        } finally {
            Cache::forget(WaSession::CHECK_LOCK_KEY);
        }

        return response()->json($status);
    }
}

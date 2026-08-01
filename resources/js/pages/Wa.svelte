<script lang="ts">
    import { useHttp } from '@inertiajs/svelte';
    import { onMount } from 'svelte';
    import { Badge } from '@/components/ui/badge/index.js';
    import { Button } from '@/components/ui/button/index.js';
    import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card/index.js';
    import { Input } from '@/components/ui/input/index.js';
    import { Separator } from '@/components/ui/separator/index.js';
    import { Textarea } from '@/components/ui/textarea/index.js';
    import AppHead from '@/components/AppHead.svelte';
    import ToastComponent from '@/components/ui/toast/toast.svelte';
    import SendIcon from '@lucide/svelte/icons/send';
    import PhoneIcon from '@lucide/svelte/icons/phone';
    import QrCodeIcon from '@lucide/svelte/icons/qr-code';
    import RefreshCwIcon from '@lucide/svelte/icons/refresh-cw';
    import CheckCircle2Icon from '@lucide/svelte/icons/check-circle-2';
    import LoaderCircleIcon from '@lucide/svelte/icons/loader-circle';

    let { session_dir } = $props<{
        session_dir: string;
    }>();

    const http = useHttp<{ phone: string; message: string }, SendResponse>({
        phone: '',
        message: '',
    });

    type SendResponse = {
        status: string;
        job_id: string;
        phone: string;
        message_length: number;
        queue: string;
    };

    type JobStatusResponse = {
        status: string;
        error: string | null;
        phone: string;
        checked_at: string;
    };

    type StatusResponse = {
        logged_in: boolean;
        qr_base64: string | null;
        checked_at: string;
        error: string | null;
    };

    type Toast = {
        variant: 'success' | 'error' | 'info';
        title: string;
        description?: string | null;
    };

    let result: SendResponse | null = $state(null);
    let errorMessage: string | null = $state(null);

    let status: StatusResponse | null = $state(null);
    let statusError: string | null = $state(null);
    let refreshing = $state(false);

    let toast: Toast | null = $state(null);
    let toastTimer: ReturnType<typeof setTimeout> | null = $state(null);

    function showToast(variant: Toast['variant'], title: string, description: string | null = null) {
        if (toastTimer) clearTimeout(toastTimer);

        toast = { variant, title, description };

        toastTimer = setTimeout(() => {
            toast = null;
        }, 6000);
    }

    function closeToast() {
        if (toastTimer) clearTimeout(toastTimer);
        toast = null;
    }

    async function checkStatus(force = false) {
        if (refreshing) return;
        refreshing = true;

        try {
            const response = await fetch(`/wa/status${force ? '?refresh=1' : ''}`, {
                headers: { Accept: 'application/json' },
            });

            if (response.status === 423) {
                // Pemeriksaan lain sedang berjalan; tunggu saja.
                return;
            }

            if (!response.ok) {
                statusError = `Gagal memeriksa status (HTTP ${response.status}).`;
                return;
            }

            status = (await response.json()) as StatusResponse;
            statusError = null;
        } catch (e) {
            statusError = e instanceof Error ? e.message : 'Gagal memeriksa status.';
        } finally {
            refreshing = false;
        }
    }

    async function pollJobStatus(jobId: string, phone: string) {
        const deadline = Date.now() + 120_000;

        while (Date.now() < deadline) {
            await new Promise((resolve) => setTimeout(resolve, 3000));

            try {
                const response = await fetch(`/wa/jobs/${jobId}`, {
                    headers: { Accept: 'application/json' },
                });

                if (!response.ok) continue;

                const job = (await response.json()) as JobStatusResponse;

                if (job.status === 'sent') {
                    showToast('success', 'Pesan terkirim!', `Ke ${phone}`);
                    return;
                }

                if (job.status === 'failed') {
                    showToast(
                        'error',
                        'Pesan gagal dikirim',
                        job.error ?? 'Terjadi kesalahan saat mengirim.',
                    );
                    return;
                }
            } catch {
                // Coba lagi pada iterasi berikutnya.
            }
        }

        showToast('error', 'Waktu tunggu habis', 'Status pengiriman tidak terkonfirmasi.');
    }

    function submit(e: SubmitEvent) {
        e.preventDefault();
        result = null;
        errorMessage = null;

        http.post('/wa/send', {
            onSuccess: (response) => {
                result = response;

                showToast('info', 'Pesan masuk antrean', `Job ID: ${result.job_id}`);

                pollJobStatus(result.job_id, result.phone);
            },
            onError: (errors) => {
                errorMessage = Object.values(errors).join(' ');

                showToast('error', 'Gagal mengirim', errorMessage);
            },
        });
    }

    onMount(() => {
        checkStatus(true);

        const interval = setInterval(() => {
            // QR WhatsApp Web berganti setiap ~20 detik; refresh paksa saat
            // belum login agar QR yang tampil selalu yang terbaru dari halaman.
            checkStatus(!status?.logged_in);
        }, 20_000);

        return () => clearInterval(interval);
    });
</script><AppHead title="WhatsApp" />

<div class="flex min-h-screen flex-col items-center bg-background p-6 lg:justify-center lg:p-8">
    {#if toast}
        <ToastComponent
            variant={toast.variant}
            title={toast.title}
            description={toast.description}
            onClose={closeToast}
        />
    {/if}

    <main class="flex w-full max-w-xl flex-col gap-6">
        <div class="flex flex-col gap-1">
            <h1 class="text-2xl font-semibold tracking-tight">Integrasi WhatsApp</h1>
            <p class="text-sm text-muted-foreground">
                PoC WhatsApp Web via chrome-php — kirim pesan langsung dari antrean Laravel.
            </p>
        </div>

        <Separator />

        <Card>
            <CardHeader>
                <CardTitle class="flex items-center gap-2 text-base">
                    <QrCodeIcon data-icon="inline-start" class="text-muted-foreground" />
                    Status Sesi WhatsApp
                </CardTitle>
                <CardDescription>
                    Halaman memeriksa status login secara berkala (20 detik).
                </CardDescription>
            </CardHeader>
            <CardContent class="flex flex-col gap-4">
                <div class="flex items-center justify-between gap-4">
                    <span class="text-sm text-muted-foreground">Session dir</span>
                    <code class="rounded bg-muted px-2 py-0.5 text-xs">{session_dir}</code>
                </div>

                <Separator />

                {#if statusError}
                    <div class="flex flex-col gap-2">
                        <div class="flex items-center gap-2">
                            <Badge variant="destructive">Error</Badge>
                            <span class="text-xs text-muted-foreground">Gagal memeriksa status</span>
                        </div>
                        <p class="text-sm text-destructive">{statusError}</p>
                    </div>
                {:else if !status}
                    <div class="flex flex-col items-center justify-center gap-3 py-8">
                        <LoaderCircleIcon class="size-6 animate-spin text-muted-foreground" />
                        <p class="text-sm text-muted-foreground">Memeriksa sesi WhatsApp...</p>
                    </div>
                {:else if status.logged_in}
                    <div class="flex flex-col items-center justify-center gap-3 py-6">
                        <span class="flex items-center gap-2">
                            <CheckCircle2Icon class="size-6 text-emerald-500" />
                            <Badge>Sudah Login</Badge>
                        </span>
                        <p class="text-center text-sm text-muted-foreground">
                            Sesi WhatsApp Web aktif. Anda bisa langsung mengirim pesan.
                        </p>
                    </div>
                {:else if status.qr_base64}
                    <div class="flex flex-col items-center justify-center gap-3 py-2">
                        <img
                            src={status.qr_base64}
                            alt="QR Code WhatsApp Web"
                            class="size-56 rounded-lg border"
                        />
                        <p class="text-center text-sm text-muted-foreground">
                            Scan QR ini dengan WhatsApp di HP Anda (Settings → Linked Devices → Link a Device).
                        </p>
                    </div>
                {:else}
                    <div class="flex flex-col items-center gap-2 py-6 text-center">
                        <p class="text-sm text-muted-foreground">QR code belum tersedia.</p>
                        {#if status.error}
                            <p class="text-xs text-destructive">{status.error}</p>
                        {/if}
                    </div>
                {/if}

                <Button variant="outline" onclick={() => checkStatus(true)} disabled={refreshing}>
                    <RefreshCwIcon data-icon="inline-start" class={refreshing ? 'animate-spin' : ''} />
                    {refreshing ? 'Memeriksa...' : 'Periksa Ulang'}
                </Button>

                {#if status && status.checked_at}
                    <p class="text-center text-xs text-muted-foreground">
                        Terakhir diperiksa: {status.checked_at}
                    </p>
                {/if}
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle class="flex items-center gap-2 text-base">
                    <PhoneIcon data-icon="inline-start" class="text-muted-foreground" />
                    Kirim Pesan
                </CardTitle>
                <CardDescription>
                    Nomor dalam format internasional tanpa + (contoh: 6281234567890).
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form onsubmit={submit} class="flex flex-col gap-4">
                    <div class="flex flex-col gap-2">
                        <label for="phone" class="text-sm font-medium">Nomor WhatsApp</label>
                        <Input id="phone" type="tel" placeholder="6281234567890" bind:value={http.phone} required />
                    </div>

                    <div class="flex flex-col gap-2">
                        <label for="message" class="text-sm font-medium">Isi Pesan</label>
                        <Textarea
                            id="message"
                            rows={5}
                            placeholder="Halo, ini pesan dari Laravel..."
                            bind:value={http.message}
                            required
                        />
                    </div>

                    <Button type="submit" disabled={http.processing || !http.phone || !http.message} class="w-full">
                        {#if http.processing}
                            Mengirim...
                        {:else}
                            <SendIcon data-icon="inline-start" />
                            Kirim Pesan
                        {/if}
                    </Button>
                </form>

                {#if errorMessage}
                    <div class="mt-4 rounded-lg bg-destructive/10 p-3 text-sm text-destructive">{errorMessage}</div>
                {/if}

                {#if result}
                    <div class="mt-4 rounded-lg border bg-muted/50 p-3 text-sm">
                        <div class="mb-2 flex items-center gap-2">
                            <Badge variant="secondary">Queued</Badge>
                            <span class="text-xs text-muted-foreground">Job ID: {result.job_id}</span>
                        </div>
                        <p class="text-muted-foreground">
                            Pesan ke {result.phone} ({result.message_length} karakter) sudah masuk antrean
                            <span class="font-medium text-foreground">{result.queue}</span>.
                        </p>
                    </div>
                {/if}
            </CardContent>
        </Card>
    </main>
</div>

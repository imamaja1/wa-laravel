<script lang="ts">
    import CheckCircle2Icon from '@lucide/svelte/icons/check-circle-2';
    import XCircleIcon from '@lucide/svelte/icons/x-circle';
    import InfoIcon from '@lucide/svelte/icons/info';
    import XIcon from '@lucide/svelte/icons/x';
    import { fade } from 'svelte/transition';

    type ToastVariant = 'success' | 'error' | 'info';

    let {
        variant,
        title,
        description = null,
        onClose,
    }: {
        variant: ToastVariant;
        title: string;
        description?: string | null;
        onClose: () => void;
    } = $props();
</script>

<div
    transition:fade={{ duration: 150 }}
    class="pointer-events-auto fixed right-4 top-4 z-50 flex w-80 items-start gap-3 rounded-lg border bg-background p-4 shadow-lg"
>
    {#if variant === 'success'}
        <CheckCircle2Icon class="mt-0.5 size-5 shrink-0 text-emerald-500" />
    {:else if variant === 'error'}
        <XCircleIcon class="mt-0.5 size-5 shrink-0 text-destructive" />
    {:else}
        <InfoIcon class="mt-0.5 size-5 shrink-0 text-muted-foreground" />
    {/if}
    <div class="flex min-w-0 flex-col gap-1">
        <p class="text-sm font-semibold">{title}</p>
        {#if description}
            <p class="break-words text-xs text-muted-foreground">{description}</p>
        {/if}
    </div>
    <button
        type="button"
        onclick={onClose}
        class="ml-auto shrink-0 rounded p-1 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
        aria-label="Tutup notifikasi"
    >
        <XIcon class="size-4" />
    </button>
</div>

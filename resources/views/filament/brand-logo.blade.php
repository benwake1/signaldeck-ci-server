<div class="flex items-center gap-x-3">
    @if ($brand['logo_url'])
        <img
            src="{{ $brand['logo_url'] }}"
            alt="{{ $brand['name'] }}"
            style="height: var(--fi-logo-height, 2rem)"
            class="shrink-0"
        >
    @endif
    @if ($brand['name'])
        <span class="text-base font-bold tracking-tight text-gray-950 dark:text-white">
            {{ $brand['name'] }}
        </span>
    @endif
</div>

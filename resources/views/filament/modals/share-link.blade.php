<div x-data="{ copied: false }" class="p-2">
    <div class="flex items-center gap-2">
        <input
            type="text"
            readonly
            value="{{ $url }}"
            class="flex-1 rounded-lg border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-800 px-3 py-2 text-sm font-mono text-gray-700 dark:text-gray-300 focus:outline-none"
            x-ref="linkInput"
            @click="$el.select()"
        >
        <button
            type="button"
            @click="navigator.clipboard.writeText('{{ $url }}').then(() => { copied = true; setTimeout(() => copied = false, 2000) })"
            class="shrink-0 inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium bg-primary-600 text-white hover:bg-primary-500 transition"
        >
            <span x-show="!copied">Copy</span>
            <span x-show="copied">Copied!</span>
        </button>
    </div>
    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
        This link provides read-only access to the report. It does not expire.
    </p>
</div>

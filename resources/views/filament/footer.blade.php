<div class="px-6 py-4 text-xs text-center text-gray-400 dark:text-gray-500 border-t border-gray-100 dark:border-white/5">
    &copy; {{ date('Y') }} {{ ($brand['legal_name'] ?? null) ?: ($brand['name'] ?? null) ?: config('app.name') }}. All rights reserved.
    <span class="block mt-0.5">{{ config('brand.version') }}</span>
</div>

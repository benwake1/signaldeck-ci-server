<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex items-center gap-3">
            <x-filament::button type="submit">
                Save Mail Settings
            </x-filament::button>

            <x-filament::button
                type="button"
                color="gray"
                wire:click="sendTestEmail"
                wire:loading.attr="disabled"
                wire:target="sendTestEmail"
            >
                <span wire:loading.remove wire:target="sendTestEmail">Send Test Email</span>
                <span wire:loading wire:target="sendTestEmail">Sending…</span>
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>

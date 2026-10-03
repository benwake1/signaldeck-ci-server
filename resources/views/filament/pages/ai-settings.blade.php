<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex items-center gap-3">
            <x-filament::button type="submit">
                Save AI Settings
            </x-filament::button>

            <x-filament::button type="button" color="gray" wire:click="testConnection" wire:loading.attr="disabled" wire:target="testConnection">
                <span wire:loading.remove wire:target="testConnection">Test connection</span>
                <span wire:loading wire:target="testConnection">Testing...</span>
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>

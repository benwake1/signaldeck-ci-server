<x-filament-panels::page>

    {{-- Colour utilities not included in Filament's pre-compiled CSS --}}
    <style>
        .bg-amber-50{background-color:#fffbeb}.bg-blue-50{background-color:#eff6ff}.bg-blue-100{background-color:#dbeafe}
        .border-amber-200{border-color:#fde68a}.border-blue-200{border-color:#bfdbfe}.border-blue-300{border-color:#93c5fd}
        .text-amber-500{color:#f59e0b}.text-amber-700{color:#b45309}.text-amber-800{color:#92400e}
        .text-blue-500{color:#3b82f6}.text-blue-700{color:#1d4ed8}.text-blue-800{color:#1e40af}
        .hover\:bg-blue-100:hover{background-color:#dbeafe}
        .dark .dark\:bg-amber-950{background-color:#451a03}.dark .dark\:bg-blue-950{background-color:#172554}.dark .dark\:bg-blue-900{background-color:#1e3a8a}
        .dark .dark\:border-amber-800{border-color:#92400e}.dark .dark\:border-blue-800{border-color:#1e40af}.dark .dark\:border-blue-700{border-color:#1d4ed8}
        .dark .dark\:text-amber-200{color:#fde68a}.dark .dark\:text-amber-300{color:#fcd34d}
        .dark .dark\:text-blue-200{color:#bfdbfe}.dark .dark\:text-blue-300{color:#93c5fd}
        .dark .dark\:hover\:bg-blue-900:hover{background-color:#1e3a8a}
        .dark\:bg-blue-900\/30{background-color:rgb(30 58 138/.3)}
    </style>

    {{-- Step indicator --}}
    <div class="flex items-center justify-center gap-2 mb-8">
        @foreach(['Framework', 'Scenarios', 'Configuration', 'Download'] as $i => $label)
            @php $step = $i + 1; @endphp
            <button
                wire:click="goToStep({{ $step }})"
                class="flex items-center gap-2 group"
            >
                <span @class([
                    'flex items-center justify-center w-8 h-8 rounded-full text-sm font-bold transition-colors',
                    'bg-primary-600 text-white'                                                    => $currentStep === $step,
                    'bg-success-500 text-white'                                                    => $currentStep > $step,
                    'bg-gray-200 dark:bg-gray-700 text-gray-600 dark:text-gray-400 group-hover:bg-gray-300 dark:group-hover:bg-gray-600' => $currentStep < $step,
                ])>
                    @if($currentStep > $step)
                        <x-heroicon-s-check class="w-4 h-4" />
                    @else
                        {{ $step }}
                    @endif
                </span>
                <span @class([
                    'hidden sm:block text-sm font-medium transition-colors',
                    'text-primary-600 dark:text-primary-400' => $currentStep === $step,
                    'text-success-600 dark:text-success-400' => $currentStep > $step,
                    'text-gray-500 dark:text-gray-400'       => $currentStep < $step,
                ])>{{ $label }}</span>
            </button>

            @if($step < 4)
                <div @class([
                    'flex-1 h-px max-w-16',
                    'bg-success-500' => $currentStep > $step,
                    'bg-gray-200 dark:bg-gray-700' => $currentStep <= $step,
                ])></div>
            @endif
        @endforeach
    </div>

    {{-- Step content --}}
    @if($currentStep === 1)
        @include('filament.pages.test-generator.step-1-framework')
    @elseif($currentStep === 2)
        @include('filament.pages.test-generator.step-2-scenarios')
    @elseif($currentStep === 3)
        @include('filament.pages.test-generator.step-3-configuration')
    @elseif($currentStep === 4)
        @include('filament.pages.test-generator.step-4-download')
    @endif

</x-filament-panels::page>

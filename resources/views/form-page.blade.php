<x-filament-panels::page>
    {{-- Polls while Cloudflare is still being called, so the status changes without a reload. --}}
    <form wire:submit="save" @if ($this->waiting()) wire:poll.3s @endif>
        {{ $this->form }}
    </form>
</x-filament-panels::page>

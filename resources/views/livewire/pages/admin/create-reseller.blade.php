<div>
    <form wire:submit.prevent="save" class="space-y-6">
        <x-input label="Name" wire:model="name" required />
        <x-input label="Email" type="email" wire:model="email" required />
        <x-input label="Password" wire:model="password" readonly />
        <x-input label="Credits" type="number" wire:model="credits" min="0" />
        <x-checkbox label="Active" wire:model="is_active" />

        <x-button type="submit">Create Reseller</x-button>
    </form>
</div>
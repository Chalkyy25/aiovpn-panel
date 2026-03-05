<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Links
        </x-slot>

        <div class="space-y-2">
            <x-filament::link
                :href="route('filament.reseller.resources.vpn-users.index')"
                icon="heroicon-m-users"
            >
                VPN Users
            </x-filament::link>

            <x-filament::link
                :href="route('filament.reseller.resources.vpn-servers.index')"
                icon="heroicon-m-server"
            >
                Servers
            </x-filament::link>

            <x-filament::link
                :href="route('filament.reseller.pages.credits')"
                icon="heroicon-m-credit-card"
            >
                Credits
            </x-filament::link>

            <x-filament::link
                :href="route('filament.reseller.resources.packages.index')"
                icon="heroicon-m-tag"
            >
                Packages
            </x-filament::link>

            <x-filament::link
                :href="route('filament.reseller.pages.account')"
                icon="heroicon-m-user-circle"
            >
                Account
            </x-filament::link>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>

<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Links
        </x-slot>

        <div class="space-y-2">
            <div class="h-full flex flex-col justify-center space-y-2">
                <x-filament::button
                    tag="a"
                    :href="route('filament.reseller.resources.vpn-users.index')"
                    icon="heroicon-m-users"
                    color="gray"
                    outlined
                    class="w-full justify-start"
                >
                    VPN Users
                </x-filament::button>

                <x-filament::button
                    tag="a"
                    :href="route('filament.reseller.resources.vpn-servers.index')"
                    icon="heroicon-m-server"
                    color="gray"
                    outlined
                    class="w-full justify-start"
                >
                    Servers
                </x-filament::button>

                <x-filament::button
                    tag="a"
                    :href="route('filament.reseller.pages.credits')"
                    icon="heroicon-m-credit-card"
                    color="gray"
                    outlined
                    class="w-full justify-start"
                >
                    Credits
                </x-filament::button>

                <x-filament::button
                    tag="a"
                    :href="route('filament.reseller.resources.packages.index')"
                    icon="heroicon-m-tag"
                    color="gray"
                    outlined
                    class="w-full justify-start"
                >
                    Packages
                </x-filament::button>

                <x-filament::button
                    tag="a"
                    :href="route('filament.reseller.pages.account')"
                    icon="heroicon-m-user-circle"
                    color="gray"
                    outlined
                    class="w-full justify-start"
                >
                    Account
                </x-filament::button>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>

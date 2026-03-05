<x-filament-widgets::widget>
    <x-filament::section heading="Links">
        <div class="h-full flex flex-col justify-center space-y-2">
            @foreach($links as $link)
                <x-filament::button
                    tag="a"
                    :href="$link['url']"
                    :icon="data_get($link, 'icon')"
                    color="gray"
                    outlined
                    class="w-full justify-start"
                >
                    {{ $link['label'] }}
                </x-filament::button>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>

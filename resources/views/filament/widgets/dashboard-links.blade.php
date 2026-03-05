<x-filament-widgets::widget>
    <x-filament::section heading="Links">
        <div class="space-y-2">
            @foreach($links as $link)
                <x-filament::link :href="$link['url']">
                    {{ $link['label'] }}
                </x-filament::link>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>

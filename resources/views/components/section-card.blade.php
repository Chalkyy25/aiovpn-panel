@props(['title' => ''])
<div class="bg-white border rounded-xl shadow-sm">
    @if($title)
        <div class="px-4 py-3 border-b">
            <h2 class="font-semibold">{{ $title }}</h2>
        </div>
    @endif
    <div class="p-4">
        {{ $slot }}
    </div>
</div>
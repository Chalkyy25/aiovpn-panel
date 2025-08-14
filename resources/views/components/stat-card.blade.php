@props([
    'title' => '',
    'value' => '',
    'icon'  => 'o-chart-bar',  // any <x-icon> name you already use
    'hint'  => null,           // optional small text on the right (eg. "Last 5m")
])

<div {{ $attributes->merge([
    'class' =>
        'flex items-center gap-4 rounded-xl bg-white border shadow-sm px-4 py-3 ' .
        'hover:shadow-md transition'
]) }}>
    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-gray-100">
        <x-icon :name="$icon" class="w-5 h-5 text-gray-700" />
    </div>

    <div class="flex-1 min-w-0">
        <div class="text-2xl font-semibold leading-none truncate">{{ $value }}</div>
        <div class="text-sm text-gray-500 truncate">{{ $title }}</div>
    </div>

    @if($hint)
        <div class="text-xs text-gray-400 whitespace-nowrap">{{ $hint }}</div>
    @endif
</div>
@props(['dir' => 'asc'])

<svg {{ $attributes->merge(['class' => 'h-3.5 w-3.5 opacity-70']) }}
     xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
    @if ($dir === 'asc')
        {{-- caret up --}}
        <path d="M5 12l5-5 5 5H5z"/>
    @else
        {{-- caret down --}}
        <path d="M5 8l5 5 5-5H5z"/>
    @endif
</svg>
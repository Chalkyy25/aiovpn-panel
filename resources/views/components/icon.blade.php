@props(['name' => 'o-home', 'class' => 'w-5 h-5'])

@switch($name)
    {{-- Outline icons (Heroicons-style) --}}
    @case('o-home')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <path stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                  d="M3 10.5 12 3l9 7.5V20a1.5 1.5 0 0 1-1.5 1.5H4.5A1.5 1.5 0 0 1 3 20v-9.5z"/>
            <path stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                  d="M9 21v-6h6v6"/>
        </svg>
    @break

    @case('o-chart-bar')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <path stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                  d="M3 20.5h18M7 17V9M12 17V6M17 17v-4"/>
        </svg>
    @break

    @case('o-user-group')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <path stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                  d="M16 14a4 4 0 1 1 4 4H4a4 4 0 1 1 4-4m8-4a4 4 0 1 1-8 0"/>
        </svg>
    @break

    @case('o-plus')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <path stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14"/>
        </svg>
    @break

    @case('o-plus-circle')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <circle cx="12" cy="12" r="9" stroke-width="1.5"/>
            <path stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" d="M12 8v8M8 12h8"/>
        </svg>
    @break

    @case('o-clock')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <circle cx="12" cy="12" r="9" stroke-width="1.5"/>
            <path stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" d="M12 7v5l3 2"/>
        </svg>
    @break

    @case('o-list-bullet')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <path stroke-width="1.5" stroke-linecap="round" d="M9 7h11M9 12h11M9 17h11"/>
            <path stroke-width="1.5" stroke-linecap="round" d="M5 7h.01M5 12h.01M5 17h.01"/>
        </svg>
    @break

    @case('o-server')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <rect x="3.5" y="5" width="17" height="6" rx="1.5" stroke-width="1.5"/>
            <rect x="3.5" y="13" width="17" height="6" rx="1.5" stroke-width="1.5"/>
            <path stroke-width="1.5" stroke-linecap="round" d="M7 8h.01M10 8h.01M13 8h.01M7 16h.01M10 16h.01M13 16h.01"/>
        </svg>
    @break

    @case('o-cog-6-tooth')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <circle cx="12" cy="12" r="3" stroke-width="1.5"/>
            <path stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                  d="M19.4 15a7.97 7.97 0 0 0 .2-2 7.97 7.97 0 0 0-.2-2l2.1-1.6-2-3.4-2.5 1a8.05 8.05 0 0 0-3.4-2l-.3-2.7h-4l-.3 2.7a8.05 8.05 0 0 0-3.4 2l-2.5-1-2 3.4L4.6 11a7.97 7.97 0 0 0-.2 2c0 .68.07 1.34.2 2L2.5 16.6l2 3.4 2.5-1a8.05 8.05 0 0 0 3.4 2l.3 2.7h4l.3-2.7a8.05 8.05 0 0 0 3.4-2l2.5 1 2-3.4L19.4 15z"/>
        </svg>
    @break

    @case('o-banknotes')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <rect x="3" y="7" width="18" height="10" rx="2" stroke-width="1.5"/>
            <circle cx="12" cy="12" r="2.5" stroke-width="1.5"/>
            <path stroke-width="1.5" d="M5 9h0M19 15h0"/>
        </svg>
    @break

    @case('o-currency-dollar')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <path stroke-width="1.5" stroke-linecap="round" d="M12 3v18"/>
            <path stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                  d="M16 7.5a3.5 3.5 0 0 0-3.5-3.5H10a3 3 0 0 0 0 6h4a3 3 0 0 1 0 6h-2.5A3.5 3.5 0 0 1 8 12.5"/>
        </svg>
    @break

    @case('o-bars-3')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <path stroke-width="1.5" stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/>
        </svg>
    @break

    @case('o-x-mark')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <path stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
        </svg>
    @break

    {{-- NEW: activity (pulse) --}}
    @case('o-activity')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <path stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                  d="M3 12h4l3 7 4-14 3 7h4"/>
        </svg>
    @break

    {{-- NEW: filter (funnel) --}}
    @case('o-filter')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <path stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                  d="M3 5h18M7 12h10M10 19h4"/>
        </svg>
    @break

    {{-- NEW: check-circle (online) + alias o-online --}}
    @case('o-check-circle')
    @case('o-online')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <circle cx="12" cy="12" r="9" stroke-width="1.5"/>
            <path stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                  d="M8.5 12.5l2.5 2.5 4.5-5"/>
        </svg>
    @break

    {{-- NEW: disconnect (broken link) --}}
    @case('o-disconnect')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <path stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                  d="M9 8l-2-2a3 3 0 0 0-4.2 4.2l2 2M15 16l2 2a3 3 0 1 0 4.2-4.2l-2-2M8 16l8-8"/>
        </svg>
    @break

    {{-- NEW: copy (overlapped squares) --}}
    @case('o-copy')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <rect x="9" y="9" width="10" height="12" rx="2" stroke-width="1.5"/>
            <rect x="5" y="3" width="10" height="12" rx="2" stroke-width="1.5"/>
        </svg>
    @break

    @default
        {{-- Fallback --}}
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <circle cx="12" cy="12" r="9" stroke-width="1.5"/>
        </svg>
@endswitch
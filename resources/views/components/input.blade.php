@props([
    'label'       => '',
    'name'        => '',
    'type'        => 'text',
    'placeholder' => null,
    'help'        => null,
    'showToggle'  => false,  // force the eye toggle even if type != password
    'value'       => null,
    'autocomplete'=> null,
])

@php
    $isPassword = $type === 'password' || $showToggle;
    $error = $name ? $errors->first($name) : null;
@endphp

<div @if($isPassword) x-data="{ show: false }" @endif>
    @if ($label)
        <label for="{{ $name }}" class="block text-sm font-medium text-[var(--aio-sub)] mb-1">
            {{ $label }}
        </label>
    @endif

    <div class="relative">
        <input
            name="{{ $name }}"
            id="{{ $name }}"
            @if($isPassword)
                type="password"
                x-bind:type="show ? 'text' : 'password'"
            @else
                type="{{ $type }}"
            @endif
            value="{{ old($name, $value) }}"
            @if($autocomplete) autocomplete="{{ $autocomplete }}" @endif
            placeholder="{{ $placeholder }}"
            {{ $attributes->merge([
                'class' => 'aio-input w-full mt-1 '.($error ? 'border-red-500 focus:border-red-500 focus:ring-red-500' : ''),
            ]) }}
        />

        {{-- Eye toggle (only renders for password fields) --}}
        @if($isPassword)
            <button
                type="button"
                class="absolute inset-y-0 right-0 mt-1 mr-2 text-xs aio-pill bg-white/5 hover:shadow-glow px-2"
                @click="show = !show"
                aria-label="Toggle password visibility"
            >
                <span x-show="!show">Show</span>
                <span x-show="show"  x-cloak>Hide</span>
            </button>
        @endif
    </div>

    @if ($error)
        <p class="mt-1 text-xs text-red-300">{{ $error }}</p>
    @elseif ($help)
        <p class="mt-1 text-xs text-[var(--aio-sub)]">{{ $help }}</p>
    @endif
</div>
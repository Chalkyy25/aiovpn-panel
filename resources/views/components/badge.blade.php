@props([
  'style' => 'soft',     // soft | solid
  'tone'  => 'slate',    // slate | blue | green | yellow | red
  'size'  => 'md',       // sm | md
  'icon'  => null,
])

@php
  $sizes = [
    'sm' => 'text-[11px] px-2 py-0.5 rounded-md',
    'md' => 'text-xs px-2.5 py-1 rounded-lg',
  ];

  // IMPORTANT: literal Tailwind classes only (no interpolation),
  // so Tailwind JIT can see them.
  $softTones = [
    'slate'  => 'bg-[var(--aio-soft)] border-[var(--aio-border)] text-[var(--aio-ink)]',
    'blue'   => 'bg-[var(--aio-accent-weak)] border-[var(--aio-accent-weak)] text-[var(--aio-accent)]',
    'green'  => 'bg-[color-mix(in_srgb,var(--aio-success)_14%,transparent)] border-[color-mix(in_srgb,var(--aio-success)_30%,transparent)] text-[var(--aio-success)]',
    'yellow' => 'bg-[color-mix(in_srgb,var(--aio-warning)_14%,transparent)] border-[color-mix(in_srgb,var(--aio-warning)_30%,transparent)] text-[var(--aio-warning)]',
    'red'    => 'bg-[color-mix(in_srgb,var(--aio-danger)_14%,transparent)] border-[color-mix(in_srgb,var(--aio-danger)_30%,transparent)] text-[var(--aio-danger)]',
  ];

  $solidTones = [
    'slate'  => 'bg-[var(--aio-ink)] text-white border-transparent',
    'blue'   => 'bg-[var(--aio-accent)] text-white border-transparent',
    'green'  => 'bg-[var(--aio-success)] text-white border-transparent',
    'yellow' => 'bg-[var(--aio-warning)] text-white border-transparent',
    'red'    => 'bg-[var(--aio-danger)] text-white border-transparent',
  ];

  $base = 'inline-flex items-center gap-1.5 border font-medium whitespace-nowrap';
  $pad  = $sizes[$size] ?? $sizes['md'];

  $toneCls = $style === 'solid'
    ? ($solidTones[$tone] ?? $solidTones['slate'])
    : ($softTones[$tone] ?? $softTones['slate']);

  $cls = trim("$base $pad $toneCls");
@endphp

<span {{ $attributes->merge(['class' => $cls]) }}>
  @if($icon)
    <x-icon :name="$icon" class="w-3.5 h-3.5 opacity-90" />
  @endif
  {{ $slot }}
</span>
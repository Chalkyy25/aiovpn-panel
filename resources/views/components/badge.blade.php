@props([
    // solid | soft (default soft)
    'style' => 'soft',

    // slate | blue | green | yellow | red
    'tone' => 'slate',

    // sm | md
    'size' => 'md',

    // optional left icon (heroicon name if you're using x-icon)
    'icon' => null,
])

@php
  $sizes = [
    'sm' => 'text-[11px] px-2 py-0.5 rounded-md',
    'md' => 'text-xs px-2.5 py-1 rounded-lg',
  ];

  // Uses your CSS tokens: --aio-accent / --aio-success / --aio-warning / --aio-danger
  $toneVars = [
    'slate'  => ['bg' => 'var(--aio-soft)', 'bd' => 'var(--aio-border)', 'tx' => 'var(--aio-ink)'],
    'blue'   => ['bg' => 'var(--aio-accent-weak)', 'bd' => 'var(--aio-accent-weak)', 'tx' => 'var(--aio-accent)'],
    'green'  => ['bg' => 'color-mix(in srgb, var(--aio-success) 14%, transparent)', 'bd' => 'color-mix(in srgb, var(--aio-success) 30%, transparent)', 'tx' => 'var(--aio-success)'],
    'yellow' => ['bg' => 'color-mix(in srgb, var(--aio-warning) 14%, transparent)', 'bd' => 'color-mix(in srgb, var(--aio-warning) 30%, transparent)', 'tx' => 'var(--aio-warning)'],
    'red'    => ['bg' => 'color-mix(in srgb, var(--aio-danger) 14%, transparent)',  'bd' => 'color-mix(in srgb, var(--aio-danger) 30%, transparent)',  'tx' => 'var(--aio-danger)'],
  ];

  $t = $toneVars[$tone] ?? $toneVars['slate'];
  $pad = $sizes[$size] ?? $sizes['md'];

  $base = "inline-flex items-center gap-1.5 border font-medium whitespace-nowrap {$pad}";
  $soft = "bg-[{$t['bg']}] border-[{$t['bd']}] text-[{$t['tx']}]";
  $solid = match($tone) {
    'blue'   => 'bg-[var(--aio-accent)] border-transparent text-white',
    'green'  => 'bg-[var(--aio-success)] border-transparent text-white',
    'yellow' => 'bg-[var(--aio-warning)] border-transparent text-white',
    'red'    => 'bg-[var(--aio-danger)] border-transparent text-white',
    default  => 'bg-[var(--aio-ink)] border-transparent text-white',
  };

  $cls = $style === 'solid' ? "{$base} {$solid}" : "{$base} {$soft}";
@endphp

<span {{ $attributes->merge(['class' => $cls]) }}>
  @if($icon)
    <x-icon :name="$icon" class="w-3.5 h-3.5 opacity-90" />
  @endif
  {{ $slot }}
</span>
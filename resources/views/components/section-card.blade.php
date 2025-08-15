@props(['title' => null])

<div {{ $attributes->merge(['class' => 'aio-card']) }}>
  @if($title)
    <div class="px-4 py-3 border-b aio-divider">
      <h2 class="font-semibold">{{ $title }}</h2>
    </div>
  @endif
  <div class="p-4">
    {{ $slot }}
  </div>
</div>
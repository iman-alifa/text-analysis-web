@props([
    'variant' => 'secondary', // primary | secondary | success | danger | warning | info
    'href' => null,
    'type' => 'button',
    'icon' => null,
    'iconPosition' => 'left',
    'size' => 'md', // sm | md | lg
    'block' => false,
    'disabled' => false,
    'target' => null,
    'title' => null,
])

@php
    $base = 'inline-flex items-center justify-center font-semibold tracking-wide rounded-xl transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed shadow-sm hover:shadow-md active:scale-[0.98]';

    $sizeClasses = [
        'sm' => 'px-3 py-1.5 text-xs gap-1.5',
        'md' => 'px-4 py-2 text-sm gap-2',
        'lg' => 'px-5 py-2.5 text-base gap-2',
    ][$size];

    $variantClasses = [
        'primary'   => 'bg-gradient-to-r from-blue-600 to-indigo-600 text-white hover:from-blue-700 hover:to-indigo-700 focus:ring-blue-500',
        'secondary' => 'bg-white text-gray-700 border border-gray-300 hover:bg-gray-50 hover:border-gray-400 focus:ring-gray-400',
        'success'   => 'bg-gradient-to-r from-emerald-500 to-teal-600 text-white hover:from-emerald-600 hover:to-teal-700 focus:ring-emerald-500',
        'danger'    => 'bg-gradient-to-r from-rose-500 to-red-600 text-white hover:from-rose-600 hover:to-red-700 focus:ring-red-500',
        'warning'   => 'bg-gradient-to-r from-amber-500 to-orange-500 text-white hover:from-amber-600 hover:to-orange-600 focus:ring-amber-500',
        'info'      => 'bg-gradient-to-r from-sky-500 to-cyan-500 text-white hover:from-sky-600 hover:to-cyan-600 focus:ring-cyan-500',
    ][$variant];

    $blockClass = $block ? 'w-full' : '';
    $classes = trim("$base $sizeClasses $variantClasses $blockClass");
@endphp

@if ($href && !$disabled)
    <a href="{{ $href }}" {{ $target ? "target=$target rel=noopener" : '' }} {{ $title ? "title=$title" : '' }}
       {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon && $iconPosition === 'left')
            <span class="shrink-0">{!! $icon !!}</span>
        @endif
        <span>{{ $slot }}</span>
        @if ($icon && $iconPosition === 'right')
            <span class="shrink-0">{!! $icon !!}</span>
        @endif
    </a>
@else
    <button type="{{ $type }}" {{ $disabled ? 'disabled' : '' }} {{ $title ? "title=$title" : '' }}
        {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon && $iconPosition === 'left')
            <span class="shrink-0">{!! $icon !!}</span>
        @endif
        <span>{{ $slot }}</span>
        @if ($icon && $iconPosition === 'right')
            <span class="shrink-0">{!! $icon !!}</span>
        @endif
    </button>
@endif

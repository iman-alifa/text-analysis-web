@props([
    'score'      => 0,      // Confidence score: float 0–1 (P(ŷ₁|x))
    'scores'     => null,   // Optional: all class scores as array ['positive'=>0.8, ...]
    'showMargin' => false,  // Whether to show the margin of confidence detail
    'compact'    => false,  // Compact single-line mode
])

@php
    $confidencePercent = round((float) $score * 100, 1);

    // Calculate Margin of Confidence: M(x) = P(ŷ₁|x) - P(ŷ₂|x)
    if ($scores && is_array($scores) && count($scores) >= 2) {
        $sortedScores = array_values($scores);
        rsort($sortedScores);
        $margin = $sortedScores[0] - ($sortedScores[1] ?? 0);
    } else {
        // Approximate margin for 3-class: M ≈ (3·P - 1) / 2
        $margin = max(0.0, (3 * (float) $score - 1) / 2);
    }
    $marginPercent = round($margin * 100, 1);

    // Status thresholds
    if ($margin >= 0.6) {
        $status      = 'Confident';
        $barColor    = 'bg-green-500';
        $textColor   = 'text-green-700';
        $badgeClass  = 'bg-green-100 text-green-800 border-green-200';
    } elseif ($margin >= 0.3) {
        $status      = 'Moderate';
        $barColor    = 'bg-yellow-500';
        $textColor   = 'text-yellow-700';
        $badgeClass  = 'bg-yellow-100 text-yellow-800 border-yellow-200';
    } else {
        $status      = 'Uncertain';
        $barColor    = 'bg-red-500';
        $textColor   = 'text-red-700';
        $badgeClass  = 'bg-red-100 text-red-800 border-red-200';
    }
@endphp

@if($compact)
{{-- Compact inline mode --}}
<div class="flex items-center gap-2">
    <div class="flex-1 bg-gray-200 rounded-full h-1.5">
        <div class="{{ $barColor }} h-1.5 rounded-full transition-all duration-500"
             style="width: {{ $confidencePercent }}%"></div>
    </div>
    <span class="text-xs font-medium {{ $textColor }} whitespace-nowrap">{{ $confidencePercent }}%</span>
    <span class="text-xs px-1.5 py-0.5 rounded-full border font-medium {{ $badgeClass }}">{{ $status }}</span>
</div>
@else
{{-- Full mode --}}
<div class="mt-2 space-y-1">
    <div class="flex items-center justify-between text-xs">
        <span class="text-gray-500 font-medium">Confidence</span>
        <div class="flex items-center gap-1.5">
            <span class="font-bold text-gray-700">{{ $confidencePercent }}%</span>
            <span class="px-1.5 py-0.5 rounded-full border text-xs font-medium {{ $badgeClass }}">{{ $status }}</span>
        </div>
    </div>
    <div class="w-full bg-gray-200 rounded-full h-2">
        <div class="{{ $barColor }} h-2 rounded-full transition-all duration-700"
             style="width: {{ $confidencePercent }}%"></div>
    </div>
    @if($showMargin)
    <div class="flex items-center justify-between text-xs text-gray-400 pt-0.5">
        <span class="font-mono">M(x) = P(ŷ₁|x) − P(ŷ₂|x)</span>
        <span class="{{ $textColor }} font-semibold">{{ $marginPercent }}%</span>
    </div>
    @endif
</div>
@endif

@extends('layouts.app')

@section('content')
<div class="min-h-[60vh] flex items-center justify-center">
    <div class="text-center space-y-4">
        <div class="inline-flex items-center justify-center w-16 h-16 bg-indigo-100 rounded-full">
            <svg class="w-8 h-8 text-indigo-600 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
        </div>
        <h2 class="text-xl font-bold text-gray-900">Memuat Workspace Koreksi…</h2>
        <p class="text-gray-500 text-sm">Anda akan segera diarahkan ke halaman Active Learning.</p>
    </div>
</div>
@endsection

@extends('layouts.app')
@section('title', 'Performa Tim')
@section('content')
<div class="p-4 sm:p-6 lg:p-8 max-w-[1400px] mx-auto">

    <div class="mb-5 sm:mb-7">
        <h1 class="font-display text-[26px] sm:text-[32px] font-semibold text-[var(--text-primary)]">Performa Tim</h1>
        <p class="text-[var(--text-secondary)] text-sm mt-1">Nilai KPI, ketepatan, dan kualitas kerja tim internal.</p>
    </div>

    <div class="flex items-center gap-1 bg-[var(--surface-muted)] rounded-lg p-1 mb-5 sm:mb-6 w-fit max-w-full overflow-x-auto">
        <a href="{{ route('team-performance.index', ['tab' => 'performa']) }}"
           class="text-sm font-medium px-4 py-2 rounded-md transition-colors whitespace-nowrap shrink-0 {{ $tab === 'performa' ? 'bg-[var(--surface-card)] text-[var(--text-primary)] shadow-sm' : 'text-[var(--text-muted)] hover:text-[var(--text-secondary)]' }}">
            Performa
        </a>
        <a href="{{ route('team-performance.index', ['tab' => 'kehadiran']) }}"
           class="text-sm font-medium px-4 py-2 rounded-md transition-colors whitespace-nowrap shrink-0 {{ $tab === 'kehadiran' ? 'bg-[var(--surface-card)] text-[var(--text-primary)] shadow-sm' : 'text-[var(--text-muted)] hover:text-[var(--text-secondary)]' }}">
            Kehadiran
        </a>
    </div>

    @if ($tab === 'kehadiran')
        @include('team-performance.partials.tab-kehadiran')
    @else
        @include('team-performance.partials.tab-performa')
    @endif
</div>
@endsection

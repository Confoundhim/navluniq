@props(['at' => null, 'empty' => null])
@php $at = $at instanceof \Carbon\CarbonInterface ? $at : ($at ? \Illuminate\Support\Carbon::parse($at) : null); @endphp
@if($at)
    <time {{ $attributes }} datetime="{{ $at->toIso8601String() }}" data-ago="{{ $at->getTimestamp() }}" title="{{ $at->format('d.m.Y H:i') }}">{{ \App\Support\TimeAgo::label($at) }}</time>
@else
    <span {{ $attributes }}>{{ $empty }}</span>
@endif

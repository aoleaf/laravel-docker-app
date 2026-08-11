@extends('layouts.app')

@section('title', 'イベント一覧')

@section('content')
    <h1>イベント一覧</h1>

    @forelse ($events as $event)
        <article>
            <h2>
                <a href="{{ route('events.show', $event) }}">
                    {{ $event->title }}
                </a>
            </h2>
            <p>
                {{ $event->starts_at->format('Y年m月d日 H:i') }}
                / {{ $event->venue }}
            </p>
            <p>
                @if ($event->hasEnded())
                    <span class="text-muted">開催終了</span>
                @elseif ($event->isFull())
                    <span class="text-danger">満席</span>
                @else
                    残席 {{ $event->remainingSeats() }} / {{ $event->capacity }} 名
                @endif
            </p>
        </article>
    @empty
        <p>イベントがありません</p>
    @endforelse

    {{ $events->links() }}
@endsection

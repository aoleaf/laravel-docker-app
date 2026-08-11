@extends('layouts.app')

@section('title', $event->title)

@section('content')
    <article>
        <h1>{{ $event->title }}</h1>

        <dl>
            <dt>開催日時</dt>
            <dd>{{ $event->starts_at->format('Y年m月d日 H:i') }}</dd>

            <dt>会場</dt>
            <dd>{{ $event->venue }}</dd>

            <dt>定員</dt>
            <dd>{{ $event->capacity }} 名</dd>

            <dt>残席</dt>
            <dd>
                @if ($event->isFull())
                    <span class="text-danger">満席</span>
                @else
                    {{ $event->remainingSeats() }} 名
                @endif
            </dd>

            <dt>詳細</dt>
            <dd>
                @if ($event->description)
                    {!! nl2br(e($event->description)) !!}
                @else
                    （未登録）
                @endif
            </dd>
        </dl>
    </article>

    {{-- 予約導線 --}}
    @if ($event->hasEnded())
        <p class="text-muted">このイベントは終了しました。</p>
    @elseif ($event->isFull())
        <p class="text-danger">満席のため予約を受け付けていません。</p>
    @else
        <a href="{{ route('reservations.create', $event) }}">このイベントを予約する</a>
    @endif

    {{-- 予約者一覧 --}}
    <h2>予約状況（{{ $event->reservations->count() }} 件）</h2>

    <table>
        <thead>
            <tr>
                <th>受付番号</th>
                <th>お名前</th>
                <th>人数</th>
                <th>予約日時</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($event->reservations as $reservation)
                <tr>
                    <td>{{ $reservation->id }}</td>
                    <td>{{ $reservation->name }}</td>
                    <td>{{ $reservation->number_of_people }} 名</td>
                    <td>{{ $reservation->reserved_at->format('Y年m月d日 H:i') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4">まだ予約はありません</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <a href="{{ route('events.index') }}">イベント一覧に戻る</a>
@endsection

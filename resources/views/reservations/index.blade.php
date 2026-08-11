@extends('layouts.app')

@section('title', '予約一覧')

@section('content')
    <h1>予約一覧</h1>

    <table>
        <thead>
            <tr>
                <th>受付番号</th>
                <th>イベント</th>
                <th>お名前</th>
                <th>メールアドレス</th>
                <th>人数</th>
                <th>予約日時</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($reservations as $reservation)
                <tr>
                    <td>{{ $reservation->id }}</td>
                    <td>
                        <a href="{{ route('events.show', $reservation->event) }}">
                            {{ $reservation->event->title }}
                        </a>
                    </td>
                    <td>{{ $reservation->name }}</td>
                    <td>{{ $reservation->email }}</td>
                    <td>{{ $reservation->number_of_people }} 名</td>
                    <td>{{ $reservation->reserved_at->format('Y年m月d日 H:i') }}</td>
                    <td>
                        <form method="POST" action="{{ route('reservations.destroy', $reservation) }}"
                              onsubmit="return confirm('この予約をキャンセルしますか？')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger">キャンセル</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">予約がありません</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{ $reservations->links() }}
@endsection

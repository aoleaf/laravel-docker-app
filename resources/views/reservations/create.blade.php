@extends('layouts.app')

@section('title', '予約フォーム')

@section('content')
    <h1>予約フォーム</h1>

    <section>
        <h2>{{ $event->title }}</h2>
        <p>
            {{ $event->starts_at->format('Y年m月d日 H:i') }}
            / {{ $event->venue }}
            / 残席 {{ $event->remainingSeats() }} 名
        </p>
    </section>

    <form method="POST" action="{{ route('reservations.store', $event) }}">
        @csrf

        <div class="mb-3">
            <label for="name" class="form-label">お名前</label>
            <input type="text" class="form-control" id="name" name="name"
                   value="{{ old('name') }}">
            @error('name')
                <p class="text-danger">{{ $message }}</p>
            @enderror
        </div>

        <div class="mb-3">
            <label for="email" class="form-label">メールアドレス</label>
            <input type="email" class="form-control" id="email" name="email"
                   value="{{ old('email') }}">
            @error('email')
                <p class="text-danger">{{ $message }}</p>
            @enderror
        </div>

        <div class="mb-3">
            <label for="number_of_people" class="form-label">人数</label>
            <input type="number" class="form-control" id="number_of_people" name="number_of_people"
                   min="1" max="{{ $event->remainingSeats() }}"
                   value="{{ old('number_of_people', 1) }}">
            @error('number_of_people')
                <p class="text-danger">{{ $message }}</p>
            @enderror
        </div>

        <div class="mb-3">
            <label for="reserved_at" class="form-label">予約日時</label>
            <input type="datetime-local" class="form-control" id="reserved_at" name="reserved_at"
                   value="{{ old('reserved_at', $event->starts_at->format('Y-m-d\TH:i')) }}">
            @error('reserved_at')
                <p class="text-danger">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit" class="btn btn-primary">予約する</button>
    </form>

    <a href="{{ route('events.show', $event) }}">イベント詳細に戻る</a>
@endsection

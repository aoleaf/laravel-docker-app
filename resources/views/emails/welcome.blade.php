<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <title>ご登録ありがとうございます</title>
</head>
<body>
    <p>{{ $user->name }} 様</p>

    <p>{{ config('app.name') }} へのご登録ありがとうございます。</p>

    <p>下記のリンクからログインできます。</p>

    <p><a href="{{ route('login') }}">{{ route('login') }}</a></p>

    <p>{{ config('app.name') }}</p>
</body>
</html>

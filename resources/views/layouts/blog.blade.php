<!DOCTYPE html>
<html>
<head>
    <title>@yield('title') - My App</title>
    <link rel="stylesheet" href="/css/app.css">
</head>
<body>
    <header>
        <nav>
            <a href="/">ホーム</a>
            <a href="/posts">投稿一覧</a>
            <a href="/products">商品一覧</a>
            <a href="/events">イベント一覧</a>
            <a href="/reservations">予約一覧</a>
        </nav>
    </header>

    <main>
        @if (session('success'))
            <div class="alert alert-success">
                {{ session('success') }}
            </div>
        @endif

        @yield('content')
    </main>

    <footer>
        © 2026 My App
    </footer>
</body>
</html>
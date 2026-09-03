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
            @auth
                <a href="/tasks">タスク一覧</a>
            @endauth
            <a href="/products">商品一覧</a>
            <a href="/events">イベント一覧</a>
            <a href="/reservations">予約一覧</a>
            <a href="/purchases">購入履歴</a>

            <span class="nav-auth">
                @auth
                    <span class="nav-user">{{ auth()->user()->name }} さん</span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit">ログアウト</button>
                    </form>
                @else
                    <a href="{{ route('login') }}">ログイン</a>
                    <a href="{{ route('register') }}">新規登録</a>
                @endauth
            </span>
        </nav>
    </header>

    <main>
        @if (session('success'))
            <div class="alert alert-success">
                {{ session('success') }}
            </div>
        @endif

        @if (session('error'))
            <div class="alert alert-danger">
                {{ session('error') }}
            </div>
        @endif

        @yield('content')
    </main>

    <footer>
        © 2026 My App
    </footer>
</body>
</html>
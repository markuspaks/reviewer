<!DOCTYPE HTML>
<html lang="en">
<head>
    <meta charset=utf-8>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log in · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <main class="login">
        <form class="box login-box" action="{{ route('login') }}" method="post">
            @csrf
            <h1 class="title is-4">Reviewer</h1>

            <div class="field">
                <label class="label" for="email">Email</label>
                <div class="control">
                    <input class="input @error('email') is-danger @enderror" type="email" id="email" name="email"
                           value="{{ old('email') }}" autocomplete="username" required autofocus>
                </div>
                @error('email') <p class="help is-danger">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label class="label" for="password">Password</label>
                <div class="control">
                    <input class="input" type="password" id="password" name="password" autocomplete="current-password" required>
                </div>
                @error('password') <p class="help is-danger">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label class="checkbox">
                    <input type="checkbox" name="remember" value="1" @checked(old('remember'))> Keep me logged in
                </label>
            </div>

            <button class="button is-primary is-fullwidth" type="submit">Log in</button>
            <p class="help login-help">No account or forgot your password? Ask an admin to run <code>php artisan reviewer:user</code>.</p>
        </form>
    </main>
</body>
</html>

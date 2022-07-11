<!DOCTYPE HTML>
<html lang="en">
<head>
    <meta charset=utf-8>
    <title>{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>

    <nav class="navbar" role="navigation" aria-label="main navigation">
        <div class="navbar-brand">
            <a class="navbar-item" href="{{ url('') }}">
                Reviewer
            </a>

            <a role="button" class="navbar-burger" aria-label="menu" aria-expanded="false" data-target="navbar">
                <span aria-hidden="true"></span>
                <span aria-hidden="true"></span>
                <span aria-hidden="true"></span>
            </a>
        </div>

        <div id="navbar" class="navbar-menu">
            <div class="navbar-start">
                <a class="navbar-item">
                    Reviews
                </a>

                <a class="navbar-item" href="{{ url('send-emails') }}">
                    Send Emails
                </a>

                <a class="navbar-item" href="{{ url('configuration') }}">
                    Configuration
                </a>
            </div>

            <div class="navbar-end">
                <div class="navbar-item">
                    {{ \GrahamCampbell\Bitbucket\Facades\Bitbucket::currentUser()->show()['nickname'] }}
                    <a href="{{ url('logout') }}">Logout</a>
                </div>
            </div>
        </div>
    </nav>

    <div>
        @yield('content')
    </div>
</body>
</html>
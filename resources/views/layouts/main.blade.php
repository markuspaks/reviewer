<link rel="stylesheet" href="{{ asset('app.css') }}">
@vite(['resources/css/app.css', 'resources/js/app.js'])
<div>
    {{ \GrahamCampbell\Bitbucket\Facades\Bitbucket::currentUser()->show()['nickname'] }}
</div>
<div>
    <a href="{{ url('logout') }}">Logout</a>
</div>

@yield('content')
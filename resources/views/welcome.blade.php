<h3>{{ $dbStatus ?? 'Chưa test DB' }}</h3>

@if(!empty($users) && $users->count())
    <ul>
        @foreach($users as $user)
            <li>{{ $user->name }} - {{ $user->email }}</li>
        @endforeach
    </ul>
@endif
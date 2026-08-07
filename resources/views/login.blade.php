@extends('layouts.app')

@section('title', 'Login')

@section('content')
    <h2>Login</h2>

    <form method="post" action="{{ route('login.attempt') }}">
        @csrf
        <label>
            Name
            <input type="text" name="name" value="{{ old('name') }}" autocomplete="username" required>
        </label>

        @error('name')
            <p>{{ $message }}</p>
        @enderror

        <label>
            Password
            <input type="password" name="password" autocomplete="current-password" required>
        </label>

        <button type="submit">Log in</button>
    </form>
@endsection

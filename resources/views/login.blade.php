@extends('layouts.app')

@section('title', 'Login')

@section('content')
<h2>PBRP Login</h2>

<p>
    To submit your story, choose any username you like and today's password.<br />
    The username will not be shown unless you include it yourself in the story. <br />
</p>
<form class="login-form" method="post" action="{{ route('login.attempt') }}">
    @csrf
    <div class="login-field">
        <label>
            Name
            <input type="text" name="name" value="{{ old('name') }}" autocomplete="username" required>
        </label>
    </div>

    @error('name')
    <p>{{ $message }}</p>
    @enderror

    <div class="login-field">
        <label>
            Password
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
    </div>

    <div class="login-actions">
        <button type="submit">Log in</button>
    </div>
</form>
@endsection

@push('styles')
<style>
    .login-form {
        display: grid;
        gap: 0.75rem;
    }

    .login-field,
    .login-actions {
        display: block;
    }

    .login-field label {
        display: grid;
        grid-template-columns: 7rem minmax(0, 20rem);
        gap: 5px;
        align-items: center;
    }

    .login-field input {
        width: 100%;
        min-width: 0;
    }
</style>
@endpush
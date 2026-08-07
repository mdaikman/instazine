@extends('layouts.app')

@section('title', 'Suggest a story')

@section('content')
<h2>Suggest a story</h2>

<p>Story will be reviewed by a human before publishing.<br />
    All fields are optional, just offer something to approve<br />
    and be appropriate is all we ask.</p>

@if (session('status'))
<p>{{ session('status') }}</p>
@endif

<form method="post" action="{{ route('reporter.suggest-story.store') }}" enctype="multipart/form-data">
    @csrf
    @include('admin.partials.article-form', ['article' => null, 'showDate' => false])
    <button type="submit">Submit suggestion</button>
</form>
@endsection

@push('styles')
<style>
    .main-view textarea {
        padding: 6px;
    }
</style>
@endpush
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

<form class="suggest-story-form" method="post" action="{{ route('reporter.suggest-story.store') }}" enctype="multipart/form-data">
    @csrf
    @include('admin.partials.article-form', ['article' => null, 'showDate' => false])
    <div class="suggest-story-actions">
        <button type="submit">Submit suggestion</button>
    </div>
</form>
@endsection

@push('styles')
<style>
    .main-view textarea {
        padding: 6px;
    }

    .suggest-story-form .article-form-field {
        display: grid;
        grid-template-columns: 7rem minmax(0, 1fr);
        gap: 5px;
        align-items: start;
        padding: 5px;
    }

    .suggest-story-form .article-form-field > input:not([type='hidden']),
    .suggest-story-form .article-form-field > textarea {
        width: 100%;
        min-width: 0;
    }

    .suggest-story-actions {
        padding: 5px;
    }

    @media (max-width: 480px) {
        .suggest-story-form .article-form-field {
            grid-template-columns: 1fr;
        }
    }
</style>
@endpush

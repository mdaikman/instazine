@extends('layouts.app')

@section('title', 'Submission successful')

@section('content')
    <h1 class="submission-success">Success!</h1>

    <table class="submission-summary">
        <tbody>
            <tr>
                <td><h2>{{ $article->Headline }}</h2></td>
            </tr>
            <tr>
                <td>
                    @if (filled($article->Pic))
                        <img
                            src="{{ route('reporter.suggest-story.picture', $article) }}"
                            alt="Uploaded picture for {{ $article->Headline }}"
                        >
                    @endif
                </td>
            </tr>
            <tr>
                <td>{!! nl2br(e($article->Text)) !!}</td>
            </tr>
        </tbody>
    </table>
@endsection

@push('styles')
    <style>
        .submission-success {
            color: #278547;
        }

        .submission-summary {
            width: 450px;
            border-collapse: collapse;
        }

        .submission-summary td {
            padding: 5px;
            border: 0;
        }

        .submission-summary img {
            display: block;
            max-width: 440px;
            height: auto;
        }
    </style>
@endpush

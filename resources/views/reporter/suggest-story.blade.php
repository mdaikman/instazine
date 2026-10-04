@extends('layouts.app')

@section('title', 'Suggest a story')

@section('content')
<h2>Suggest a story</h2>

<p>Story will be reviewed by a human before publishing.<br />
    All fields are optional. In general, just offer something to approve and be <a href="#submission-guidelines-heading">appropriate</a> is all we ask.<br /></p>

@if (session('status'))
<p>{{ session('status') }}</p>
@endif

<form class="suggest-story-form" method="post" action="{{ route('reporter.suggest-story.store') }}" enctype="multipart/form-data">
    @csrf
    @include('admin.partials.article-form', [
    'article' => null,
    'showDate' => false,
    'formKey' => 'suggest-story',
    ])
    <div class="suggest-story-actions">
        <button type="submit">Submit suggestion</button>
    </div>
</form>
<p>&nbsp;</p>
<p>&nbsp;</p>
<p>&nbsp;</p>
<p>&nbsp;</p>
<p>&nbsp;</p>
<p>&nbsp;</p>
<p>&nbsp;</p>
<p>&nbsp;</p>
<p>&nbsp;</p>
<p>&nbsp;</p>
<p>&nbsp;</p>
<section class="submission-guidelines" aria-labelledby="submission-guidelines-heading">
    <p id="submission-guidelines-heading"><strong>List of TEDxVictoria inappropriate topics:</strong></p>
    <ul>
        <li>Profanity, vulgar or sexually explicit content</li>
        <li>Hate speech, discriminatory language, harassment or personal attacks</li>
        <li>Political advocacy, campaigning, endorsements or partisan messaging</li>
        <li>Religious promotion, proselytizing or messaging intended to persuade others toward a particular belief</li>
        <li>Promotion or advertising of businesses, products, services or personal brands</li>
        <li>Fundraising, donation requests or calls to purchase something</li>
        <li>Confidential information about another person without their consent</li>
        <li>Defamatory, knowingly false or misleading claims</li>
        <li>Graphic, violent or otherwise inappropriate content</li>
        <li>Content that could reasonably make another attendee feel targeted, unsafe or unwelcome</li>
        <li>Anything that conflicts with the spirit and standards of TEDxVictoria</li>
    </ul>
</section>
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

    .suggest-story-form .article-form-field>input:not([type='hidden']),
    .suggest-story-form .article-form-field>textarea {
        width: 100%;
        min-width: 0;
    }

    .suggest-story-actions {
        padding: 5px;
    }

    .suggest-story-form .article-form-error {
        grid-column: 2;
        color: #b00020;
        margin: 0;
    }

    .submission-guidelines {
        margin-top: 1.5rem;
    }

    .submission-guidelines ul {
        padding-left: 1.5rem;
    }

    .submission-guidelines li {
        font-weight: 300;
    }

    .submission-guidelines li+li {
        margin-top: 0.35rem;
    }

    @media (max-width: 480px) {
        .suggest-story-form .article-form-field {
            grid-template-columns: 1fr;
        }

        .suggest-story-form .article-form-error {
            grid-column: 1;
        }
    }
</style>
@endpush
@extends('layouts.app')

@section('title', 'Articles')

@section('content')
<h2>Articles</h2>

<button type="button" id="open-create-article-modal">Create new</button>

@if (session('status'))
<p>{{ session('status') }}</p>
@endif

<form method="get" action="{{ route('admin.articles') }}">
    <label for="per-page">Entries per page</label>
    <select id="per-page" name="per_page">
        @foreach ($perPageOptions as $option)
        <option value="{{ $option }}" @selected($perPage===$option)>{{ $option }}</option>
        @endforeach
    </select>
    <button type="submit">Apply</button>
</form>

<div class="articles-table-scroll" role="region" aria-label="Articles table" tabindex="0">
<table class="articles-table">
    <thead>
        <tr>
            <th scope="col" class="table-actions"><span class="sr-only">Record controls</span></th>
            <th scope="col">OK'd</th>
            <th scope="col">Headline</th>
            <th scope="col">Pic</th>
            <th scope="col">Text</th>
            <th scope="col">Author</th>
            <th scope="col">Tracking</th>
            <th scope="col">Date</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($articles as $article)
        <tr>
            <td class="table-actions">
                <button type="button" data-edit-modal="edit-article-modal-{{ $article->A_id }}" aria-label="Edit article" title="Edit">✎</button>

                <form class="inline-form" method="post" action="{{ route('admin.articles.destroy', $article) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" aria-label="Delete article" title="Delete">X</button>
                </form>
            </td>
            <td style='text-align: center;'>
                <form method="post" action="{{ route('admin.articles.approval', $article) }}">
                    @csrf
                    @method(' PATCH')
                    <input type="hidden" name="approved" value="0">
                    <input
                        type="checkbox"
                        name="approved"
                        value="1"
                        aria-label="Toggle article approval"
                        @checked($article->Approved)
                    onchange="this.form.submit()"
                    >
                    <noscript><button type="submit">Update</button></noscript>
                </form>
            </td>
            <td>{{ $article->Headline }}</td>
            <td class="article-picture-cell">
                <span class="article-picture-path">{{ $article->Pic }}</span>
                @if ($article->hasPicture)
                <br>
                <img
                    class="article-picture"
                    src="{{ route('admin.articles.picture', $article) }}"
                    alt="Article picture for {{ $article->Headline }}">
                @endif
            </td>
            <td>{!! preg_replace('/\R/u', '<br><br>', e($article->Text)) !!}</td>
            <td>{{ $article->author?->name ?? "Unknown user #{$article->Author}" }}</td>
            <td class="tracking-column">
                {{ $article->tracking_count }} times<br>
                Last:<br>
                <time
                    class="article-date tracking-last-seen"
                    @if ($article->tracking_max_created_at) data-utc-date="{{ $article->tracking_max_created_at->toIso8601String() }}" @endif
                >{{ $article->tracking_max_created_at?->format('Y-m-d H:i') ?? '—' }}</time>
            </td>
            <td>
                <time class="article-date" data-utc-date="{{ $article->Date?->toIso8601String() }}">
                    {{ $article->Date?->format('Y-m-d H:i') }}
                </time>
            </td>
        </tr>
        @empty
        <tr>
            <td colspan="8">No articles found.</td>
        </tr>
        @endforelse
    </tbody>
</table>
</div>

{{ $articles->links() }}

<dialog id="create-article-modal" class="article-modal">
    <form method="post" action="{{ route('admin.articles.store') }}" enctype="multipart/form-data">
        @csrf
        <h3>Create new article</h3>
        @include('admin.partials.article-form', ['article' => null])
        <div class="article-form-actions">
            <button type="button" id="close-create-article-modal">Cancel</button>
            <button type="submit">Create</button>
        </div>
    </form>
</dialog>

@foreach ($articles as $article)
<dialog id="edit-article-modal-{{ $article->A_id }}" class="article-modal">
    <form method="post" action="{{ route('admin.articles.update', $article) }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')
        <h3>Edit article</h3>
        @include('admin.partials.article-form', ['article' => $article])
        <div class="article-form-actions">
            <button type="button" data-close-modal="edit-article-modal-{{ $article->A_id }}">Cancel</button>
            <button type="submit">Save</button>
        </div>
    </form>
</dialog>
@endforeach
@endsection

@push('styles')
<style>
    .articles-table {
        border-collapse: collapse;
        min-width: 100%;
    }

    .articles-table-scroll {
        width: 100%;
        max-width: 100%;
        overflow-x: auto;
        overscroll-behavior-inline: contain;
        -webkit-overflow-scrolling: touch;
    }

    .articles-table th,
    .articles-table td {
        padding: 0.5rem;
        border: 1px solid currentColor;
        vertical-align: top;
    }

    .articles-table th,
    .articles-table .table-actions {
        border: 0;
        white-space: nowrap;
    }

    .article-modal textarea {
        padding: 6px;
    }

    .article-modal .article-form-field {
        display: grid;
        grid-template-columns: 7rem minmax(0, 1fr);
        gap: 5px;
        align-items: start;
        padding: 5px;
    }

    .article-modal .article-form-field > input:not([type='hidden']),
    .article-modal .article-form-field > textarea {
        width: 100%;
        min-width: 0;
    }

    .article-modal .article-picture-input,
    .article-modal .article-form-thumbnail {
        grid-column: 2;
    }

    .article-modal .article-form-thumbnail {
        width: min(225px, 100%);
        height: auto;
    }

    .article-modal .article-form-actions {
        padding: 5px;
    }

    .article-picture {
        width: 100%;
        height: auto;
        cursor: pointer;
    }

    .article-picture-cell {
        width: 225px;
    }

    .article-picture-cell.is-full-size {
        width: 450px;
    }

    .article-picture-path {
        font-size: 0.75rem;
        overflow-wrap: anywhere;
    }

    .articles-table td.tracking-column {
        font-size: 0.75rem;
    }

    .tracking-last-seen {
        white-space: nowrap;
    }

    @media (max-width: 767px) {
        .articles-table-scroll {
            max-width: calc(100vw - 2rem);
        }

        .articles-table tbody td.table-actions {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            align-items: stretch;
        }

        .articles-table tbody td.table-actions .inline-form {
            display: block;
        }

        .articles-table tbody td.table-actions button {
            width: 100%;
        }

        .article-picture-path {
            display: none;
        }

        .article-picture-cell {
            width: 100px;
            min-width: 100px;
        }

        .article-modal {
            width: calc(100vw - 2rem);
            max-width: calc(100vw - 2rem);
            max-height: calc(100dvh - 2rem);
            padding: 1rem;
            overflow-x: hidden;
            overflow-y: auto;
        }

        .article-modal form,
        .article-modal .article-form-field {
            min-width: 0;
            max-width: 100%;
        }

        .article-modal input,
        .article-modal textarea {
            max-width: 100%;
        }
    }
</style>
@endpush

@push('scripts')
<script>
    const createArticleModal = document.querySelector('#create-article-modal');

    document.querySelector('#open-create-article-modal').addEventListener('click', () => {
        createArticleModal.showModal();
    });

    document.querySelector('#close-create-article-modal').addEventListener('click', () => {
        createArticleModal.close();
    });

    document.querySelectorAll('[data-edit-modal]').forEach((button) => {
        button.addEventListener('click', () => {
            document.querySelector(`#${button.dataset.editModal}`).showModal();
        });
    });

    document.querySelectorAll('[data-close-modal]').forEach((button) => {
        button.addEventListener('click', () => {
            document.querySelector(`#${button.dataset.closeModal}`).close();
        });
    });

    document.querySelectorAll('.article-picture').forEach((picture) => {
        picture.addEventListener('click', () => {
            const showFullSize = !picture.classList.contains('is-full-size');

            document.querySelectorAll('.article-picture').forEach((image) => {
                image.classList.toggle('is-full-size', showFullSize);
                image.closest('.article-picture-cell')?.classList.toggle('is-full-size', showFullSize);
            });
        });
    });

    const formatLocalDateTimeInput = (date) => {
        const pad = (value) => String(value).padStart(2, '0');

        return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}` +
            `T${pad(date.getHours())}:${pad(date.getMinutes())}`;
    };

    const userTimeZone = Intl.DateTimeFormat().resolvedOptions().timeZone;

    document.querySelectorAll('.article-timezone').forEach((input) => {
        input.value = userTimeZone;
    });

    document.querySelectorAll('.article-date-input').forEach((input) => {
        input.value = input.dataset.utcDate ?
            formatLocalDateTimeInput(new Date(input.dataset.utcDate)) :
            formatLocalDateTimeInput(new Date());
    });

    const dateFormatter = new Intl.DateTimeFormat(undefined, {
        dateStyle: 'short',
        timeStyle: 'short',
    });

    document.querySelectorAll('.article-date[data-utc-date]').forEach((element) => {
        element.textContent = dateFormatter.format(new Date(element.dataset.utcDate));
    });
</script>
@endpush

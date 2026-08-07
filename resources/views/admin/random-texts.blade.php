@extends('layouts.app')

@section('title', 'Random texts')

@section('content')
    <h2>Random texts</h2>

    <button type="button" id="open-create-modal">Create new</button>

    @if (session('status'))
        <p>{{ session('status') }}</p>
    @endif

    <form method="get" action="{{ route('admin.random-texts') }}">
        <label for="per-page">Entries per page</label>
        <select id="per-page" name="per_page">
            @foreach ($perPageOptions as $option)
                <option value="{{ $option }}" @selected($perPage === $option)>{{ $option }}</option>
            @endforeach
        </select>
        <label for="random-text-type-filter">Type</label>
        <select id="random-text-type-filter" name="type">
            <option value="">All types</option>
            @foreach (['HEADER', 'MID', 'FOOTER'] as $filterType)
                <option value="{{ $filterType }}" @selected($type === $filterType)>{{ $filterType }}</option>
            @endforeach
        </select>
        <button type="submit">Apply</button>
    </form>

    <table class="random-texts-table">
        <thead>
            <tr>
                <th scope="col" class="table-actions"><span class="sr-only">Record controls</span></th>
                <th scope="col">Type</th>
                <th scope="col">Random text</th>
                <th scope="col">Tracking</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($randomTexts as $randomText)
                <tr>
                    <td class="table-actions">
                        <button type="button" data-edit-modal="edit-text-modal-{{ $randomText->R_id }}" aria-label="Edit random text" title="Edit">✎</button>

                        <form class="inline-form" method="post" action="{{ route('admin.random-texts.destroy', $randomText) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" aria-label="Delete random text" title="Delete">X</button>
                        </form>
                    </td>
                    <td>{{ $randomText->Type }}</td>
                    <td>{{ $randomText->Random_text }}</td>
                    <td class="tracking-column">
                        {{ $randomText->tracking_count }} times<br>
                        Last:<br>
                        <time
                            class="tracking-date tracking-last-seen"
                            @if ($randomText->tracking_max_created_at) data-utc-date="{{ $randomText->tracking_max_created_at->toIso8601String() }}" @endif
                        >{{ $randomText->tracking_max_created_at?->format('Y-m-d H:i') ?? '—' }}</time>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4">No random texts found.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{ $randomTexts->links() }}

    <dialog id="create-text-modal" class="random-texts-modal">
        <form method="post" action="{{ route('admin.random-texts.store') }}">
            @csrf
            <h3>Create new random text</h3>

            <div>
                <label for="random-text-type">Type</label>
                <select id="random-text-type" name="type" required>
                    <option value="HEADER">HEADER</option>
                    <option value="MID">MID</option>
                    <option value="FOOTER">FOOTER</option>
                </select>
            </div>

            <div>
                <label for="random-text-content">Text</label>
                <textarea id="random-text-content" name="text" rows="5" maxlength="255" required></textarea>
            </div>

            <div>
                <button type="button" id="close-create-modal">Cancel</button>
                <button type="submit">Create</button>
            </div>
        </form>
    </dialog>

    @foreach ($randomTexts as $randomText)
        <dialog id="edit-text-modal-{{ $randomText->R_id }}" class="random-texts-modal">
            <form method="post" action="{{ route('admin.random-texts.update', $randomText) }}">
                @csrf
                @method('PUT')
                <h3>Edit random text</h3>

                <div>
                    <label for="edit-random-text-type-{{ $randomText->R_id }}">Type</label>
                    <select id="edit-random-text-type-{{ $randomText->R_id }}" name="type" required>
                        @foreach (['HEADER', 'MID', 'FOOTER'] as $type)
                            <option value="{{ $type }}" @selected($randomText->Type === $type)>{{ $type }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="edit-random-text-content-{{ $randomText->R_id }}">Text</label>
                    <textarea id="edit-random-text-content-{{ $randomText->R_id }}" name="text" rows="5" maxlength="255" required>{{ $randomText->Random_text }}</textarea>
                </div>

                <div>
                    <button type="button" data-close-modal="edit-text-modal-{{ $randomText->R_id }}">Cancel</button>
                    <button type="submit">Save</button>
                </div>
            </form>
        </dialog>
    @endforeach

@endsection

@push('styles')
    <style>
        .random-texts-table {
            border-collapse: collapse;
        }

        .random-texts-table th,
        .random-texts-table td {
            padding: 0.5rem;
            border: 1px solid currentColor;
        }

        .random-texts-table th {
            border: 0;
        }

        .random-texts-table .table-actions {
            border: 0;
            white-space: nowrap;
        }

        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        .random-texts-modal textarea {
            padding: 6px;
        }

        .inline-form {
            display: inline;
        }

        .random-texts-table td.tracking-column {
            font-size: 0.75rem;
        }

        .tracking-last-seen {
            white-space: nowrap;
        }
    </style>
@endpush

@push('scripts')
    <script>
        const createTextModal = document.querySelector('#create-text-modal');

        document.querySelector('#open-create-modal').addEventListener('click', () => {
            createTextModal.showModal();
        });

        document.querySelector('#close-create-modal').addEventListener('click', () => {
            createTextModal.close();
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

        const trackingDateFormatter = new Intl.DateTimeFormat(undefined, {
            dateStyle: 'short',
            timeStyle: 'short',
        });

        document.querySelectorAll('.tracking-date[data-utc-date]').forEach((element) => {
            element.textContent = trackingDateFormatter.format(new Date(element.dataset.utcDate));
        });
    </script>
@endpush

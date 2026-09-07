@if ($article)
    <div class="article-form-field">
        <label>
            <input type="checkbox" name="approved" value="1" @checked($article->Approved)>
            Approved
        </label>
    </div>
@endif

<div class="article-form-field">
    <label for="article-headline-{{ $article?->A_id ?? 'new' }}">Headline</label>
    <input id="article-headline-{{ $article?->A_id ?? 'new' }}" type="text" name="headline" maxlength="64" value="{{ $article?->Headline }}">
</div>

<div class="article-form-field">
    <label for="article-pic-{{ $article?->A_id ?? 'new' }}">Pic</label>
    @if ($article?->hasPicture)
        <img
            class="article-form-thumbnail"
            src="{{ route('admin.articles.picture', $article) }}"
            alt="Current picture for {{ $article->Headline }}"
        >
    @endif
    <input
        class="article-picture-input"
        id="article-pic-{{ $article?->A_id ?? 'new' }}"
        type="file"
        name="pic"
        accept="image/jpeg,image/png,image/gif,image/webp,image/avif"
    >
</div>

<div class="article-form-field">
    <label for="article-text-{{ $article?->A_id ?? 'new' }}">Text</label>
    <textarea id="article-text-{{ $article?->A_id ?? 'new' }}" name="text" rows="6">{{ $article?->Text }}</textarea>
</div>

@if ($article)
    <div class="article-form-field">
        <label for="article-author-{{ $article->A_id }}">Author</label>
        <input
            id="article-author-{{ $article->A_id }}"
            type="text"
            value="{{ $article->author?->name ?? "Unknown user #{$article->Author}" }}"
            readonly
        >
        <input type="hidden" name="author" value="{{ $article->Author }}">
    </div>
@endif

@if ($showDate ?? true)
    <div class="article-form-field">
        <label for="article-date-{{ $article?->A_id ?? 'new' }}">Date</label>
        <input type="hidden" name="timezone" class="article-timezone" value="UTC">
        <input
            id="article-date-{{ $article?->A_id ?? 'new' }}"
            class="article-date-input"
            type="datetime-local"
            name="date"
            data-utc-date="{{ $article?->Date?->toIso8601String() }}"
            required
        >
    </div>
@endif

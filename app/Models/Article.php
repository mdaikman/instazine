<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['Date', 'Author', 'Headline', 'Pic', 'Approved', 'Text'])]
class Article extends Model
{
    /** @var string */
    protected $table = 'Article';

    /** @var string */
    protected $primaryKey = 'A_id';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Date' => 'datetime',
            'Approved' => 'boolean',
            'tracking_max_created_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'Author');
    }

    public function tracking(): HasMany
    {
        return $this->hasMany(Tracking::class, 'A_id');
    }
}

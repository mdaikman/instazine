<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['A_id', 'R_id'])]
class Tracking extends Model
{
    /** @var string */
    protected $table = 'Tracking';

    /** @var string */
    protected $primaryKey = 'T_id';

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'A_id');
    }

    public function randomText(): BelongsTo
    {
        return $this->belongsTo(RandomText::class, 'R_id');
    }
}

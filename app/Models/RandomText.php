<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['Type', 'Random_text'])]
class RandomText extends Model
{
    /** @var string */
    protected $table = 'Random_text';

    /** @var string */
    protected $primaryKey = 'R_id';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tracking_max_created_at' => 'datetime',
        ];
    }

    public function tracking(): HasMany
    {
        return $this->hasMany(Tracking::class, 'R_id');
    }
}

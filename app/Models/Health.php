<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['Date', 'Message'])]
class Health extends Model
{
    /** @var string */
    protected $table = 'Health';

    /** @var string */
    protected $primaryKey = 'H_id';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['Date' => 'datetime'];
    }
}

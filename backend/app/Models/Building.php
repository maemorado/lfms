<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Building extends Model
{
    protected $table = 'buildings';
    protected $guarded = ['id'];

    public function facilities(): HasMany
    {
        return $this->hasMany(Facility::class, 'building_id');
    }
}

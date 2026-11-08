<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class FacilityType extends Model
{
    protected $table = 'facility_types';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function facilities(): HasMany
    {
        return $this->hasMany(Facility::class, 'facility_type_id');
    }
}

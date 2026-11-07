<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class FacilityFeature extends Model
{
    protected $table = 'facility_features';
    protected $guarded = ['id'];

    public function facilities(): BelongsToMany
    {
        return $this->belongsToMany(Facility::class, 'facility_feature_assignments', 'feature_id', 'facility_id')
            ->withPivot('created_at');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FacilityFeatureAssignment extends Model
{
    protected $table = 'facility_feature_assignments';
    public $timestamps = false;
    protected $guarded = ['id'];

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }

    public function feature(): BelongsTo
    {
        return $this->belongsTo(FacilityFeature::class, 'feature_id');
    }
}

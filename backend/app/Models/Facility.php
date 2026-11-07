<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Facility extends Model
{
    public const STATUSES = ['available', 'unavailable', 'maintenance', 'inactive'];

    protected $table = 'facilities';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['capacity' => 'integer'];
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class, 'building_id');
    }

    public function facilityType(): BelongsTo
    {
        return $this->belongsTo(FacilityType::class, 'facility_type_id');
    }

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(FacilityFeature::class, 'facility_feature_assignments', 'facility_id', 'feature_id')
            ->withPivot('created_at');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'facility_id');
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FacilityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this['id'], 'name' => $this['name'], 'code' => $this['code'],
            'capacity' => (int)$this['capacity'], 'description' => $this['description'],
            'status' => $this['status'], 'building_id' => $this['building_id'],
            'building' => $this['building'] ?? null, 'facility_type_id' => $this['facility_type_id'],
            'facility_type' => $this['facility_type'] ?? null, 'features' => $this['features'] ?? [],
            'created_at' => $this['created_at'] ?? null, 'updated_at' => $this['updated_at'] ?? null,
        ];
    }
}

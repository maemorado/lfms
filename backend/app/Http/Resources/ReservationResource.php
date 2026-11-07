<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this['id'], 'facility_id' => $this['facility_id'], 'requester_id' => $this['requester_id'],
            'start_at' => $this['start_at'], 'end_at' => $this['end_at'], 'purpose' => $this['purpose'],
            'subject_course' => $this['subject_course'], 'participant_count' => (int)$this['participant_count'],
            'status' => $this['status'], 'cancellation_reason' => $this['cancellation_reason'],
            'facility' => $this['facility'] ?? null, 'requester' => $this['requester'] ?? null,
            'approvals' => $this['approvals'] ?? [], 'created_at' => $this['created_at'] ?? null,
            'updated_at' => $this['updated_at'] ?? null,
        ];
    }
}

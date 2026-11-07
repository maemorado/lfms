<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Reservation extends Model
{
    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled', 'completed'];

    protected $table = 'reservations';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'start_at' => 'immutable_datetime',
            'end_at' => 'immutable_datetime',
            'participant_count' => 'integer',
        ];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'requester_id');
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(Profile::class, 'reservation_participants', 'reservation_id', 'user_id')
            ->withPivot('created_at');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(ReservationApproval::class, 'reservation_id')->orderByDesc('reviewed_at');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ReservationAttachment::class, 'reservation_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ReservationAttachment extends Model
{
    protected $table = 'reservation_attachments';
    public $timestamps = false;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['uploaded_at' => 'immutable_datetime'];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id');
    }
}

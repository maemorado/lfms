<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Profile extends Model
{
    protected $table = 'profiles';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = ['id'];

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'requester_id');
    }
}

<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
final class InventoryLocation extends Model {
 protected $table='inventory_locations'; public $incrementing=false; protected $keyType='string'; protected $guarded=['id']; const UPDATED_AT=null;
 public function facility(): BelongsTo { return $this->belongsTo(Facility::class,'facility_id'); }
 public function stock(): HasMany { return $this->hasMany(InventoryStock::class,'location_id'); }
}
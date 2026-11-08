<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
final class InventoryMovement extends Model {
 protected $table='inventory_movements'; public $incrementing=false; protected $keyType='string'; protected $guarded=['id']; const UPDATED_AT=null;
 public function item(): BelongsTo { return $this->belongsTo(InventoryItem::class,'item_id'); }
 public function location(): BelongsTo { return $this->belongsTo(InventoryLocation::class,'location_id'); }
 public function performer(): BelongsTo { return $this->belongsTo(Profile::class,'performed_by'); }
}
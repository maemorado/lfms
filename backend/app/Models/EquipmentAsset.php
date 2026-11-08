<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
final class EquipmentAsset extends Model {
 protected $table='equipment_assets'; public $incrementing=false; protected $keyType='string'; protected $guarded=['id'];
 public function item(): BelongsTo { return $this->belongsTo(InventoryItem::class,'item_id'); }
 public function location(): BelongsTo { return $this->belongsTo(InventoryLocation::class,'location_id'); }
}
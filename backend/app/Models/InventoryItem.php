<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
final class InventoryItem extends Model {
 protected $table='inventory_items'; public $incrementing=false; protected $keyType='string'; protected $guarded=['id'];
 public function category(): BelongsTo { return $this->belongsTo(ItemCategory::class,'category_id'); }
 public function stock(): HasMany { return $this->hasMany(InventoryStock::class,'item_id'); }
 public function assets(): HasMany { return $this->hasMany(EquipmentAsset::class,'item_id'); }
 public function movements(): HasMany { return $this->hasMany(InventoryMovement::class,'item_id'); }
}
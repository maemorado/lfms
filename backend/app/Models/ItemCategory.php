<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
final class ItemCategory extends Model {
 protected $table='item_categories'; public $incrementing=false; protected $keyType='string'; protected $guarded=['id']; const UPDATED_AT=null;
 public function items(): HasMany { return $this->hasMany(InventoryItem::class,'category_id'); }
}
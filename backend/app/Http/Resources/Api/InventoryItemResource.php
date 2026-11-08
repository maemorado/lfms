<?php
namespace App\Http\Resources\Api;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
final class InventoryItemResource extends JsonResource {
 public function toArray(Request $request): array {
  $r=is_array($this->resource)?$this->resource:(array)$this->resource;
  $stock=is_array($r['inventory_stock']??null)?$r['inventory_stock']:[];
  $assets=is_array($r['equipment_assets']??null)?$r['equipment_assets']:[];
  $quantity=array_sum(array_map(fn($x)=>(float)($x['quantity']??0),$stock));
  $available=count(array_filter($assets,fn($x)=>($x['status']??null)==='available'));
  $minimum=(float)($r['minimum_stock']??0);
  $availability=($r['item_type']??null)==='equipment'?($available>0?'available':'unavailable'):($quantity<=0?'out_of_stock':($quantity<=$minimum?'low_stock':'in_stock'));
  return ['id'=>$r['id']??null,'category_id'=>$r['category_id']??null,'item_categories'=>$r['item_categories']??null,'name'=>$r['name']??'','item_code'=>$r['item_code']??'','item_type'=>$r['item_type']??'','unit'=>$r['unit']??'unit','description'=>$r['description']??null,'minimum_stock'=>$minimum,'status'=>$r['status']??'active','quantity_on_hand'=>$quantity,'available_assets'=>$available,'availability'=>$availability,'inventory_stock'=>$stock,'equipment_assets'=>$assets,'created_at'=>$r['created_at']??null,'updated_at'=>$r['updated_at']??null];
 }
}
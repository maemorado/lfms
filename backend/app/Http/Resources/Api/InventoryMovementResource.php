<?php
namespace App\Http\Resources\Api;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
final class InventoryMovementResource extends JsonResource {
 public function toArray(Request $request): array {
  $r=is_array($this->resource)?$this->resource:(array)$this->resource;
  return ['id'=>$r['id']??null,'item_id'=>$r['item_id']??null,'item'=>$r['inventory_items']??null,'location_id'=>$r['location_id']??null,'location'=>$r['inventory_locations']??null,'movement_type'=>$r['movement_type']??null,'quantity'=>(float)($r['quantity']??0),'reference_type'=>$r['reference_type']??null,'reference_id'=>$r['reference_id']??null,'performed_by'=>$r['performed_by']??null,'performer'=>$r['profiles']??null,'remarks'=>$r['remarks']??null,'created_at'=>$r['created_at']??null];
 }
}
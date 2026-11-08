<?php
namespace App\Http\Requests\Api;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
final class InventoryIndexRequest extends FormRequest {
 public function authorize(): bool{return true;}
 public function rules():array{return ['page'=>['sometimes','integer','min:1'],'per_page'=>['sometimes','integer','min:1','max:100'],'search'=>['sometimes','string','max:120'],'item_type'=>['sometimes',Rule::in(['equipment','consumable'])],'category_id'=>['sometimes','uuid'],'status'=>['sometimes',Rule::in(['active','inactive','discontinued'])],'sort'=>['sometimes',Rule::in(['name','item_code','item_type','status','created_at'])],'direction'=>['sometimes',Rule::in(['asc','desc'])]];}
 public function options():array{$v=$this->validated();return ['page'=>(int)($v['page']??1),'per_page'=>(int)($v['per_page']??20),'search'=>$v['search']??null,'item_type'=>$v['item_type']??null,'category_id'=>$v['category_id']??null,'status'=>$v['status']??'active','sort'=>$v['sort']??'name','direction'=>$v['direction']??'asc'];}
}
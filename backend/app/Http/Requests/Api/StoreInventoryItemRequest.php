<?php
namespace App\Http\Requests\Api;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
final class StoreInventoryItemRequest extends FormRequest {
 public function authorize():bool{return true;}
 public function rules():array{return ['name'=>['required','string','min:1','max:180'],'item_code'=>['required','string','min:1','max:80'],'item_type'=>['required',Rule::in(['equipment','consumable'])],'category_id'=>['nullable','uuid'],'unit'=>['required','string','max:40'],'description'=>['nullable','string','max:2000'],'minimum_stock'=>['sometimes','numeric','min:0','max:999999999']];}
}
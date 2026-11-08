<?php
namespace App\Http\Requests\Api;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
final class UpdateInventoryItemRequest extends FormRequest {
 public function authorize():bool{return true;}
 public function rules():array{return ['name'=>['sometimes','required','string','min:1','max:180'],'item_code'=>['sometimes','required','string','min:1','max:80'],'category_id'=>['sometimes','nullable','uuid'],'unit'=>['sometimes','required','string','max:40'],'description'=>['sometimes','nullable','string','max:2000'],'minimum_stock'=>['sometimes','numeric','min:0','max:999999999'],'status'=>['sometimes',Rule::in(['active','discontinued'])]];}
}
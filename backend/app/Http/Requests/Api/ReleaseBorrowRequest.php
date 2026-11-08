<?php
namespace App\Http\Requests\Api;
use Illuminate\Foundation\Http\FormRequest;
final class ReleaseBorrowRequest extends FormRequest {
 public function authorize():bool{return true;}
 public function rules():array{return ['remarks'=>['nullable','string','max:2000'],'items'=>['required','array','min:1','max:50'],'items.*.item_id'=>['required','uuid','distinct'],'items.*.quantity'=>['required','numeric','gt:0','max:1000000'],'items.*.location_id'=>['required','uuid'],'items.*.asset_ids'=>['sometimes','array','max:100'],'items.*.asset_ids.*'=>['uuid','distinct']];}
}
<?php
namespace App\Http\Requests\Api;
use Illuminate\Foundation\Http\FormRequest;
final class StoreBorrowRequest extends FormRequest {
 public function authorize():bool{return true;}
 public function rules():array{return ['expected_return_at'=>['required','date','after:now'],'reservation_id'=>['nullable','uuid'],'remarks'=>['nullable','string','max:2000'],'items'=>['required','array','min:1','max:50'],'items.*.item_id'=>['required','uuid','distinct'],'items.*.quantity'=>['required','numeric','gt:0','max:1000000'],'items.*.remarks'=>['nullable','string','max:1000']];}
}
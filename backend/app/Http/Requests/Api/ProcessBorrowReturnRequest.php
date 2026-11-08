<?php
namespace App\Http\Requests\Api;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
final class ProcessBorrowReturnRequest extends FormRequest {
 public function authorize():bool{return true;}
 public function rules():array{return ['remarks'=>['nullable','string','max:2000'],'items'=>['required','array','min:1','max:100'],'items.*.item_id'=>['required','uuid'],'items.*.asset_id'=>['nullable','uuid'],'items.*.quantity'=>['required','numeric','gt:0','max:1000000'],'items.*.condition'=>['required',Rule::in(['new','good','fair','poor','damaged','missing'])],'items.*.remarks'=>['nullable','string','max:1000']];}
}
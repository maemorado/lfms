<?php
namespace App\Http\Requests\Api;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
final class StoreInventoryMovementRequest extends FormRequest {
 public function authorize():bool{return true;}
 public function rules():array{return ['location_id'=>['required','uuid'],'movement_type'=>['required',Rule::in(['stock_in','stock_out','adjustment_in','adjustment_out'])],'quantity'=>['required','numeric','gt:0','max:999999999'],'remarks'=>['nullable','string','max:1000'],'reference_type'=>['nullable','string','max:80'],'reference_id'=>['nullable','uuid']];}
}
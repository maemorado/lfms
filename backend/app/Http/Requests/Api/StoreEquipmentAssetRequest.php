<?php
namespace App\Http\Requests\Api;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
final class StoreEquipmentAssetRequest extends FormRequest {
 public function authorize():bool{return true;}
 public function rules():array{return ['asset_tag'=>['required','string','min:1','max:100'],'serial_number'=>['nullable','string','max:160'],'condition'=>['sometimes',Rule::in(['new','good','fair','poor','damaged'])],'status'=>['sometimes',Rule::in(['available','borrowed','damaged','missing','under_repair','retired'])],'location_id'=>['nullable','uuid']];}
}
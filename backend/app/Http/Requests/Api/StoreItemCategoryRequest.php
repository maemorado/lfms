<?php
namespace App\Http\Requests\Api;
use Illuminate\Foundation\Http\FormRequest;
final class StoreItemCategoryRequest extends FormRequest {
 public function authorize():bool{return true;}
 public function rules():array{return ['name'=>['required','string','min:1','max:120'],'description'=>['nullable','string','max:1000']];}
}
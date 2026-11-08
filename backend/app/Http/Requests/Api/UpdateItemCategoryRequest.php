<?php
namespace App\Http\Requests\Api;
use Illuminate\Foundation\Http\FormRequest;
final class UpdateItemCategoryRequest extends FormRequest {
 public function authorize():bool{return true;}
 public function rules():array{return ['name'=>['sometimes','required','string','min:1','max:120'],'description'=>['sometimes','nullable','string','max:1000']];}
}
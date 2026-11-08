<?php
namespace App\Http\Requests\Api;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
final class ReviewBorrowRequest extends FormRequest {
 public function authorize():bool{return true;}
 public function rules():array{return ['decision'=>['required',Rule::in(['approved','rejected'])],'remarks'=>['nullable','required_if:decision,rejected','string','max:2000']];}
}
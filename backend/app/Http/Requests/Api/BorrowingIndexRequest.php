<?php
namespace App\Http\Requests\Api;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
final class BorrowingIndexRequest extends FormRequest {
 public function authorize():bool{return true;}
 public function rules():array{return ['page'=>['sometimes','integer','min:1'],'per_page'=>['sometimes','integer','min:1','max:100'],'status'=>['sometimes',Rule::in(['pending','approved','rejected','cancelled','partially_returned','returned'])],'search'=>['sometimes','string','max:120'],'overdue'=>['sometimes','boolean']];}
 public function options():array{$v=$this->validated();return ['page'=>(int)($v['page']??1),'per_page'=>(int)($v['per_page']??20),'status'=>$v['status']??null,'search'=>$v['search']??null,'overdue'=>(bool)($v['overdue']??false)];}
}
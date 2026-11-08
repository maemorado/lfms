<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\BorrowingIndexRequest;
use App\Http\Requests\Api\ProcessBorrowReturnRequest;
use App\Http\Requests\Api\ReleaseBorrowRequest;
use App\Http\Requests\Api\ReviewBorrowRequest;
use App\Http\Requests\Api\StoreBorrowRequest;
use App\Services\Borrowing\SupabaseBorrowingService;
use App\Services\Inventory\SupabaseInventoryService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
final class BorrowingController extends Controller {
 public function __construct(private readonly SupabaseBorrowingService $borrowing,private readonly SupabaseInventoryService $inventory){}
 private function token(Request $r):string{return (string)$r->bearerToken();}
 public function inventory(BorrowingIndexRequest $r):JsonResponse{
  $v=$this->inventory->listItems($this->token($r),['page'=>(int)$r->query('page',1),'per_page'=>(int)$r->query('per_page',20),'status'=>'active','sort'=>'name','direction'=>'asc','search'=>$r->query('search')]);
  $items=array_values(array_filter($v['items'],fn($i)=>($i['item_type']??'')==='equipment'?((int)($i['available_assets']??0)>0):((float)($i['quantity_on_hand']??0)>0)));
  return ApiResponse::success(['items'=>$items],'Borrowable inventory retrieved successfully.',200,['pagination'=>$v['pagination']]);
 }
 public function index(BorrowingIndexRequest $r):JsonResponse{$v=$this->borrowing->requests($this->token($r),$r->options());return ApiResponse::success(['requests'=>$v['requests']],'Borrowing requests retrieved successfully.',200,['pagination'=>$v['pagination']]);}
 public function store(StoreBorrowRequest $r):JsonResponse{return ApiResponse::success(['request'=>$this->borrowing->createRequest($this->token($r),$r->validated())],'Borrowing request submitted successfully.',201);}
 public function show(Request $r,string $requestId):JsonResponse{return ApiResponse::success(['request'=>$this->borrowing->requestDetail($this->token($r),$requestId)],'Borrowing request retrieved successfully.');}
 public function cancel(Request $r,string $requestId):JsonResponse{return ApiResponse::success(['request'=>$this->borrowing->cancelRequest($this->token($r),$requestId)],'Borrowing request cancelled successfully.');}
 public function review(ReviewBorrowRequest $r,string $requestId):JsonResponse{return ApiResponse::success(['request'=>$this->borrowing->reviewRequest($this->token($r),$requestId,$r->validated())],'Borrowing request reviewed successfully.');}
 public function release(ReleaseBorrowRequest $r,string $requestId):JsonResponse{return ApiResponse::success(['borrowing'=>$this->borrowing->releaseRequest($this->token($r),$requestId,$r->validated())],'Borrowing items released successfully.',201);}
 public function borrowings(BorrowingIndexRequest $r):JsonResponse{$v=$this->borrowing->borrowings($this->token($r),$r->options());return ApiResponse::success(['borrowings'=>$v['borrowings']],'Borrowing records retrieved successfully.',200,['pagination'=>$v['pagination']]);}
 public function overdue(BorrowingIndexRequest $r):JsonResponse{$o=$r->options();$o['overdue']=true;$v=$this->borrowing->borrowings($this->token($r),$o);return ApiResponse::success(['borrowings'=>$v['borrowings']],'Overdue borrowings retrieved successfully.',200,['pagination'=>$v['pagination']]);}
 public function borrowingDetail(Request $r,string $transactionId):JsonResponse{return ApiResponse::success(['borrowing'=>$this->borrowing->borrowingDetail($this->token($r),$transactionId)],'Borrowing details retrieved successfully.');}
 public function returns(Request $r,string $transactionId):JsonResponse{return ApiResponse::success(['returns'=>$this->borrowing->returns($this->token($r),$transactionId)],'Return history retrieved successfully.');}
 public function processReturn(ProcessBorrowReturnRequest $r,string $transactionId):JsonResponse{return ApiResponse::success(['return'=>$this->borrowing->processReturn($this->token($r),$transactionId,$r->validated())],'Borrowing return processed successfully.',201);}
}
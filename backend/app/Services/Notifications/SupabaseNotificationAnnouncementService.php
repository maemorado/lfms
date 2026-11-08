<?php

namespace App\Services\Notifications;

use App\Exceptions\NotificationAnnouncementException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class SupabaseNotificationAnnouncementService
{
    private function client(string $token)
    {
        $url = rtrim((string) config('supabase.url'), '/');
        $key = (string) config('supabase.publishable_key');
        if ($url === '' || $key === '' || $token === '') {
            throw new NotificationAnnouncementException('Notifications and announcements are not configured for this authenticated session.', 503);
        }

        return Http::acceptJson()
            ->withHeaders(['apikey' => $key, 'Authorization' => 'Bearer '.$token])
            ->timeout((int) config('supabase.auth_user_timeout', 10));
    }

    private function request(string $token, string $method, string $path, array $query = [], ?array $body = null, string $prefer = 'return=representation'): array
    {
        $url = rtrim((string) config('supabase.url'), '/').'/rest/v1/'.$path;
        if ($query !== []) $url .= '?'.http_build_query($query);

        $client = $this->client($token)->withHeaders(['Prefer' => $prefer]);
        $response = match (strtoupper($method)) {
            'GET' => $client->get($url),
            'POST' => $client->post($url, $body ?? []),
            'PATCH' => $client->patch($url, $body ?? []),
            default => throw new NotificationAnnouncementException('Unsupported notifications or announcements operation.', 405),
        };

        if (!$response->successful()) {
            $status = $response->status();
            $bodyText = strtolower($response->body());
            $message = match (true) {
                $status === 401 => 'Your session is no longer valid. Sign in again.',
                $status === 403 => 'Your account is not authorized to access this record.',
                $status === 404 => 'The requested record was not found.',
                $status === 409 => 'This record changed and conflicts with the requested operation.',
                str_contains($bodyText, 'row-level security') => 'The current account cannot modify this record under the existing access policy.',
                str_contains($bodyText, 'check constraint') => 'The submitted value is not supported by the existing LFMS data model.',
                $status >= 500 => 'The notifications and announcements service is temporarily unavailable.',
                default => 'The requested notifications or announcements operation could not be completed.',
            };
            throw new NotificationAnnouncementException($message, in_array($status, [401,403,404,409,422], true) ? $status : ($status === 400 ? 422 : 502));
        }

        return ['data' => $response->json() ?? [], 'range' => $response->header('Content-Range')];
    }

    private function pagination(?string $range, int $page, int $perPage): array
    {
        $total = 0;
        if (is_string($range) && str_contains($range, '/')) {
            $raw = substr($range, strrpos($range, '/') + 1);
            if (ctype_digit($raw)) $total = (int) $raw;
        }
        return ['current_page'=>$page,'per_page'=>$perPage,'total'=>$total,'last_page'=>max(1,(int)ceil($total/max(1,$perPage)))];
    }

    private function uuid(string $id): void
    {
        if (!Str::isUuid($id)) throw new NotificationAnnouncementException('The supplied record identifier is invalid.', 422);
    }

    private function one(array $rows, string $message): array
    {
        $row = $rows[0] ?? null;
        if (!is_array($row)) throw new NotificationAnnouncementException($message, 404);
        return $row;
    }

    public function notifications(string $token, string $userId, array $options): array
    {
        $query = [
            'select'=>'id,user_id,title,message,type,read_at,created_at,event_key,related_record_type,related_record_id,metadata',
            'user_id'=>'eq.'.$userId,
            'order'=>'created_at.desc',
            'limit'=>(string)$options['per_page'],
            'offset'=>(string)(($options['page']-1)*$options['per_page']),
        ];
        if ($options['type']) $query['type']='eq.'.$options['type'];
        if ($options['unread']) $query['read_at']='is.null';
        $result=$this->request($token,'GET','notifications',$query,null,'count=exact');
        return ['notifications'=>$result['data'],'pagination'=>$this->pagination($result['range']??null,$options['page'],$options['per_page'])];
    }

    public function notification(string $token, string $userId, string $id): array
    {
        $this->uuid($id);
        $rows=$this->request($token,'GET','notifications',[
            'select'=>'id,user_id,title,message,type,read_at,created_at,event_key,related_record_type,related_record_id,metadata',
            'id'=>'eq.'.$id,'user_id'=>'eq.'.$userId,'limit'=>'1',
        ])['data'];
        return $this->one($rows,'Notification not found.');
    }

    public function markNotificationRead(string $token, string $userId, string $id): array
    {
        $this->uuid($id);
        $rows=$this->request($token,'PATCH','notifications',[
            'id'=>'eq.'.$id,'user_id'=>'eq.'.$userId,'read_at'=>'is.null',
        ],['read_at'=>now()->toIso8601String()])['data'];
        if (is_array($rows) && $rows !== []) return $rows[0];
        return $this->notification($token,$userId,$id);
    }

    public function markAllNotificationsRead(string $token, string $userId): int
    {
        $rows=$this->request($token,'PATCH','notifications',[
            'user_id'=>'eq.'.$userId,'read_at'=>'is.null',
        ],['read_at'=>now()->toIso8601String()])['data'];
        return is_array($rows)?count($rows):0;
    }

    public function unreadCount(string $token, string $userId): int
    {
        $result=$this->request($token,'GET','notifications',[
            'select'=>'id','user_id'=>'eq.'.$userId,'read_at'=>'is.null','limit'=>'1',
        ],null,'count=exact');
        $range=$result['range']??'';
        if (is_string($range) && str_contains($range,'/')) {
            $raw=substr($range,strrpos($range,'/')+1);
            if (ctype_digit($raw)) return (int)$raw;
        }
        return 0;
    }

    public function dispatchOverdueBorrowing(string $token): int
    {
        $now=now()->toIso8601String();
        $requests=$this->request($token,'GET','borrow_requests',[
            'select'=>'id,borrower_id,expected_return_at,status,borrow_transactions!inner(id)',
            'status'=>'in.(approved,partially_returned)',
            'expected_return_at'=>'lt.'.$now,
            'order'=>'expected_return_at.asc',
            'limit'=>'500',
        ])['data'];

        $created=0;
        foreach ($requests as $request) {
            if (!is_string($request['id']??null) || !is_string($request['borrower_id']??null) || !is_string($request['expected_return_at']??null)) continue;
            $key='borrowing:overdue:'.$request['id'].':'.$request['expected_return_at'];
            $result=$this->request($token,'POST','notifications',[
                'on_conflict'=>'user_id,event_key',
            ],[
                'user_id'=>$request['borrower_id'],
                'title'=>'Borrowing overdue',
                'message'=>'A borrowed item is past its expected return date.',
                'type'=>'borrowing',
                'event_key'=>$key,
                'related_record_type'=>'borrowing',
                'related_record_id'=>$request['id'],
                'metadata'=>['event'=>'overdue','expected_return_at'=>$request['expected_return_at']],
            ],'resolution=ignore-duplicates,return=representation');
            if (is_array($result['data']) && $result['data'] !== []) $created++;
        }
        return $created;
    }

    public function announcements(string $token, array $options, bool $admin): array
    {
        $now=now()->toIso8601String();
        $query=[
            'select'=>'id,title,content,created_by,published_at,status,category,expires_at,created_at,updated_at',
            'order'=>'created_at.desc',
            'limit'=>(string)$options['per_page'],
            'offset'=>(string)(($options['page']-1)*$options['per_page']),
        ];
        if (!$admin) {
            $query['status']='eq.published';
            $query['published_at']='lte.'.$now;
            $query['or']='(expires_at.is.null,expires_at.gt.'.$now.')';
        } elseif ($options['status']) {
            $query['status']='eq.'.$options['status'];
        }
        if ($options['category']) $query['category']='eq.'.$options['category'];
        if ($options['search']) $query['title']='ilike.*'.str_replace(['%','_'],['\\%','\\_'],$options['search']).'*';
        $result=$this->request($token,'GET','announcements',$query,null,'count=exact');
        return ['announcements'=>$result['data'],'pagination'=>$this->pagination($result['range']??null,$options['page'],$options['per_page'])];
    }

    public function announcement(string $token, string $id, bool $admin): array
    {
        $this->uuid($id);
        $query=['select'=>'id,title,content,created_by,published_at,status,category,expires_at,created_at,updated_at','id'=>'eq.'.$id,'limit'=>'1'];
        if (!$admin) {
            $now=now()->toIso8601String();
            $query['status']='eq.published';
            $query['published_at']='lte.'.$now;
            $query['or']='(expires_at.is.null,expires_at.gt.'.$now.')';
        }
        $rows=$this->request($token,'GET','announcements',$query)['data'];
        return $this->one($rows,'Announcement not found.');
    }

    public function createAnnouncement(string $token, string $actorId, array $data): array
    {
        $rows=$this->request($token,'POST','announcements',[],[
            'created_by'=>$actorId,
            'title'=>trim($data['title']),
            'content'=>trim($data['content']),
            'category'=>$data['category']??'general',
            'expires_at'=>$data['expires_at']??null,
            'status'=>'draft',
        ])['data'];
        return $this->one($rows,'Announcement was not created.');
    }

    public function updateAnnouncement(string $token, string $id, array $data): array
    {
        $this->uuid($id);
        $payload=[];
        foreach (['title','content','category','expires_at'] as $field) {
            if (array_key_exists($field,$data)) $payload[$field]=in_array($field,['title','content'],true)?trim((string)$data[$field]):$data[$field];
        }
        if ($payload===[]) throw new NotificationAnnouncementException('At least one announcement field is required.',422);
        $rows=$this->request($token,'PATCH','announcements',['id'=>'eq.'.$id],$payload)['data'];
        return $this->one($rows,'Announcement could not be updated under the current announcement ownership policy.');
    }

    public function setAnnouncementState(string $token, string $id, string $state): array
    {
        $this->uuid($id);
        if (!in_array($state,['draft','published','archived'],true)) throw new NotificationAnnouncementException('Unsupported announcement state.',422);
        $current=$this->announcement($token,$id,true);
        $allowed=match($current['status']??null){
            'draft'=>['published','archived'],
            'published'=>['draft','archived'],
            default=>[],
        };
        if (!in_array($state,$allowed,true)) throw new NotificationAnnouncementException('That announcement state transition is not allowed.',409);
        if ($state==='published' && is_string($current['expires_at']??null) && strtotime($current['expires_at'])<=time()) {
            throw new NotificationAnnouncementException('An expired announcement cannot be published. Clear or update its expiration date first.',422);
        }
        $payload=['status'=>$state];
        if ($state==='published') $payload['published_at']=now()->toIso8601String();
        if ($state==='draft') $payload['published_at']=null;
        $rows=$this->request($token,'PATCH','announcements',['id'=>'eq.'.$id],$payload)['data'];
        return $this->one($rows,'Announcement could not be updated under the current announcement ownership policy.');
    }

    public function announcementHistory(string $token, string $id): array
    {
        $this->uuid($id);
        return $this->request($token,'GET','audit_logs',[
            'select'=>'id,user_id,table_name,record_id,action,old_values,new_values,created_at',
            'table_name'=>'eq.announcements','record_id'=>'eq.'.$id,
            'order'=>'created_at.desc','limit'=>'100',
        ])['data'];
    }
}

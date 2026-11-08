<?php

namespace Tests\Feature;

use App\Services\Notifications\SupabaseNotificationAnnouncementService;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

final class NotificationAnnouncementApiTest extends TestCase
{
    private const ADMIN='11111111-1111-4111-8111-111111111111';
    private const STAFF='22222222-2222-4222-8222-222222222222';
    private const ROLE='33333333-3333-4333-8333-333333333333';
    private const RECORD='55555555-5555-4555-8555-555555555555';

    protected function setUp(): void
    {
        parent::setUp();
        config(['supabase.url'=>'https://example.supabase.co','supabase.publishable_key'=>'test-publishable-key','supabase.auth_user_timeout'=>10]);
    }

    private function fakeUser(string $id,string $role,array $permissions): void
    {
        Http::fake([
            'https://example.supabase.co/auth/v1/user'=>Http::response(['id'=>$id,'email'=>'test@example.test'],200),
            'https://example.supabase.co/rest/v1/profiles*'=>Http::response([['id'=>$id,'full_name'=>'Test User','email'=>'test@example.test','status'=>'active']],200),
            'https://example.supabase.co/rest/v1/user_roles*'=>Http::response([['role_id'=>self::ROLE,'roles'=>['name'=>$role]]],200),
            'https://example.supabase.co/rest/v1/role_permissions*'=>Http::response(array_map(fn($p)=>['permissions'=>['name'=>$p]],$permissions),200),
        ]);
    }

    public function test_staff_can_only_request_their_own_notification_feed(): void
    {
        $this->fakeUser(self::STAFF,'staff_sa',['notifications.view_own']);
        $service=Mockery::mock(SupabaseNotificationAnnouncementService::class);
        $service->shouldReceive('notifications')->once()->with('staff-token',self::STAFF,Mockery::type('array'))
            ->andReturn(['notifications'=>[['id'=>self::RECORD,'user_id'=>self::STAFF,'title'=>'Notice','read_at'=>null]],'pagination'=>['current_page'=>1,'per_page'=>20,'total'=>1,'last_page'=>1]]);
        $this->app->instance(SupabaseNotificationAnnouncementService::class,$service);
        $this->withToken('staff-token')->getJson('/api/v1/notifications')
            ->assertOk()->assertJsonPath('data.notifications.0.user_id',self::STAFF);
    }

    public function test_staff_cannot_create_or_manage_announcements(): void
    {
        $this->fakeUser(self::STAFF,'staff_sa',['notifications.view_own']);
        $service=Mockery::mock(SupabaseNotificationAnnouncementService::class);
        $service->shouldNotReceive('createAnnouncement');
        $this->app->instance(SupabaseNotificationAnnouncementService::class,$service);
        $this->withToken('staff-token')->postJson('/api/v1/announcements',['title'=>'Safety notice','content'=>'Please take care.'])
            ->assertForbidden();
    }

    public function test_admin_creates_announcement_using_verified_identity(): void
    {
        $this->fakeUser(self::ADMIN,'admin',['notifications.view_own','notifications.manage']);
        $service=Mockery::mock(SupabaseNotificationAnnouncementService::class);
        $service->shouldReceive('createAnnouncement')->once()->with('admin-token',self::ADMIN,Mockery::on(fn($data)=>$data['title']==='Facility notice'&&!array_key_exists('created_by',$data)))
            ->andReturn(['id'=>self::RECORD,'title'=>'Facility notice','status'=>'draft','created_by'=>self::ADMIN]);
        $this->app->instance(SupabaseNotificationAnnouncementService::class,$service);
        $this->withToken('admin-token')->postJson('/api/v1/announcements',['title'=>'Facility notice','content'=>'The laboratory will be closed.'])
            ->assertCreated()->assertJsonPath('data.announcement.status','draft');
    }

    public function test_admin_can_publish_an_announcement(): void
    {
        $this->fakeUser(self::ADMIN,'admin',['notifications.view_own','notifications.manage']);
        $service=Mockery::mock(SupabaseNotificationAnnouncementService::class);
        $service->shouldReceive('setAnnouncementState')->once()->with('admin-token',self::RECORD,'published')
            ->andReturn(['id'=>self::RECORD,'status'=>'published']);
        $this->app->instance(SupabaseNotificationAnnouncementService::class,$service);
        $this->withToken('admin-token')->postJson('/api/v1/announcements/'.self::RECORD.'/publish')
            ->assertOk()->assertJsonPath('data.announcement.status','published');
    }

    public function test_staff_cannot_access_another_users_notification_by_identifier(): void
    {
        $this->fakeUser(self::STAFF,'staff_sa',['notifications.view_own']);
        $service=Mockery::mock(SupabaseNotificationAnnouncementService::class);
        $service->shouldReceive('notification')->once()->with('staff-token',self::STAFF,self::RECORD)->andThrow(new \App\Exceptions\NotificationAnnouncementException('Notification not found.',404));
        $this->app->instance(SupabaseNotificationAnnouncementService::class,$service);
        $this->withToken('staff-token')->getJson('/api/v1/notifications/'.self::RECORD)->assertNotFound();
    }

    public function test_staff_can_mark_only_their_own_notification_as_read(): void
    {
        $this->fakeUser(self::STAFF,'staff_sa',['notifications.view_own']);
        $service=Mockery::mock(SupabaseNotificationAnnouncementService::class);
        $service->shouldReceive('markNotificationRead')->once()->with('staff-token',self::STAFF,self::RECORD)
            ->andReturn(['id'=>self::RECORD,'user_id'=>self::STAFF,'read_at'=>'2026-11-08T10:00:00Z']);
        $this->app->instance(SupabaseNotificationAnnouncementService::class,$service);
        $this->withToken('staff-token')->patchJson('/api/v1/notifications/'.self::RECORD.'/read')
            ->assertOk()->assertJsonPath('data.notification.user_id',self::STAFF);
    }

    public function test_mark_all_read_uses_only_the_authenticated_user_id(): void
    {
        $this->fakeUser(self::STAFF,'staff_sa',['notifications.view_own']);
        $service=Mockery::mock(SupabaseNotificationAnnouncementService::class);
        $service->shouldReceive('markAllNotificationsRead')->once()->with('staff-token',self::STAFF)->andReturn(4);
        $this->app->instance(SupabaseNotificationAnnouncementService::class,$service);
        $this->withToken('staff-token')->postJson('/api/v1/notifications/read-all')
            ->assertOk()->assertJsonPath('data.updated',4);
    }
}

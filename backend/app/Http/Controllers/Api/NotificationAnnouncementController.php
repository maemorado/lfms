<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AnnouncementIndexRequest;
use App\Http\Requests\Api\NotificationIndexRequest;
use App\Http\Requests\Api\StoreAnnouncementRequest;
use App\Http\Requests\Api\UpdateAnnouncementRequest;
use App\Services\Notifications\NeonNotificationAnnouncementService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NotificationAnnouncementController extends Controller
{
    public function __construct(private readonly NeonNotificationAnnouncementService $service) {}

    private function isAdmin(Request $request): bool
    {
        $permissions=$request->attributes->get('lfms_authorization')['permissions']??[];
        return is_array($permissions) && in_array('notifications.manage',$permissions,true);
    }

    public function notifications(NotificationIndexRequest $request): JsonResponse
    {
        $result=$this->service->notifications($request->options());
        return ApiResponse::success(['notifications'=>$result['notifications']],'Notifications retrieved successfully.',200,['pagination'=>$result['pagination']]);
    }

    public function notification(Request $request,string $notificationId): JsonResponse
    {
        return ApiResponse::success(['notification'=>$this->service->notification($notificationId)],'Notification retrieved successfully.');
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return ApiResponse::success(['unread_count'=>$this->service->unreadCount()],'Unread notification count retrieved successfully.');
    }

    public function markRead(Request $request,string $notificationId): JsonResponse
    {
        return ApiResponse::success(['notification'=>$this->service->markNotificationRead($notificationId)],'Notification marked as read.');
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $count=$this->service->markAllNotificationsRead();
        return ApiResponse::success(['updated'=>$count],'All unread notifications were marked as read.');
    }

    public function dispatchOverdue(Request $request): JsonResponse
    {
        $count=$this->service->dispatchOverdueBorrowing();
        return ApiResponse::success(['created'=>$count],'Overdue borrowing notifications checked successfully.');
    }

    public function announcements(AnnouncementIndexRequest $request): JsonResponse
    {
        $result=$this->service->announcements($request->options(),$this->isAdmin($request));
        return ApiResponse::success(['announcements'=>$result['announcements']],'Announcements retrieved successfully.',200,['pagination'=>$result['pagination']]);
    }

    public function announcement(Request $request,string $announcementId): JsonResponse
    {
        return ApiResponse::success(['announcement'=>$this->service->announcement($announcementId,$this->isAdmin($request))],'Announcement retrieved successfully.');
    }

    public function createAnnouncement(StoreAnnouncementRequest $request): JsonResponse
    {
        return ApiResponse::success(['announcement'=>$this->service->createAnnouncement($request->validated())],'Announcement saved as draft.',201);
    }

    public function updateAnnouncement(UpdateAnnouncementRequest $request,string $announcementId): JsonResponse
    {
        return ApiResponse::success(['announcement'=>$this->service->updateAnnouncement($announcementId,$request->validated())],'Announcement updated successfully.');
    }

    public function publish(Request $request,string $announcementId): JsonResponse
    {
        return ApiResponse::success(['announcement'=>$this->service->setAnnouncementState($announcementId,'published')],'Announcement published successfully.');
    }

    public function unpublish(Request $request,string $announcementId): JsonResponse
    {
        return ApiResponse::success(['announcement'=>$this->service->setAnnouncementState($announcementId,'draft')],'Announcement unpublished and returned to draft.');
    }

    public function archive(Request $request,string $announcementId): JsonResponse
    {
        return ApiResponse::success(['announcement'=>$this->service->setAnnouncementState($announcementId,'archived')],'Announcement archived successfully.');
    }

    public function history(Request $request,string $announcementId): JsonResponse
    {
        return ApiResponse::success(['history'=>$this->service->announcementHistory($announcementId)],'Announcement history retrieved successfully.');
    }
}

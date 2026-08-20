<?php

namespace App\Services\Engagement;

use App\Enums\AnnouncementAudience;
use App\Enums\CertTierTargetMode;
use App\Enums\MediaSourceType;
use App\Enums\NotificationType;
use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Services\Notification\NotificationService;
use App\Support\Media\StoredFileName;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Agent-view IA item 1.6. Same "own company or platform default"
// forcing pattern as BadgeService::create().
//
// Human request (2026-07-23): "สามารถเพิ่มรูป และวิดีโอในประกาศได้" — image/
// video files live on the 'public' disk (same Storage::disk('public')
// pattern already used for avatar_path/background_path — an
// announcement's media is meant to be shown directly to every targeted
// Agent, not access-checked per-row like ProductMedia's private-disk
// files, so a direct public URL is the right fit here, not the
// private-disk + streaming-controller pattern).
class AnnouncementService
{
    private const DISK = 'public';

    public function __construct(private NotificationService $notifier) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor, ?UploadedFile $image = null, ?UploadedFile $video = null): Announcement
    {
        // 'image'/'video' are read via the $image/$video params above,
        // never as plain array values — FormRequest::validated() returns
        // the raw UploadedFile for any field that passed a 'file'/'image'
        // rule, and neither key is in Announcement::$fillable anyway, so
        // this is a defensive strip rather than something that would
        // otherwise silently corrupt a row.
        unset($data['image'], $data['video']);

        $data['company_id'] = $actor->isSuperAdmin() ? ($data['company_id'] ?? null) : $actor->company_id;
        $data['created_by'] = $actor->id;
        $data['published_at'] = $data['published_at'] ?? now();

        $this->applyImage($data, $image);
        $this->applyVideo($data, $video);

        $announcement = Announcement::create($data);

        // TASK-053 Phase 2b — push an in-app notification to every agent
        // this announcement targets, so it shows on their personal home
        // bell (not just when they happen to open the news feed).
        $this->notifyTargetAgents($announcement);

        return $announcement;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Announcement $announcement, array $data, ?UploadedFile $image = null, ?UploadedFile $video = null): Announcement
    {
        unset($data['image'], $data['video']);

        // remove_image/remove_video are explicit clear flags (never
        // inferred from an absent field — an update() call that simply
        // doesn't touch image/video at all must leave the existing one
        // alone, the same "only touch what's explicitly sent" contract
        // every other PATCH-shaped update in this codebase follows).
        if ($data['remove_image'] ?? false) {
            $this->deleteFile($announcement->image_path);
            $data['image_path'] = null;
        }
        unset($data['remove_image']);

        if ($data['remove_video'] ?? false) {
            $this->deleteFile($announcement->video_path);
            $data['video_source_type'] = null;
            $data['video_path'] = null;
            $data['video_embed_url'] = null;
        }
        unset($data['remove_video']);

        if ($image) {
            $this->deleteFile($announcement->image_path);
        }
        $this->applyImage($data, $image);

        if ($video || array_key_exists('video_embed_url', $data)) {
            $this->deleteFile($announcement->video_path);
        }
        $this->applyVideo($data, $video);

        $announcement->update($data);

        return $announcement;
    }

    public function delete(Announcement $announcement): void
    {
        $this->deleteFile($announcement->image_path);
        $this->deleteFile($announcement->video_path);

        $announcement->delete();
    }

    /**
     * Notify every agent this announcement targets. Skips future-dated
     * (scheduled) posts — an agent shouldn't be pinged before the post
     * is even visible in the feed (the feed itself hides not-yet-
     * published rows, so the two stay consistent).
     */
    private function notifyTargetAgents(Announcement $announcement): void
    {
        if ($announcement->published_at !== null && $announcement->published_at->isFuture()) {
            return;
        }

        foreach ($this->resolveTargetAgents($announcement) as $agent) {
            $this->notifier->notify(
                $agent,
                NotificationType::Announcement,
                $announcement->title,
                null,
                '/news',
                ['announcement_id' => $announcement->id],
            );
        }
    }

    /**
     * The set of agents an announcement is addressed to — mirrors the
     * exact audience rules AnnouncementController::index() filters the
     * feed by (all_agents, cert_tier exact, cert_tier and_above), scoped
     * to the announcement's company (or every company when it's a
     * platform-wide / company_id = null post).
     *
     * @return Collection<int, User>
     */
    private function resolveTargetAgents(Announcement $announcement): Collection
    {
        // Drop ONLY TenantScope (not SoftDeletes): this runs as the
        // authoring Admin but must reach every targeted agent, incl. all
        // companies for a platform-wide post — NotificationService still
        // stamps each row's company_id from the RECIPIENT, so nothing
        // leaks cross-tenant. Keeping the SoftDeletes scope means
        // deactivated (trashed) agents are correctly NOT notified.
        $query = User::withoutGlobalScope(TenantScope::class)->where('role', UserRole::Agent->value);

        if ($announcement->company_id !== null) {
            $query->where('company_id', $announcement->company_id);
        }

        if ($announcement->audience === AnnouncementAudience::CertTier) {
            $targetTierId = $announcement->target_cert_tier_id;

            if ($announcement->target_cert_tier_mode === CertTierTargetMode::AndAbove) {
                $targetSort = DB::table('cert_tiers')->where('id', $targetTierId)->value('sort_order');
                $query->whereIn('id', function ($sub) use ($targetSort) {
                    $sub->select('user_certifications.user_id')
                        ->from('user_certifications')
                        ->join('cert_tiers', 'cert_tiers.id', '=', 'user_certifications.cert_tier_id')
                        ->where('cert_tiers.sort_order', '>=', $targetSort);
                });
            } else {
                $query->whereIn('id', function ($sub) use ($targetTierId) {
                    $sub->select('user_id')
                        ->from('user_certifications')
                        ->where('cert_tier_id', $targetTierId);
                });
            }
        }

        return $query->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyImage(array &$data, ?UploadedFile $image): void
    {
        if (! $image) {
            return;
        }

        $data['image_path'] = $image->storeAs(
            'announcements/images',
            StoredFileName::random($image),
            self::DISK,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyVideo(array &$data, ?UploadedFile $video): void
    {
        $isEmbed = ($data['video_source_type'] ?? null) === MediaSourceType::Embed->value;

        if ($isEmbed) {
            // embed_url already sits in $data as 'video_embed_url' —
            // nothing to store; video_path stays null.
            $data['video_path'] = null;

            return;
        }

        if (! $video) {
            return;
        }

        $data['video_source_type'] = MediaSourceType::Upload->value;
        $data['video_embed_url'] = null;
        $data['video_path'] = $video->storeAs(
            'announcements/videos',
            StoredFileName::random($video),
            self::DISK,
        );
    }

    private function deleteFile(?string $path): void
    {
        if ($path) {
            Storage::disk(self::DISK)->delete($path);
        }
    }
}

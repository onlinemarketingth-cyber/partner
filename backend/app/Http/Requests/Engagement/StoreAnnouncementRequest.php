<?php

namespace App\Http\Requests\Engagement;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementBannerPage;
use App\Enums\CertTierTargetMode;
use App\Enums\MediaSourceType;
use App\Services\Catalog\VideoProcessingSettingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAnnouncementRequest extends FormRequest
{
    public function __construct(private readonly VideoProcessingSettingService $videoProcessingSettingService)
    {
        parent::__construct();
    }

    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\Announcement::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Human request (2026-07-23): "สามารถเพิ่มรูป และวิดีโอในประกาศได้" —
        // video is upload-OR-embed, same mutual-exclusion shape as
        // StoreProductSalesMaterialRequest (ADR-007). image has no
        // source_type — an announcement image is always a direct upload.
        $isVideoEmbed = $this->input('video_source_type') === MediaSourceType::Embed->value;
        $isVideoUpload = $this->input('video_source_type') === MediaSourceType::Upload->value;

        // Video size cap reuses the SAME admin-configurable
        // video_processing_settings value every other video-upload
        // surface in this codebase reads (BR-7 — never a new hardcoded
        // number). A platform-wide post (Super Admin, no company_id) has
        // no company to look up an override for, so it falls back to the
        // platform default the same way VideoProcessingSettingService
        // itself does for a company with no override row.
        $companyId = $this->user()->isSuperAdmin() ? $this->input('company_id') : $this->user()->company_id;
        $maxUploadMb = $companyId
            ? $this->videoProcessingSettingService->forCompany((int) $companyId)['max_upload_mb']
            : config('media.video.max_upload_mb');

        return [
            'company_id' => [
                Rule::prohibitedIf(fn () => ! $this->user()->isSuperAdmin()),
                'nullable',
                'integer',
                Rule::exists('companies', 'id'),
            ],
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:5000'],
            'audience' => ['required', Rule::in(array_column(AnnouncementAudience::cases(), 'value'))],
            'target_cert_tier_id' => ['required_if:audience,cert_tier', 'nullable', 'integer', Rule::exists('cert_tiers', 'id')],
            'target_cert_tier_mode' => ['nullable', Rule::in(array_column(CertTierTargetMode::cases(), 'value'))],
            'is_pinned' => ['nullable', 'boolean'],
            // TASK-080 — display switches. Both optional and independent:
            // the DB defaults (modal on, banner off) reproduce the
            // pre-TASK-080 behaviour when a client omits them.
            // `banner_pages` is only meaningful when show_as_banner is on,
            // but it is NOT prohibited otherwise — an admin toggling the
            // banner off shouldn't lose the page selection they had.
            'show_as_modal' => ['nullable', 'boolean'],
            'show_as_banner' => ['nullable', 'boolean'],
            'banner_pages' => ['nullable', 'array'],
            'banner_pages.*' => [Rule::enum(AnnouncementBannerPage::class)],
            'published_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:published_at'],
            'image' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'video_source_type' => ['sometimes', 'nullable', Rule::enum(MediaSourceType::class)],
            'video' => [
                Rule::requiredIf($isVideoUpload),
                Rule::prohibitedIf(! $isVideoUpload),
                'file', 'max:'.($maxUploadMb * 1024),
                'mimes:'.implode(',', config('media.video.allowed_mimes')),
            ],
            'video_embed_url' => [
                Rule::requiredIf($isVideoEmbed),
                Rule::prohibitedIf(! $isVideoEmbed),
                'url', 'max:2048',
            ],
        ];
    }
}

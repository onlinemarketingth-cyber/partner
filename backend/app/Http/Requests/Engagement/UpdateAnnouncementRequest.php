<?php

namespace App\Http\Requests\Engagement;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementBannerPage;
use App\Enums\CertTierTargetMode;
use App\Enums\MediaSourceType;
use App\Services\Catalog\VideoProcessingSettingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAnnouncementRequest extends FormRequest
{
    public function __construct(private readonly VideoProcessingSettingService $videoProcessingSettingService)
    {
        parent::__construct();
    }

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('announcement'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // See StoreAnnouncementRequest for the full reasoning — identical
        // upload-OR-embed shape + BR-7 config-driven size cap, just
        // 'sometimes' throughout since this is an update.
        $isVideoEmbed = $this->input('video_source_type') === MediaSourceType::Embed->value;
        $isVideoUpload = $this->input('video_source_type') === MediaSourceType::Upload->value;

        $announcement = $this->route('announcement');
        $companyId = $this->user()->isSuperAdmin()
            ? ($this->input('company_id') ?? $announcement?->company_id)
            : $this->user()->company_id;
        $maxUploadMb = $companyId
            ? $this->videoProcessingSettingService->forCompany((int) $companyId)['max_upload_mb']
            : config('media.video.max_upload_mb');

        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'content' => ['sometimes', 'string', 'max:5000'],
            'audience' => ['sometimes', Rule::in(array_column(AnnouncementAudience::cases(), 'value'))],
            'target_cert_tier_id' => ['required_if:audience,cert_tier', 'nullable', 'integer', Rule::exists('cert_tiers', 'id')],
            'target_cert_tier_mode' => ['nullable', Rule::in(array_column(CertTierTargetMode::cases(), 'value'))],
            'is_pinned' => ['nullable', 'boolean'],
            // TASK-080 — mirrors StoreAnnouncementRequest. See its comment
            // for why banner_pages is not prohibited when the banner is off.
            'show_as_modal' => ['nullable', 'boolean'],
            'show_as_banner' => ['nullable', 'boolean'],
            'banner_pages' => ['nullable', 'array'],
            'banner_pages.*' => [Rule::enum(AnnouncementBannerPage::class)],
            'published_at' => ['sometimes', 'date'],
            'expires_at' => ['nullable', 'date', 'after:published_at'],
            'image' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_image' => ['sometimes', 'boolean'],
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
            'remove_video' => ['sometimes', 'boolean'],
        ];
    }
}

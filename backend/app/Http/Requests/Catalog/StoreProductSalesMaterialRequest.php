<?php

namespace App\Http\Requests\Catalog;

use App\Enums\MediaSourceType;
use App\Services\Catalog\VideoProcessingSettingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Only Company Admin (own company) / Super Admin manage the catalog —
// reuses ProductPolicy::update rather than a new Policy class (see
// ProductSalesMaterialController's own comment on why no dedicated
// Policy exists for this model).
//
// ADR-007 — gained video (upload or embed). source_type defaults to
// 'upload' when omitted (pdf/image callers never need to change) — only
// a video-embed submission sends source_type=embed + embed_url instead
// of a file.
class StoreProductSalesMaterialRequest extends FormRequest
{
    public function __construct(private readonly VideoProcessingSettingService $videoProcessingSettingService)
    {
        parent::__construct();
    }

    public function authorize(): bool
    {
        $product = $this->route('product');

        return $product !== null && $this->user()->can('update', $product);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isEmbed = $this->input('source_type') === MediaSourceType::Embed->value;
        $product = $this->route('product');
        $maxUploadMb = $product ? $this->videoProcessingSettingService->forCompany($product->company_id)['max_upload_mb'] : 200;
        // The largest of the video cap and the original 15MB pdf/image
        // cap — a single 'file' field serves both, and the mimes rule
        // below is what actually distinguishes an oversized image from
        // an oversized video, not this max.
        $fileMaxKb = max($maxUploadMb * 1024, 15360);

        return [
            'material_group' => ['nullable', 'string', 'max:255'],
            'source_type' => ['sometimes', Rule::enum(MediaSourceType::class)],
            'file' => [
                Rule::requiredIf(fn () => ! $isEmbed),
                Rule::prohibitedIf(fn () => $isEmbed),
                'file', 'max:'.$fileMaxKb,
                'mimes:pdf,jpg,jpeg,png,webp,'.implode(',', config('media.video.allowed_mimes')),
            ],
            'embed_url' => [
                Rule::requiredIf(fn () => $isEmbed),
                Rule::prohibitedIf(fn () => ! $isEmbed),
                'url', 'max:2048',
            ],
        ];
    }
}

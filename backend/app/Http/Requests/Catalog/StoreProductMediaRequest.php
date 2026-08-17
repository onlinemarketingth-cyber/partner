<?php

namespace App\Http\Requests\Catalog;

use App\Enums\MediaSourceType;
use App\Enums\ProductMediaPurpose;
use App\Enums\ProductMediaType;
use App\Services\Catalog\VideoProcessingSettingService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ADR-007 — Company Admin (own company)/Super Admin manage the catalog,
// same as StoreProductSalesMaterialRequest (reuses ProductPolicy::update,
// no dedicated Policy class for this model either).
class StoreProductMediaRequest extends FormRequest
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
        $isVideo = $this->input('media_type') === ProductMediaType::Video->value;
        $isEmbed = $this->input('source_type') === MediaSourceType::Embed->value;

        // ADR-007/BR-7 — max upload size is the COMPANY's configured
        // limit (video_processing_settings), never the platform default
        // alone; VideoProcessingSettingService::forCompany() already
        // falls back to config/media.php when the company has no override.
        $product = $this->route('product');
        $maxUploadMb = $isVideo && $product
            ? $this->videoProcessingSettingService->forCompany($product->company_id)['max_upload_mb']
            : null;

        return [
            'media_type' => ['required', Rule::enum(ProductMediaType::class)],
            // Images are always an upload in this system (no "embed an
            // image" use case was requested) — source_type only matters,
            // and is only accepted, for a video.
            'source_type' => [
                Rule::requiredIf(fn () => $isVideo),
                Rule::prohibitedIf(fn () => ! $isVideo),
                Rule::enum(MediaSourceType::class),
            ],
            'file' => [
                Rule::requiredIf(fn () => ! $isVideo || ! $isEmbed),
                Rule::prohibitedIf(fn () => $isVideo && $isEmbed),
                'file',
                $isVideo ? 'mimes:'.implode(',', config('media.video.allowed_mimes')) : 'mimes:jpg,jpeg,png,webp',
                'max:'.($isVideo ? $maxUploadMb * 1024 : 15360),
            ],
            'embed_url' => [
                Rule::requiredIf(fn () => $isVideo && $isEmbed),
                Rule::prohibitedIf(fn () => ! ($isVideo && $isEmbed)),
                'url', 'max:2048',
            ],
            // TASK-097 — which gallery this belongs to. Optional and
            // defaulting to 'detail' at the DB level, so every existing
            // caller keeps working untouched.
            'purpose' => ['sometimes', Rule::enum(ProductMediaPurpose::class)],
            'is_primary' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * TASK-097 — a cover ("รูปสินค้า") is a product PHOTO, never a video
     * or a YouTube embed.
     *
     * This is a cross-field rule, so it can't live in the rules array
     * without duplicating the media_type/source_type reads. Enforcing it
     * here rather than only in the UI matters because the cover is what
     * ProductResource::thumbnail_url hands to the storefront card: a
     * video cover would render as a blank placeholder for every agent
     * and every customer who opens a share link, with nothing on screen
     * to explain why.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('purpose') !== ProductMediaPurpose::Cover->value) {
                return;
            }

            if ($this->input('media_type') !== ProductMediaType::Image->value) {
                $validator->errors()->add('purpose', 'รูปสินค้า (หน้าปก) ต้องเป็นไฟล์รูปภาพเท่านั้น — วิดีโอและลิงก์ฝังให้ใส่ในรายละเอียดสินค้าแทน');
            }
        });
    }
}

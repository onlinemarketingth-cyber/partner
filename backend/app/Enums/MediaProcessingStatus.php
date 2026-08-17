<?php

namespace App\Enums;

// ADR-007 — only meaningful for an UPLOADED video (MediaSourceType::Upload
// + a video media/content type). Null everywhere else (images, embeds,
// pdf/link/quiz modules). Set by App\Jobs\CompressUploadedVideo.
enum MediaProcessingStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
}

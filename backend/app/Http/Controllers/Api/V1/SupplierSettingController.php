<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Supplier\SupplierPlatformSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 2026-09-27 — ADR-048. Platform-wide supplier settings. Super Admin only.
 *
 * `/supplier-settings`, hyphenated: RestrictScopedRole opens the `supplier`
 * segment to the partner role and matches whole segments, so this stays
 * closed to suppliers — the setting decides when THEIR money is released.
 */
class SupplierSettingController extends Controller
{
    public function show(Request $request, SupplierPlatformSettingService $settings): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        return response()->json(['data' => $settings->get()]);
    }

    public function update(Request $request, SupplierPlatformSettingService $settings): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $validated = $request->validate([
            // At least one day: 0 would release on the supplier's own
            // "shipped" click, which is exactly what ADR-048 took away.
            'auto_receive_days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        $settings->update((int) $validated['auto_receive_days'], $request->user());

        return response()->json(['data' => $settings->get()]);
    }
}

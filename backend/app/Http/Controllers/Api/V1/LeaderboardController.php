<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Gamification\LevelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

// BR-5 — standalone aggregate endpoint, not tied to any model CRUD, so
// there's no Policy/Resource pair here (unlike every other controller
// in this codebase) — just company-scoped read access enforced inline.
// "Level" (Phase 9) is now included per row via LevelService, which
// reads the Admin-configured level_thresholds table (BR-7) — never a
// hardcoded formula. If no thresholds are configured, LevelService
// returns level_number 0 rather than throwing.
class LeaderboardController extends Controller
{
    public function index(Request $request, LevelService $levelService): JsonResponse
    {
        $user = $request->user();

        if ($user->isSuperAdmin()) {
            Validator::make($request->all(), [
                'company_id' => ['required', 'integer', 'exists:companies,id'],
            ])->validate();

            $companyId = (int) $request->query('company_id');
        } else {
            $companyId = $user->company_id;
        }

        $agents = User::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('role', UserRole::Agent)
            ->withSum('xpLedger as total_xp', 'xp_awarded')
            ->orderByDesc('total_xp')
            ->orderBy('id')
            ->get();

        $ranked = $agents->values()->map(function (User $agent, int $index) use ($levelService) {
            $totalXp = (int) ($agent->total_xp ?? 0);
            $level = $levelService->currentLevelForTotalXp($totalXp);

            return [
                'rank' => $index + 1,
                'user' => [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    // Same "public disk, non-sensitive image" reasoning
                    // as UserResource — every agent's own avatar (not
                    // just the viewer's) is fine to show here.
                    'avatar_url' => $agent->avatar_path ? Storage::disk('public')->url($agent->avatar_path) : null,
                ],
                'total_xp' => $totalXp,
                'level_number' => $level['level_number'],
                'next_level_xp_required' => $level['next_level_xp_required'],
            ];
        });

        return response()->json([
            'data' => $ranked,
            'meta' => ['company_id' => $companyId],
        ]);
    }
}

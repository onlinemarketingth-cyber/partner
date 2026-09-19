<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Money\SupportedCurrency;
use Illuminate\Http\JsonResponse;

/**
 * The currencies a tenant may be denominated in.
 *
 * A closed vocabulary served from the server rather than hardcoded in the
 * admin console, for the same reason every other enum on this system is:
 * two copies of a list are two lists, and the one in the browser is the one
 * that keeps a removed option on screen until somebody redeploys.
 *
 * The list is restricted to hundredth-based currencies because BR-3 stores
 * satang and every formatter divides by 100 — see
 * App\Support\Money\SupportedCurrency, which states that at length. This
 * endpoint exists so a picker cannot offer anything outside it.
 *
 * Readable by any authenticated user: it is a static reference list with no
 * tenant data in it, and both the admin console (the picker) and the agent
 * portal (formatting) have reason to read it.
 */
class CurrencyController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => SupportedCurrency::options(),
            // What a company gets when nobody chooses — stated rather than
            // assumed by the caller, so the form can preselect it without
            // hardcoding 'THB'.
            'default' => SupportedCurrency::DEFAULT,
        ]);
    }
}

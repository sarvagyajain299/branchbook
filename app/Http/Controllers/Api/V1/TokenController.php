<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class TokenController extends Controller
{
    /** A real bcrypt hash of a random string, checked when the email is unknown so both paths take equal time. */
    private const DUMMY_HASH = '$2y$12$gSEnNkH69nZVaKaJyold/uwGetN32ongc5g4xkfFb2D7tRrxuiSeq';

    /** POST /auth/tokens {email, password, device_name} -> a token limited to the role's abilities. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:60'],
        ]);

        $user = User::query()->where('email', mb_strtolower($data['email']))->first();

        // Always run one hash check, even for an unknown email, so response time doesn't reveal
        // which emails exist.
        $valid = Hash::check($data['password'], $user->password ?? self::DUMMY_HASH);

        if ($user === null || ! $valid || $user->email_verified_at === null) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        $abilities = $user->role->apiAbilities();
        $token = $user->createToken($data['device_name'], $abilities, now()->addHours(12));

        Audit::record('api.token_created', $user, ['device' => $data['device_name'], 'abilities' => $abilities], $user);

        return response()->json([
            'token' => $token->plainTextToken,
            'abilities' => $abilities,
            'expires_at' => now()->addHours(12)->toIso8601ZuluString(),
            'user' => ['id' => $user->id, 'name' => $user->name, 'role' => $user->role->value, 'branch_id' => $user->branch_id],
        ], 201);
    }

    /** DELETE /auth/tokens/current: log out this token. */
    public function destroy(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        // Sanctum::actingAs() in tests supplies a transient token that has no delete().
        if (method_exists($token, 'delete')) { // @phpstan-ignore function.alreadyNarrowedType (true for real tokens, false for the test double)
            $token->delete();
        }

        return response()->json(['message' => 'Token revoked.']);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\OtpThrottledException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\PasswordForgotRequest;
use App\Http\Requests\Auth\PasswordResetRequest;
use App\Http\Requests\Auth\RegisterStartRequest;
use App\Http\Requests\Auth\RegisterVerifyRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthService;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const REGISTER_PURPOSE = 'register';

    private const RESET_PURPOSE = 'password_reset';

    public function __construct(
        private readonly OtpService $otp,
        private readonly AuthService $auth,
    ) {}

    public function registerStart(RegisterStartRequest $request): JsonResponse
    {
        $data = $request->validated();

        $this->otp->sendCode($data['phone'], self::REGISTER_PURPOSE);

        return response()->json(['message' => 'Verification code sent.']);
    }

    public function registerVerify(RegisterVerifyRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (! $this->otp->verifyCode($data['phone'], self::REGISTER_PURPOSE, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'The verification code is invalid or expired.']);
        }

        $user = $this->auth->registerClient($data['phone'], $data['name'], $data['password']);

        return response()->json([
            'token' => $this->auth->issueToken($user),
            'user' => new UserResource($user),
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::where('phone', $data['phone'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['phone' => 'These credentials do not match our records.']);
        }

        abort_if($user->is_banned, 403, 'This account is banned.');
        abort_if($user->phone_verified_at === null, 403, 'Phone number is not verified.');

        return response()->json([
            'token' => $this->auth->issueToken($user),
            'user' => new UserResource($user),
        ]);
    }

    public function passwordForgot(PasswordForgotRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Only send if the account exists, but always return the same response (no enumeration).
        // Swallow throttling too — a 429 for existing accounts only would leak existence.
        if (User::where('phone', $data['phone'])->exists()) {
            try {
                $this->otp->sendCode($data['phone'], self::RESET_PURPOSE);
            } catch (OtpThrottledException) {
                // no-op: keep the response identical to the unknown-phone case
            }
        }

        return response()->json(['message' => 'If the account exists, a reset code was sent.']);
    }

    public function passwordReset(PasswordResetRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (! $this->otp->verifyCode($data['phone'], self::RESET_PURPOSE, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'The verification code is invalid or expired.']);
        }

        $user = User::where('phone', $data['phone'])->firstOrFail();
        $user->password = (string) $data['password'];
        $user->save();
        $user->tokens()->delete(); // revoke all sessions on password change

        return response()->json(['message' => 'Password reset. Please log in.']);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}

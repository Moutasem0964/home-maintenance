<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\OtpThrottledException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\PasswordForgotRequest;
use App\Http\Requests\Auth\PasswordResetRequest;
use App\Http\Requests\Auth\PasswordVerifyRequest;
use App\Http\Requests\Auth\RegisterClientRequest;
use App\Http\Requests\Auth\RegisterStartRequest;
use App\Http\Requests\Auth\RegisterVerifyRequest;
use App\Http\Requests\Auth\TechnicianRegisterRequest;
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
        private readonly OtpService $otpService,
        private readonly AuthService $authService,
    ) {}

    public function registerStart(RegisterStartRequest $request): JsonResponse
    {
        $data = $request->validated();

        $code = $this->otpService->sendCode($data['phone'], self::REGISTER_PURPOSE);

        $payload = ['message' => 'Verification code sent.'];

        if (config('otp.expose_code')) {
            $payload['debug_code'] = $code;
        }

        return response()->json($payload);
    }

    /**
     * Step 2 of registration: verify the OTP only. On success the code is consumed and a
     * single-use ticket is returned, which register/client or register/technician redeems.
     */
    public function registerVerify(RegisterVerifyRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (! $this->otpService->verifyCode($data['phone'], self::REGISTER_PURPOSE, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'The verification code is invalid or expired.']);
        }

        return response()->json([
            'message' => 'Phone number verified.',
            'ticket' => $this->otpService->issueTicket($data['phone'], self::REGISTER_PURPOSE),
        ]);
    }

    /** Step 3 (client): create the account, redeeming the verification ticket. */
    public function registerClient(RegisterClientRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (! $this->otpService->consumeTicket($data['phone'], self::REGISTER_PURPOSE, $data['ticket'])) {
            throw ValidationException::withMessages(['ticket' => 'Phone verification is required or has expired.']);
        }

        $user = $this->authService->registerClient($data['phone'], $data['name'], $data['password']);

        return response()->json([
            'token' => $this->authService->issueToken($user),
            'user' => new UserResource($user),
        ], 201);
    }

    /** Step 3 (technician): create the account, redeeming the verification ticket. */
    public function registerTechnician(TechnicianRegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (! $this->otpService->consumeTicket($data['phone'], self::REGISTER_PURPOSE, $data['ticket'])) {
            throw ValidationException::withMessages(['ticket' => 'Phone verification is required or has expired.']);
        }

        // Store the KYC files on the PRIVATE disk (never public URLs), keep the paths.
        $documents = [
            'id_front_url' => $request->file('id_front')->store('technician/kyc', 'local'),
            'id_back_url' => $request->file('id_back')->store('technician/kyc', 'local'),
            'selfie_url' => $request->file('selfie')->store('technician/kyc', 'local'),
        ];

        $user = $this->authService->registerTechnician($data['phone'], $data['name'], $data['password'], $documents);

        return response()->json([
            'token' => $this->authService->issueToken($user),
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
            'token' => $this->authService->issueToken($user),
            'user' => new UserResource($user),
        ]);
    }

    public function passwordForgot(PasswordForgotRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Only send if the account exists, but always return the same response (no enumeration).
        // Swallow throttling too — a 429 for existing accounts only would leak existence.
        $code = null;

        if (User::where('phone', $data['phone'])->exists()) {
            try {
                $code = $this->otpService->sendCode($data['phone'], self::RESET_PURPOSE);
            } catch (OtpThrottledException) {
                // no-op: keep the response identical to the unknown-phone case
            }
        }

        $payload = ['message' => 'If the account exists, a reset code was sent.'];

        if (config('otp.expose_code') && $code !== null) {
            $payload['debug_code'] = $code;
        }

        return response()->json($payload);
    }

    /** Step 2 of reset: verify the OTP only, returning a single-use ticket for password/reset. */
    public function passwordVerify(PasswordVerifyRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (! $this->otpService->verifyCode($data['phone'], self::RESET_PURPOSE, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'The verification code is invalid or expired.']);
        }

        return response()->json([
            'message' => 'Verification successful.',
            'ticket' => $this->otpService->issueTicket($data['phone'], self::RESET_PURPOSE),
        ]);
    }

    /** Step 3 of reset: set the new password, redeeming the verification ticket. */
    public function passwordReset(PasswordResetRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (! $this->otpService->consumeTicket($data['phone'], self::RESET_PURPOSE, $data['ticket'])) {
            throw ValidationException::withMessages(['ticket' => 'Phone verification is required or has expired.']);
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

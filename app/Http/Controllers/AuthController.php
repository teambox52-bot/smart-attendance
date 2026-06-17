<?php

namespace App\Http\Controllers;

use App\Mail\PasswordResetOtpMail;
use Illuminate\Http\Request;
use App\Models\Enrollment;
use App\Models\PasswordResetOtp;
use App\Models\User;
use App\Support\AcademicLevel;
use App\Support\AcademicMajor;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class AuthController extends Controller
{
    // Register
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:6|confirmed',
            'role' => 'required|in:student,doctor',

            'university_code' => 'required_if:role,student|nullable|string|unique:users,university_code',
            'major' => 'required_if:role,student|nullable|string',
            'level' => 'required_if:role,student|nullable|integer',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role,
            'university_code' => $request->role === 'student' ? $request->university_code : null,
            'major' => $request->role === 'student' ? $request->major : null,
            'level' => $request->role === 'student' ? $request->level : null,
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Registered successfully',
            'token' => $token,
            'user' => $user
        ], 201);
    }

    // Login
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials'
            ], 401);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'token' => $token,
            'user' => $user
        ]);
    }

    // Logout
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully'
        ]);
    }

    // Current user
    public function me(Request $request)
    {
        return response()->json($request->user());
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        if (!in_array($user->role, ['student', 'doctor'], true)) {
            return response()->json([
                'message' => 'Only students and doctors can update profiles'
            ], 403);
        }

        $rules = [
            'role' => 'prohibited',
            'university_code' => 'prohibited',
            'name' => 'sometimes|required|string|max:255',
            'email' => [
                'sometimes',
                'required',
                'email',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
        ];

        if ($user->role === 'student') {
            $rules['major'] = 'sometimes|nullable|string|max:255';
            $rules['level'] = 'sometimes|nullable|integer|min:1|max:10';
        } else {
            $rules['major'] = 'prohibited';
            $rules['level'] = 'prohibited';
        }

        $validated = $request->validate($rules);

        $previousLevel = $user->level;

        $user->fill($validated);
        $user->save();

        if (
            $user->role === 'student'
            && (
                (array_key_exists('level', $validated) && (string) $previousLevel !== (string) $user->level)
                || array_key_exists('major', $validated)
            )
        ) {
            $this->removeEnrollmentsOutsideCurrentAudience($user);
        }

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $this->profilePayload($user->fresh()),
        ]);
    }

    public function changePassword(Request $request)
    {
        $user = $request->user();

        if (!in_array($user->role, ['student', 'doctor'], true)) {
            return response()->json([
                'message' => 'Only students and doctors can change passwords'
            ], 403);
        }

        $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ]);

        if (!Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        return response()->json([
            'message' => 'Password changed successfully'
        ]);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $email = strtolower(trim($request->email));
        $user = User::where('email', $email)->first();

        if ($user) {
            $otp = (string) random_int(100000, 999999);

            PasswordResetOtp::create([
                'email' => $email,
                'otp_hash' => Hash::make($otp),
                'expires_at' => now()->addMinutes((int) env('RESET_PASSWORD_OTP_EXPIRES_MINUTES', 10)),
            ]);

            try {
                Mail::to($email)->send(new PasswordResetOtpMail($otp));
            } catch (Throwable) {
                return response()->json([
                    'message' => 'Unable to send reset code. Please try again later.',
                ], 503);
            }
        }

        return response()->json([
            'message' => 'If this email exists, a reset code has been sent.'
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp' => 'required|string|size:6',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $email = strtolower(trim($request->email));
        $user = User::where('email', $email)->first();

        if (!$user) {
            throw ValidationException::withMessages([
                'otp' => ['Invalid or expired reset code.'],
            ]);
        }

        $resetOtp = PasswordResetOtp::where('email', $email)
            ->whereNull('used_at')
            ->latest()
            ->first();

        if (
            !$resetOtp ||
            $resetOtp->expires_at->isPast() ||
            !Hash::check($request->otp, $resetOtp->otp_hash)
        ) {
            throw ValidationException::withMessages([
                'otp' => ['Invalid or expired reset code.'],
            ]);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        $resetOtp->used_at = now();
        $resetOtp->save();
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Password reset successfully. You can now sign in.'
        ]);
    }

    private function profilePayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'university_code' => $user->university_code,
            'major' => $user->major,
            'level' => $user->level,
            'face_enrolled' => !empty($user->face_token),
        ];
    }

    private function removeEnrollmentsOutsideCurrentAudience(User $user): void
    {
        Enrollment::with('course')
            ->where('user_id', $user->id)
            ->get()
            ->filter(function (Enrollment $enrollment) use ($user) {
                $course = $enrollment->course;

                if (!$course) {
                    return true;
                }

                return !AcademicLevel::matches($course->level, $user->level)
                    || !AcademicMajor::matches($course->semester, $user->major);
            })
            ->each(fn (Enrollment $enrollment) => $enrollment->delete());
    }
}

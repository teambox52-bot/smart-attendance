<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesDoctorResources;
use App\Http\Controllers\Concerns\HandlesGroupedSessions;
use App\Models\User;
use Illuminate\Http\Request;
use App\Services\FacePlusPlusService;
use App\Models\AttendanceRecord;
use App\Models\Enrollment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class FaceController extends Controller
{
    use AuthorizesDoctorResources;
    use HandlesGroupedSessions;

    private function studentPayload(User $student, ?AttendanceRecord $attendance = null): array
    {
        return [
            'id' => $student->id,
            'full_name' => $student->name,
            'name' => $student->name,
            'email' => $student->email,
            'university_code' => $student->university_code,
            'major' => $student->major,
            'level' => $student->level,
            'status' => $attendance?->status,
            'attended_at' => $attendance?->attended_at,
            'method' => $attendance?->method,
            'match_score' => $attendance?->match_score,
            'face_enrolled' => !empty($student->face_token),
        ];
    }

    private function attendancePayload(AttendanceRecord $attendance): array
    {
        return [
            'id' => $attendance->id,
            'session_id' => $attendance->attendance_session_id,
            'method' => $attendance->method,
            'status' => $attendance->status,
            'match_score' => $attendance->match_score,
            'attended_at' => $attendance->attended_at,
        ];
    }

    private function faceServiceIsConfigured(): bool
    {
        return filled(env('FACEPP_API_KEY'))
            && filled(env('FACEPP_API_SECRET'))
            && filled(env('FACEPP_BASE_URL'));
    }

    private function faceServiceUnavailableResponse()
    {
        return response()->json([
            'message' => 'Face verification service is not configured'
        ], 503);
    }

    private function safeProviderDiagnostic(array $result): array
    {
        return [
            'endpoint' => $result['endpoint'] ?? null,
            'status' => $result['status'] ?? null,
            'error_code' => $result['error_code'] ?? null,
            'error_message' => $result['error_message'] ?? null,
        ];
    }

    private function faceProviderUnavailableResponse(array $result)
    {
        Log::warning('Face provider unavailable', $this->safeProviderDiagnostic($result));

        $payload = [
            'message' => $this->faceProviderMessage($result),
        ];

        if (app()->isLocal() || config('app.debug')) {
            $payload['provider'] = $this->safeProviderDiagnostic($result);
        }

        return response()->json($payload, 503);
    }

    private function faceProviderMessage(array $result): string
    {
        $errorCode = strtoupper((string) ($result['error_code'] ?? ''));
        $errorMessage = strtoupper((string) ($result['error_message'] ?? ''));

        if (str_contains($errorCode, 'CONCURRENCY_LIMIT_EXCEEDED') || str_contains($errorMessage, 'CONCURRENCY_LIMIT_EXCEEDED')) {
            return 'Face verification service is busy. Please try again in a few seconds.';
        }

        if (str_contains($errorCode, 'IMAGE_ERROR') || str_contains($errorMessage, 'IMAGE_ERROR')) {
            return 'Face image could not be processed. Please use a clear face photo.';
        }

        return 'Face verification service is unavailable';
    }

    private function faceResultHasInvalidToken(array $result): bool
    {
        $errorCode = strtoupper((string) ($result['error_code'] ?? ''));
        $errorMessage = strtoupper((string) ($result['error_message'] ?? ''));

        return str_contains($errorCode, 'INVALID_FACE_TOKEN')
            || str_contains($errorMessage, 'INVALID_FACE_TOKEN');
    }

    private function faceResultIsTransient(array $result): bool
    {
        $errorCode = strtoupper((string) ($result['error_code'] ?? ''));
        $errorMessage = strtoupper((string) ($result['error_message'] ?? ''));
        $status = (int) ($result['status'] ?? 0);

        return $status >= 500
            || str_contains($errorCode, 'CONCURRENCY_LIMIT_EXCEEDED')
            || str_contains($errorMessage, 'CONCURRENCY_LIMIT_EXCEEDED')
            || str_contains($errorCode, 'RATE_LIMIT')
            || str_contains($errorMessage, 'RATE_LIMIT')
            || str_contains($errorCode, 'HTTP_EXCEPTION');
    }

    private function clearInvalidFaceToken(User $student, string $context): void
    {
        Log::warning('Clearing invalid stored face token', [
            'context' => $context,
            'student_id' => $student->id,
        ]);

        if ($student->face_image_path) {
            $this->deleteStoredFaceImage($student->face_image_path);
        }

        $student->face_token = null;
        $student->face_image_path = null;
        $student->save();
    }

    private function invalidStoredFaceTokenResponse(User $student, string $context)
    {
        $this->clearInvalidFaceToken($student, $context);

        return response()->json([
            'message' => 'Stored face data is invalid. Please re-enroll your face.',
        ], 422);
    }

    private function uploadDiagnostic($file): array
    {
        $path = $file?->getRealPath();

        return [
            'original_name' => $file?->getClientOriginalName(),
            'client_mime' => $file?->getClientMimeType(),
            'detected_mime' => $file?->getMimeType(),
            'size' => $file?->getSize(),
            'temp_path_exists' => $path ? is_file($path) : false,
            'temp_path_readable' => $path ? is_readable($path) : false,
        ];
    }

    private function faceUploadFilename($file): string
    {
        $name = $file->getClientOriginalName();

        return $name && pathinfo($name, PATHINFO_EXTENSION) ? $name : 'face.jpg';
    }

    private function faceUploadMimeType($file): string
    {
        return $file->getMimeType() ?: $file->getClientMimeType() ?: 'image/jpeg';
    }

    private function deleteStoredFaceImage(?string $path): void
    {
        if (!$path || !str_starts_with($path, 'face-images/')) {
            return;
        }

        File::delete(public_path($path));
    }

    private function storeFaceProfileImage(User $user, $file): string
    {
        $directory = public_path('face-images');
        File::ensureDirectoryExists($directory);

        $extension = strtolower($file->extension() ?: $file->guessExtension() ?: 'jpg');
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $extension = 'jpg';
        }

        $filename = sprintf(
            'student-%s-%s-%s.%s',
            $user->id,
            now()->format('YmdHis'),
            bin2hex(random_bytes(4)),
            $extension
        );

        $this->deleteStoredFaceImage($user->face_image_path);
        $file->move($directory, $filename);

        return 'face-images/' . $filename;
    }

    public function register(Request $request, FacePlusPlusService $faceService)
    {
        $request->validate([
            'image' => 'required|image'
        ]);

        $user = $request->user();

        if ($user->role !== 'student') {
            return response()->json([
                'message' => 'Only students can register face'
            ], 403);
        }

        $file = $request->file('image');
        $path = $file->getRealPath();
        Log::info('Face register upload received', array_merge([
            'user_id' => $user->id,
            'has_image' => $request->hasFile('image'),
        ], $this->uploadDiagnostic($file)));

        if (!$this->faceServiceIsConfigured()) {
            return $this->faceServiceUnavailableResponse();
        }

        $detectResult = $faceService->detectDetailed(
            $path,
            $this->faceUploadFilename($file),
            $this->faceUploadMimeType($file)
        );
        if (!$detectResult['ok']) {
            return $this->faceProviderUnavailableResponse($detectResult);
        }

        $detectJson = $detectResult['json'] ?? [];

        if (!isset($detectJson['faces'][0]['face_token'])) {
            return response()->json([
                'message' => 'No face detected'
            ], 422);
        }

        $students = User::where('role', 'student')
            ->whereNotNull('face_token')
            ->where('id', '!=', $user->id)
            ->get();

        foreach ($students as $student) {
            $compareResult = $faceService->compareFaceTokenDetailed(
                $path,
                $student->face_token,
                $this->faceUploadFilename($file),
                $this->faceUploadMimeType($file)
            );
            if (!$compareResult['ok']) {
                if ($this->faceResultHasInvalidToken($compareResult)) {
                    $this->clearInvalidFaceToken($student, 'face_register_duplicate_check');
                    continue;
                }

                if ($this->faceResultIsTransient($compareResult)) {
                    Log::warning('Skipping duplicate face check because provider is temporarily unavailable', array_merge([
                        'student_id' => $student->id,
                    ], $this->safeProviderDiagnostic($compareResult)));
                    continue;
                }

                return $this->faceProviderUnavailableResponse($compareResult);
            }

            $compareJson = $compareResult['json'] ?? [];

            if (isset($compareJson['confidence']) && $compareJson['confidence'] >= 80) {
                return response()->json([
                    'message' => 'This face is already registered to another student',
                    'matched_student' => [
                        'id' => $student->id,
                        'name' => $student->name,
                        'email' => $student->email,
                        'university_code' => $student->university_code,
                    ],
                    'confidence' => $compareJson['confidence']
                ], 409);
            }
        }

        $user->face_token = $detectJson['faces'][0]['face_token'];
        $user->face_image_path = $this->storeFaceProfileImage($user, $file);
        $user->save();

        return response()->json([
            'message' => 'Face registered successfully',
            'face_token' => $user->face_token,
            'face_image_url' => $user->face_image_url,
        ]);
    }

    public function verify(Request $request, FacePlusPlusService $faceService)
    {
        $request->validate([
            'image' => 'required|image'
        ]);

        if ($request->user()->role !== 'doctor') {
            return response()->json([
                'message' => 'Only doctors can verify faces'
            ], 403);
        }

        if (!$this->faceServiceIsConfigured()) {
            return $this->faceServiceUnavailableResponse();
        }

        $file = $request->file('image');
        $path = $file->getRealPath();
        Log::info('Face verify upload received', array_merge([
            'user_id' => $request->user()->id,
            'has_image' => $request->hasFile('image'),
        ], $this->uploadDiagnostic($file)));
        $students = User::where('role', 'student')
            ->whereNotNull('face_token')
            ->get();

        foreach ($students as $student) {
            $result = $faceService->compareFaceTokenDetailed(
                $path,
                $student->face_token,
                $this->faceUploadFilename($file),
                $this->faceUploadMimeType($file)
            );

            if (!$result['ok']) {
                if ($this->faceResultHasInvalidToken($result)) {
                    return $this->invalidStoredFaceTokenResponse($student, 'face_verify');
                }

                return $this->faceProviderUnavailableResponse($result);
            }

            $compareJson = $result['json'] ?? [];

            if (isset($compareJson['confidence']) && $compareJson['confidence'] >= 80) {
                return response()->json([
                    'matched' => true,
                    'message' => 'Matching student found',
                    'student' => $this->studentPayload($student),
                    'confidence' => $compareJson['confidence']
                ]);
            }
        }

        return response()->json([
            'matched' => false,
            'message' => 'No matching student found'
        ], 404);
    }

    public function verifyAndMark(Request $request, FacePlusPlusService $faceService)
    {
        $request->validate([
            'image' => 'required|image',
            'session_id' => 'required|exists:attendance_sessions,id'
        ]);

        if ($request->user()->role !== 'doctor') {
            return response()->json([
                'message' => 'Only doctors can verify and mark attendance'
            ], 403);
        }

        $representativeSession = $this->findDoctorSessionOrFail($request, $request->session_id);
        $sessions = $this->sessionGroupFor($request, $representativeSession);

        if (!$this->faceServiceIsConfigured()) {
            return $this->faceServiceUnavailableResponse();
        }

        $file = $request->file('image');
        $path = $file->getRealPath();
        Log::info('Face verify-and-mark upload received', array_merge([
            'user_id' => $request->user()->id,
            'session_id' => $request->session_id,
            'has_image' => $request->hasFile('image'),
        ], $this->uploadDiagnostic($file)));

        $students = User::where('role', 'student')
            ->whereNotNull('face_token')
            ->get();

        foreach ($students as $student) {
            $result = $faceService->compareFaceTokenDetailed(
                $path,
                $student->face_token,
                $this->faceUploadFilename($file),
                $this->faceUploadMimeType($file)
            );

            if (!$result['ok']) {
                if ($this->faceResultHasInvalidToken($result)) {
                    return $this->invalidStoredFaceTokenResponse($student, 'face_verify_and_mark');
                }

                return $this->faceProviderUnavailableResponse($result);
            }

            $compareJson = $result['json'] ?? [];

            if (isset($compareJson['confidence']) && $compareJson['confidence'] >= 80) {
                if ($sessions->contains(fn ($child) => $child->status !== 'open')) {
                    return response()->json([
                        'matched' => true,
                        'message' => 'Session is closed',
                        'student' => $this->studentPayload($student),
                        'confidence' => $compareJson['confidence']
                    ], 400);
                }

                $session = $this->matchingSessionForStudent($sessions, $student);

                if (!$session) {
                    return response()->json([
                        'matched' => true,
                        'message' => 'Student not enrolled in this course',
                        'student' => $this->studentPayload($student),
                        'confidence' => $compareJson['confidence']
                    ], 403);
                }

                $exists = AttendanceRecord::where('user_id', $student->id)
                    ->where('attendance_session_id', $session->id)
                    ->exists();

                if ($exists) {
                    $attendance = AttendanceRecord::where('user_id', $student->id)
                        ->where('attendance_session_id', $session->id)
                        ->first();

                    return response()->json([
                        'matched' => true,
                        'message' => 'Student already marked present',
                        'student' => $this->studentPayload($student, $attendance),
                        'confidence' => $compareJson['confidence'],
                        'attendance' => $attendance ? $this->attendancePayload($attendance) : null,
                    ], 409);
                }

                $attendance = AttendanceRecord::create([
                    'user_id' => $student->id,
                    'attendance_session_id' => $session->id,
                    'method' => 'face',
                    'status' => 'present',
                    'match_score' => $compareJson['confidence'],
                    'attended_at' => Carbon::now(),
                ]);

                return response()->json([
                    'matched' => true,
                    'message' => 'Attendance marked successfully by face',
                    'student' => $this->studentPayload($student, $attendance),
                    'confidence' => $compareJson['confidence'],
                    'attendance' => $this->attendancePayload($attendance),
                ], 201);
            }
        }

        return response()->json([
            'matched' => false,
            'message' => 'No matching student found'
        ], 404);
    }
}

<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\AttendanceSessionController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\QrController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\FaceController;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/

Route::get('/test', function () {
    return response()->json([
        'message' => 'API working'
    ]);
});

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:20,1');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:20,1');

/*
|--------------------------------------------------------------------------
| Protected Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    Route::get('/me', [AuthController::class, 'me']);
    Route::patch('/me/profile', [AuthController::class, 'updateProfile']);
    Route::post('/me/change-password', [AuthController::class, 'changePassword']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/courses', [CourseController::class, 'getAll']);
    Route::get('/my-qr', [QrController::class, 'generate']);
});

/*
|--------------------------------------------------------------------------
| Doctor Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'role:doctor'])->group(function () {

    Route::post('/courses', [CourseController::class, 'create']);

    Route::get('/sessions', [AttendanceSessionController::class, 'index']);
    Route::post('/sessions', [AttendanceSessionController::class, 'create']);
    Route::patch('/sessions/{id}', [AttendanceSessionController::class, 'update']);
    Route::delete('/sessions/{id}', [AttendanceSessionController::class, 'destroy']);
    Route::post('/sessions/{id}/open', [AttendanceSessionController::class, 'open']);
    Route::post('/sessions/{id}/close', [AttendanceSessionController::class, 'close']);

    Route::get('/sessions/{id}/report', [AttendanceController::class, 'report']);

    Route::get('/courses/{id}/students', [CourseController::class, 'students']);

    Route::get('/courses/{id}/report', [ReportController::class, 'courseReport']);

    Route::get('/reports/overview', [ReportController::class, 'overview']);

    Route::get('/courses/{id}/export', [ReportController::class, 'exportCourseReport']);
    Route::get('/sessions/{id}/export', [ReportController::class, 'exportSessionReport']);

    Route::post('/face/verify', [FaceController::class, 'verify']);

    Route::post('/face/verify-and-mark', [FaceController::class, 'verifyAndMark']);

    Route::post('/qr/scan', [QrController::class, 'scan']);
});

/*
|--------------------------------------------------------------------------
| Student Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'role:student'])->group(function () {

    Route::post('/face/register', [FaceController::class, 'register']);

    Route::get('/my-courses', [EnrollmentController::class, 'myCourses']);

    Route::get('/available-courses', [EnrollmentController::class, 'availableCourses']);

    Route::post('/enroll', [EnrollmentController::class, 'enroll']);

    Route::post('/attendance', [AttendanceController::class, 'mark']);

    Route::get('/students/{id}/report', [ReportController::class, 'studentReport']);

    Route::get('/my-report', [ReportController::class, 'myReport']);

    Route::get('/my-attendance', [AttendanceController::class, 'myAttendance']);


});

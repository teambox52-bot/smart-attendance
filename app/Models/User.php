<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'university_code',
        'major',
        'level',
        'face_token'
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts()
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // الطالب مسجل في مواد
    public function enrollments()
    {
        return $this->hasMany(Enrollment::class);
    }

    // الدكتور بيدي مواد
    public function courses()
    {
        return $this->hasMany(Course::class, 'doctor_id');
    }

    // سجلات الحضور
    public function attendanceRecords()
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    // الجلسات اللي أنشأها الدكتور
    public function createdSessions()
    {
        return $this->hasMany(AttendanceSession::class, 'created_by');
    }
}

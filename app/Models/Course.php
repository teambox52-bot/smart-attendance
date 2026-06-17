<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    protected $fillable = [
        'name',
        'code',
        'doctor_id',
        'semester',
        'level',
        'is_active'
    ];

    // الدكتور صاحب الكورس
    public function doctor()
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    // الطلاب المسجلين
    public function enrollments()
    {
        return $this->hasMany(Enrollment::class);
    }

    // جلسات الحضور
    public function sessions()
    {
        return $this->hasMany(AttendanceSession::class);
    }
}

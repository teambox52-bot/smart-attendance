<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceSession extends Model
{
    protected $fillable = [
        'course_id',
        'created_by',
        'session_group_key',
        'method',
        'starts_at',
        'ends_at',
        'status'
    ];

    protected function casts()
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function doctor()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function records()
    {
        return $this->hasMany(AttendanceRecord::class, 'attendance_session_id');
    }

    public function scheduleDate(): ?string
    {
        return $this->starts_at?->format('Y-m-d');
    }

    public function scheduleStartTime(): ?string
    {
        return $this->starts_at?->format('H:i');
    }

    public function scheduleEndTime(): ?string
    {
        return $this->ends_at?->format('H:i');
    }

    public function scheduleStartsAt(): ?string
    {
        return $this->starts_at?->format('Y-m-d\TH:i:s');
    }

    public function scheduleEndsAt(): ?string
    {
        return $this->ends_at?->format('Y-m-d\TH:i:s');
    }
}

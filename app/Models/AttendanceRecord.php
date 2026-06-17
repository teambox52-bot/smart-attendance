<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceRecord extends Model
{
    protected $fillable = [
        'user_id',
        'attendance_session_id',
        'method',
        'status',
        'match_score',
        'attended_at'
    ];

    protected function casts()
    {
        return [
            'attended_at' => 'datetime',
            'match_score' => 'float',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function session()
    {
        return $this->belongsTo(AttendanceSession::class, 'attendance_session_id');
    }
}

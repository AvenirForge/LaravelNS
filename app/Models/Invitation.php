<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invitation extends Model
{
    use HasFactory;

    protected $table = 'invitations';

    // DODANO 'role' do fillable – to kluczowy fix
    protected $fillable = [
        'course_id',
        'invited_email',
        'status',
        'inviter_id',
        'token',
        'expires_at',
        'responded_at',
        'user_id',
        'role',              // ← TU BYŁ BRAK!
    ];

    protected $casts = [
        'expires_at'   => 'datetime',
        'responded_at' => 'datetime',
    ];

    public $timestamps = true;

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public static function createInvitation(array $data)
    {
        return self::create($data);
    }

    public function hasExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }
}

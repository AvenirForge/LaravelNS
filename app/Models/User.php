<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable;

    public const DEFAULT_AVATAR_RELATIVE = 'users/avatars/default.png';

    protected $fillable = ['name', 'email', 'password', 'avatar'];
    protected $hidden   = ['password', 'remember_token'];
    protected $casts    = ['email_verified_at' => 'datetime', 'password' => 'hashed'];

    // ===== Relacje =====
    public function invitations(): HasMany   { return $this->hasMany(Invitation::class, 'inviter_id'); }
    public function tests(): HasMany         { return $this->hasMany(Test::class); }
    public function answers(): HasMany       { return $this->hasMany(TestsAnswer::class); }
    public function notes(): HasMany         { return $this->hasMany(Note::class); }
    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'courses_users')
            ->withPivot(['role', 'status'])
            ->withTimestamps();
    }

    // ===== Atrybuty =====
    public function getAvatarUrlAttribute(): ?string
    {
        $rel = $this->avatar ?: self::DEFAULT_AVATAR_RELATIVE;
        return Storage::disk('public')->exists($rel) ? Storage::url($rel) : null;
    }

    // ===== Avatar =====
    public function changeAvatar($file): void
    {
        $disk = Storage::disk('public');
        if ($this->avatar && $this->avatar !== self::DEFAULT_AVATAR_RELATIVE && $disk->exists($this->avatar)) {
            $disk->delete($this->avatar);
        }
        $this->forceFill(['avatar' => $file->store('users/avatars', 'public')])->save();
    }

    // ===== Role =====
    public function hasRole(string $role): bool
    {
        return $this->courses()->wherePivot('role', $role)->exists();
    }

    public function roleInCourse(Course $course): ?string
    {
        $row = $this->courses()->where('course_id', $course->id)->first();
        return $row?->pivot?->role;
    }

    // ===== JWT =====
    public function getJWTIdentifier(): mixed { return $this->getKey(); }
    public function getJWTCustomClaims(): array { return []; }
}

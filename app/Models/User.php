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

    public function getAvatarUrlAttribute(): ?string
    {
        $relativePath = $this->avatar ?: self::DEFAULT_AVATAR_RELATIVE; // Użyj ścieżki z DB lub domyślnej
        $disk = Storage::disk('public'); // Użyj dysku 'public'

        // Sprawdź, czy plik (względny lub domyślny) istnieje na dysku
        if ($disk->exists($relativePath)) {
            // Zwróć pełny URL wygenerowany przez Laravel na podstawie konfiguracji
            return $disk->url($relativePath);
        }

        // Jeśli plik użytkownika nie istnieje, spróbuj zwrócić URL do domyślnego
        if ($relativePath !== self::DEFAULT_AVATAR_RELATIVE && $disk->exists(self::DEFAULT_AVATAR_RELATIVE)) {
            return $disk->url(self::DEFAULT_AVATAR_RELATIVE);
        }

        // Zwróć null, jeśli ani plik użytkownika, ani domyślny nie istnieją
        return null;
    }

    public function changeAvatar($file): void
    {
        $disk = Storage::disk('public');
        if ($this->avatar && $this->avatar !== self::DEFAULT_AVATAR_RELATIVE && $disk->exists($this->avatar)) {
            $disk->delete($this->avatar);
        }
        $this->forceFill(['avatar' => $file->store('users/avatars', 'public')])->save();
    }

    public function hasRole(string $role): bool { return $this->courses()->wherePivot('role', $role)->exists(); }
    public function roleInCourse(Course $course): ?string
    {
        $row = $this->courses()->where('course_id', $course->id)->first();
        return $row?->pivot?->role;
    }

    public function getJWTIdentifier(): mixed { return $this->getKey(); }
    public function getJWTCustomClaims(): array { return []; }
}

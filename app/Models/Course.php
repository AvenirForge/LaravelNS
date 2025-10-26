<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Course extends Model
{
    use HasFactory;

    public const DEFAULT_AVATAR_RELATIVE = 'courses/avatars/default.png';

    protected $table = 'courses';
    protected $fillable = ['title', 'description', 'type', 'user_id', 'avatar'];

    // ===== Relacje =====
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'courses_users')
            ->withPivot(['role', 'status'])
            ->withTimestamps();
    }

    public function notes(): HasMany       { return $this->hasMany(Note::class); }
    public function invitations(): HasMany { return $this->hasMany(Invitation::class); }

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
        $this->forceFill(['avatar' => $file->store('courses/avatars', 'public')])->save();
    }
}

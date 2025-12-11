<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Course extends Model
{
    use HasFactory;

    public const DEFAULT_AVATAR_RELATIVE = 'courses/avatars/default.png';

    protected $table = 'courses';
    protected $fillable = ['title', 'description', 'type', 'user_id', 'avatar'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'courses_users')
            ->withPivot(['role', 'status'])
            ->withTimestamps();
    }

    public function notes(): BelongsToMany { return $this->belongsToMany(Note::class, 'course_note'); }
    public function invitations(): HasMany { return $this->hasMany(Invitation::class); }
    public function tests(): BelongsToMany { return $this->belongsToMany(Test::class, 'course_test'); }
    public function user(): BelongsTo{ return $this->belongsTo(User::class); }
    public function getAvatarUrlAttribute(): ?string
    {
        $rel = $this->avatar ?: self::DEFAULT_AVATAR_RELATIVE;
        return Storage::disk('public')->exists($rel) ? Storage::url($rel) : null;
    }

    public function changeAvatar($file): void
    {
        $disk = Storage::disk('public');
        if ($this->avatar && $this->avatar !== self::DEFAULT_AVATAR_RELATIVE && $disk->exists($this->avatar)) {
            $disk->delete($this->avatar);
        }
        $this->forceFill(['avatar' => $file->store('courses/avatars', 'public')])->save();
    }
}

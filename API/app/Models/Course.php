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

    protected $table = 'courses';
    protected $fillable = ['title', 'description', 'type', 'user_id', 'avatar'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'courses_users')
            ->withPivot(['role', 'status'])
            ->withTimestamps();
    }

    public function notes(): HasMany       { return $this->hasMany(Note::class); }
    public function invitations(): HasMany { return $this->hasMany(Invitation::class); }

    public function downloadAvatar(): string
    {
        return ($this->avatar && Storage::disk('public')->exists($this->avatar))
            ? $this->avatar
            : 'avatars/default_course_avatar.png';
    }
}

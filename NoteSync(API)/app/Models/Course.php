<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class Course extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'type',
        'user_id',
        'avatar',
    ];
    protected $table = 'courses';

    public function users()
    {
        return $this->belongsToMany(User::class, 'courses_users')
            ->withPivot('role')
            ->withTimestamps();
    }

    // Posty w kursie
    public function posts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function tests()
    {
        return $this->belongsToMany(Test::class, 'course_test');
    }

    public function invitations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Invitation::class);
    }
    public function notes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Note::class);
    }

    public function downloadAvatar(): string
    {
        if ($this->avatar && \Storage::exists("public/courses/{$this->avatar}")) {
            return "public/courses/{$this->avatar}";
        }

        return "avatars/default_course_avatar.png"; // Domyślny avatar
    }
}

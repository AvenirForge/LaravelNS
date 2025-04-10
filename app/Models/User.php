<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /**
     * Get the notes associated with the user.
     */
    public function notes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Note::class);
    }

    /**
     * Get the URL for the user's avatar.
     *
     * @return string|null
     */
    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar ? Storage::url($this->avatar) : null;
    }

    /**
     * Method to change the avatar for the user.
     *
     * @param  \Illuminate\Http\UploadedFile  $file
     * @return void
     */
    public function changeAvatar($file): void
    {
        if ($this->avatar) {
            Storage::delete($this->avatar); // Delete old avatar
        }

        $this->avatar = $file->store('avatars');
        $this->save();
    }

    /**
     * JWTSubject method to get the identifier for JWT.
     *
     * @return mixed
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * JWTSubject method to get the custom claims for JWT.
     *
     * @return array
     */
    public function getJWTCustomClaims()
    {
        return [];
    }
}

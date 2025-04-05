<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable implements JWTSubject
{
    use Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'avatar'
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /**
     * Get the identifier that will be stored in the JWT claim.
     *
     * @return mixed
     */
    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * Return an array of custom claims to be added to the JWT.
     *
     * @return array
     */
    public function getJWTCustomClaims(): array
    {
        return [];
    }

    /**
     * Change the user's avatar.
     *
     * @param string $avatarPath
     * @return void
     */
    public function changeAvatar($avatarPath): void
    {
        if (file_exists(storage_path('app/public/' . $avatarPath))) {
            $this->avatar = $avatarPath;
            $this->save();
        }
    }

    /**
     * Get the full URL for the user's avatar.
     *
     * @return string
     */
    public function getAvatarUrl()
    {
        if ($this->avatar) {
            return Storage::url($this->avatar);
        }

        return Storage::url('avatars/default.png'); // Domyślny avatar
    }

    /**
     * Handle user registration logic to store avatar if exists.
     *
     * @param array $data
     * @return User
     */
    public static function registerUser($data)
    {
        $avatarPath = null;

        if (isset($data['avatar']) && $data['avatar']) {
            $avatarPath = $data['avatar']->store('avatars', 'public');
        }

        return self::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => bcrypt($data['password']),
            'avatar' => $avatarPath,
        ]);
    }
}

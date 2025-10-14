<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invitation extends Model
{

    // Określamy tabelę (jeśli jest inna niż domyślna)
    protected $table = 'invitations';

    // Określamy, które pola mogą być masowo przypisane
    protected $fillable = [
        'course_id',
        'invited_email',
        'status',
        'role',
        'inviter_id',
        'token',
        'expires_at',
    ];

    // Jeśli korzystasz z timestampów
    public $timestamps = true;
    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Relacja do modelu User (zapraszający)
     */
    public function inviter()
    {
        return $this->belongsTo(User::class, 'inviter_id');
    }

    /**
     * Metoda pomocnicza do tworzenia zaproszenia
     */
    public static function createInvitation(array $data)
    {
        return self::create($data);
    }

    /**
     * Sprawdzenie, czy zaproszenie wygasło
     */
    public function hasExpired()
    {
        return $this->expires_at < now();
    }
}

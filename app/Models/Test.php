<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Test extends Model
{
    use HasFactory;

    // 🔑 Spójne stałe na status
    public const STATUS_PRIVATE  = 'private';
    public const STATUS_PUBLIC   = 'public';
    public const STATUS_ARCHIVED = 'archived';

    protected $table = 'tests';

    protected $fillable = [
        'user_id',
        'course_id',
        'title',
        'description',
        'status',
    ];

    /**
     * Autor testu.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Kurs, w którym test został udostępniony (opcjonalnie).
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Pytania w teście.
     */
    public function questions(): HasMany
    {
        return $this->hasMany(TestsQuestion::class, 'test_id');
    }
}

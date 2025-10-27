<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Test extends Model
{
    use HasFactory;

    public const STATUS_PRIVATE  = 'private';
    public const STATUS_PUBLIC   = 'public';
    public const STATUS_ARCHIVED = 'archived';

    protected $table = 'tests';
    protected $fillable = ['user_id', 'title', 'description', 'status'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function courses(): BelongsToMany { return $this->belongsToMany(Course::class, 'course_test'); }
    public function questions(): HasMany { return $this->hasMany(TestsQuestion::class, 'test_id'); }
}

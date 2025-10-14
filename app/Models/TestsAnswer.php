<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TestsAnswer extends Model
{
    use HasFactory;

    protected $table = 'tests_answers';

    protected $fillable = ['answer','is_correct','question_id'];

    protected $casts = [
        'is_correct' => 'boolean',
    ];

    public function question(): BelongsTo
    {
        return $this->belongsTo(TestsQuestion::class, 'question_id');
    }
}

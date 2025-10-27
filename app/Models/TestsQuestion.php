<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TestsQuestion extends Model
{
    use HasFactory;

    protected $fillable = ['question', 'test_id'];

    public function test(): BelongsTo { return $this->belongsTo(Test::class); }
    public function answers(): HasMany { return $this->hasMany(TestsAnswer::class, 'question_id'); }
}

<?php

// app/Models/TestsQuestion.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TestsQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'question',
        'test_id',
    ];

    // Relacja: Pytanie ma wiele odpowiedzi
    public function test()
    {
        return $this->belongsTo(Test::class);
    }

    public function answers()
    {
        return $this->hasMany(TestsAnswer::class); // Relacja z odpowiedziami
    }
}

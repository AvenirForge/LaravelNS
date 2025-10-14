<?php

// app/Models/TestsAnswer.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TestsAnswer extends Model
{
    use HasFactory;
    protected $fillable = ['answer', 'is_correct', 'question_id'];

    public function question()
    {
        return $this->belongsTo(TestsQuestion::class, 'question_id');
    }
    public function user()
    {
        return $this->belongsTo(User::class); // Relacja z użytkownikiem
    }
}

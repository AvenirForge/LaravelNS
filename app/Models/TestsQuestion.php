<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TestsQuestion extends Model
{
    use HasFactory;

    protected $fillable = ['question', 'test_id'];

    public function test() { return $this->belongsTo(Test::class); }
    public function answers() { return $this->hasMany(TestsAnswer::class); }
}

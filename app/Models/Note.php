<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Note extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'description', 'file_path', 'is_private', 'user_id', 'status'];
    protected $casts = ['is_private' => 'boolean'];

    public function user(): BelongsTo   { return $this->belongsTo(User::class); }
    public function courses(): BelongsToMany { return $this->belongsToMany(Course::class, 'course_note'); }

    public function getFileUrlAttribute(): ?string { return $this->file_path ? Storage::url($this->file_path) : null; }
    public function scopeIsPrivate($q): mixed { return $q->where('is_private', true); }
    public function scopeIsPublic($q): mixed  { return $q->where('is_private', false); }

    protected static function boot(): void
    {
        parent::boot();
        static::deleting(fn($n) => $n->file_path && Storage::delete($n->file_path));
        static::creating(fn($n) => $n->is_private ??= true);
    }
}

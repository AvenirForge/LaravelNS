<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany; // Dodano import

class Note extends Model
{
    use HasFactory;

    protected $table = 'notes';
    protected $fillable = ['title', 'description','is_private', 'user_id', 'status'];
    // --- KONIEC ZMIANY ---

    protected $casts = ['is_private' => 'boolean'];


    public function user(): BelongsTo   { return $this->belongsTo(User::class); }
    public function courses(): BelongsToMany { return $this->belongsToMany(Course::class, 'course_note'); }

    public function files(): HasMany
    {
        return $this->hasMany(NoteFile::class)->orderBy('order', 'asc')->orderBy('id', 'asc');
    }
    public function scopeIsPrivate($q): mixed { return $q->where('is_private', true); }
    public function scopeIsPublic($q): mixed  { return $q->where('is_private', false); }

    protected static function boot(): void
    {
        parent::boot();

        static::deleting(function(Note $note) {
            if (method_exists($note, 'files')) { // Sprawdzenie dla pewności
                $note->files()->each(function (NoteFile $file) {
                    if ($file->file_path && Storage::disk('public')->exists($file->file_path)) {
                        Storage::disk('public')->delete($file->file_path);
                    }
                });
            }
        });
        static::creating(fn($n) => $n->is_private ??= true);
    }
}

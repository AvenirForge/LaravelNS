<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Note extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'file_path',
        'is_private',
        'user_id',
    ];
    protected $casts = [
        'is_private' => 'boolean',  // Upewniamy się, że to pole jest traktowane jako boolean
    ];
    /**
     * Define a relationship with the User model.
     */
    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the file URL for the note.
     *
     * @return string
     */
    public function getFileUrlAttribute(): ?string
    {
        return $this->file_path ? Storage::url($this->file_path) : null;
    }

    /**
     * Define a scope to filter private notes.
     *
     * @param $query
     * @return mixed
     */
    public function scopeIsPrivate($query): mixed
    {
        return $query->where('is_private', true);
    }

    /**
     * Define a scope to filter public notes.
     *
     * @param $query
     * @return mixed
     */
    public function scopeIsPublic($query)
    {
        return $query->where('is_private', false);
    }

    /**
     * Delete the note's file when it is deleted.
     */
    public static function boot(): void
    {
        parent::boot();

        static::deleting(function ($note) {
            if ($note->file_path) {
                Storage::delete($note->file_path);
            }
        });

        static::creating(function ($note) {
            if (!$note->is_private) {
                $note->is_private = true; // Domyślnie ustawiamy na true
            }
        });
    }
}

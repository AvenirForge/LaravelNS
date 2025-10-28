<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Casts\Attribute; // Użyj nowego Attribute dla akcesorów

class NoteFile extends Model
{
    use HasFactory;

    protected $table = 'note_files';

    protected $fillable = [
        'note_id',
        'file_path',
        'original_name',
        'mime_type',
        'order',
        // 'caption', // Jeśli dodałeś to pole w migracji
    ];

    // Ukryj pola techniczne w odpowiedziach JSON
    protected $hidden = [
        'created_at',
        'updated_at',
        'note_id', // Zwykle niepotrzebne, gdy jest zagnieżdżone w notatce
        'file_path', // Zamiast tego użyj file_url
    ];

    // Automatycznie dołączaj akcesor file_url do JSON
    protected $appends = ['file_url'];

    /**
     * Akcesor zwracający pełny URL do pliku.
     * Wymaga poprawnego ustawienia APP_URL w .env i `php artisan storage:link`.
     */
    protected function fileUrl(): Attribute
    {
        return Attribute::make(
            get: function ($value, $attributes) {
                $path = $attributes['file_path'] ?? null;
                // Sprawdź, czy ścieżka istnieje na dysku 'public'
                if ($path && Storage::disk('public')->exists($path)) {
                    // Zwróć pełny URL wygenerowany przez Laravel
                    return Storage::disk('public')->url($path);
                }
                // Zwróć null, jeśli plik nie istnieje (lub ścieżka jest pusta)
                return null;
            }
        );
    }

    /**
     * Relacja odwrotna do Note.
     */
    public function note(): BelongsTo
    {
        return $this->belongsTo(Note::class);
    }
}

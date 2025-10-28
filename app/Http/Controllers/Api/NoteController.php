<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Note;
use App\Models\Course;
use App\Models\User;
use App\Models\NoteFile; // Dodano import
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log; // Dodano
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse; // Zmieniono typ zwracany
use Symfony\Component\HttpFoundation\Response as Http;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

class NoteController extends Controller
{
    // Helper do pobierania zalogowanego użytkownika
    private function me(): ?User { return Auth::guard('api')->user(); }

    /** GET /api/me/notes?top=&skip= */
    public function index(Request $request): JsonResponse
    {
        $user = $this->me();
        if (!$user) return response()->json(['error' => 'Unauthorized'], Http::HTTP_UNAUTHORIZED);

        $top  = max(1, min(100, (int) $request->query('top', 10)));
        $skip = max(0, (int) $request->query('skip', 0));

        $notesQuery = $user->notes()
            // Ładuj powiązane kursy (tylko ID i tytuł) oraz pliki
            ->with(['courses:id,title', 'files'])
            ->latest('updated_at'); // Sortuj od najnowszych

        $totalCount = $notesQuery->count(); // Liczba wszystkich notatek użytkownika
        $notes = $notesQuery->skip($skip)->take($top)->get(); // Pobierz stronę wyników

        return response()->json([
            'data'  => $notes, // Zawiera zagnieżdżoną tablicę 'files'
            'skip'  => $skip,
            'top'   => $top,
            'count' => $totalCount,
        ]);
    }

    /** POST /api/me/notes (multipart/form-data) */
    public function store(Request $request): JsonResponse
    {
        $user = $this->me();
        if (!$user) return response()->json(['error' => 'Unauthorized'], Http::HTTP_UNAUTHORIZED);

        // Walidacja dla wielu plików ('files[]')
        $validator = Validator::make($request->all(), [
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'is_private'  => 'sometimes|boolean',
            'files'       => 'required|array|min:1', // Wymagana tablica z min. 1 plikiem
            'files.*'     => 'required|file|mimes:pdf,xlsx,jpg,jpeg,png|max:10240', // Walidacja każdego pliku
        ], [ /* Komunikaty błędów */ ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], Http::HTTP_UNPROCESSABLE_ENTITY); // 422
        }

        $validatedData = $validator->validated();
        $isPrivate = $validatedData['is_private'] ?? true; // Domyślnie true

        $note = null;
        $storedFilePaths = []; // Do śledzenia zapisanych plików dla rollbacku

        try {
            DB::transaction(function() use ($validatedData, $user, $isPrivate, $request, &$note, &$storedFilePaths) {
                // 1. Utwórz rekord notatki
                $note = Note::create([
                    'title'       => $validatedData['title'],
                    'description' => $validatedData['description'] ?? null,
                    'is_private'  => $isPrivate,
                    'user_id'     => $user->id,
                ]);

                // 2. Przetwórz i zapisz każdy plik
                $order = 0;
                foreach ($request->file('files') as $file) {
                    if ($file && $file->isValid()) {
                        $path = $file->store('users/notes', 'public'); // Zapisz na dysku public
                        if (!$path) throw new \Exception("Failed to store file: " . $file->getClientOriginalName());
                        $storedFilePaths[] = $path; // Dodaj do listy dla rollbacku

                        // Utwórz powiązany rekord NoteFile
                        $note->files()->create([
                            'file_path'     => $path,
                            'original_name' => $file->getClientOriginalName(),
                            'mime_type'     => $file->getMimeType(),
                            'order'         => $order++,
                        ]);
                    } else {
                        throw new \Exception("Invalid file uploaded in the files array.");
                    }
                }
            }); // Koniec transakcji
        } catch (\Exception $e) {
            Log::error("Note creation failed (user {$user->id}): " . $e->getMessage());
            // Usuń zapisane pliki, jeśli transakcja DB się nie powiodła
            foreach ($storedFilePaths as $path) Storage::disk('public')->delete($path);
            return response()->json(['error' => 'Failed to create note. ' . $e->getMessage()], Http::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Zwróć 201 Created z notatką i załadowanymi plikami
        return response()->json([
            'message' => 'Note created successfully!',
            'note' => $note->load('files') // Załaduj relację 'files'
        ], Http::HTTP_CREATED);
    }

    /** GET /api/me/notes/{id} */
    public function show($id): JsonResponse
    {
        $user = $this->me();
        if (!$user) return response()->json(['error' => 'Unauthorized'], Http::HTTP_UNAUTHORIZED);

        // Ładuj notatkę wraz z kursami i plikami
        $note = Note::with(['courses:id,title', 'files'])
            ->where('user_id', $user->id)
            ->find($id);

        if (!$note) return response()->json(['error' => 'Note not found or unauthorized'], Http::HTTP_NOT_FOUND); // 404

        return response()->json($note); // Zwróć notatkę (zawiera 'files' i 'courses')
    }

    /** PATCH or PUT /api/me/notes/{id} */
    public function edit(Request $request, $id): JsonResponse
    {
        // Aktualizuje tylko metadane (title, desc, is_private)
        $user = $this->me();
        if (!$user) return response()->json(['error' => 'Unauthorized'], Http::HTTP_UNAUTHORIZED);

        $note = Note::with('courses:id,title')->where('user_id', $user->id)->find($id); // Ładuj kursy
        if (!$note) return response()->json(['error' => 'Note not found or unauthorized'], Http::HTTP_NOT_FOUND);

        $validator = Validator::make($request->all(), [
            'title'       => 'sometimes|required|string|max:255',
            'description' => 'sometimes|nullable|string|max:5000',
            'is_private'  => 'sometimes|required|boolean',
        ]);
        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], Http::HTTP_UNPROCESSABLE_ENTITY); // 422
        $validatedData = $validator->validated();

        $becomingPrivate = isset($validatedData['is_private']) && $validatedData['is_private'] === true;
        $wasPublicOrUnset = !$note->is_private;

        try {
            DB::transaction(function() use ($note, $validatedData, $becomingPrivate, $wasPublicOrUnset) {
                // Jeśli staje się prywatna i nie była, odłącz od kursów
                if ($becomingPrivate && $wasPublicOrUnset) {
                    $note->courses()->detach();
                }
                $note->fill($validatedData);
                $note->save();
            });
        } catch (\Exception $e) {
            Log::error("Note metadata update failed for note {$id}: " . $e->getMessage());
            return response()->json(['error' => 'Failed to update note metadata.'], Http::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Zwróć zaktualizowaną notatkę, załaduj też pliki dla spójności
        return response()->json([
            'message' => 'Note updated successfully!',
            'note' => $note->load('files') // Dodano ładowanie plików
        ]);
    }

    /**
     * POST /api/me/notes/{noteId}/files
     * NOWA FUNKCJA: Dodaje nowy plik do istniejącej notatki.
     */
    public function addFileToNote(Request $request, int $noteId): JsonResponse
    {
        $user = $this->me();
        if (!$user) return response()->json(['error' => 'Unauthorized'], Http::HTTP_UNAUTHORIZED);

        $note = Note::where('user_id', $user->id)->find($noteId);
        if (!$note) return response()->json(['error' => 'Note not found or unauthorized'], Http::HTTP_NOT_FOUND);

        // Walidacja pojedynczego pliku
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:pdf,xlsx,jpg,jpeg,png|max:10240', // Klucz 'file'
        ], ['file.*' => 'Invalid file. Max 10MB, allowed types: pdf, xlsx, jpg, jpeg, png.']);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], Http::HTTP_UNPROCESSABLE_ENTITY); // 422
        }

        $file = $request->file('file'); // Pobierz plik używając klucza 'file'
        $path = null;
        try {
            if ($file && $file->isValid()) {
                $path = $file->store('users/notes', 'public');
                if (!$path) throw new \Exception("Failed to store the new file.");

                // Znajdź najwyższy istniejący 'order' i dodaj 1
                $maxOrder = $note->files()->max('order') ?? -1;

                // Utwórz rekord NoteFile
                $noteFile = $note->files()->create([
                    'file_path'     => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type'     => $file->getMimeType(),
                    'order'         => $maxOrder + 1,
                ]);

                // Zwróć sukces z danymi nowego pliku
                return response()->json([
                    'message' => 'File added to note successfully!',
                    'file'    => $noteFile // Zawiera file_url dzięki $appends
                ], Http::HTTP_CREATED); // 201

            } else {
                throw new \Exception("Invalid file uploaded.");
            }
        } catch (\Exception $e) {
            Log::error("Adding file to note {$noteId} failed: " . $e->getMessage());
            if ($path) Storage::disk('public')->delete($path); // Rollback pliku
            return response()->json(['error' => 'Failed to add file. ' . $e->getMessage()], Http::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * DELETE /api/me/notes/{noteId}/files/{fileId}
     * NOWA FUNKCJA: Usuwa konkretny plik z notatki.
     */
    public function deleteFileFromNote(Request $request, int $noteId, int $fileId): JsonResponse
    {
        $user = $this->me();
        if (!$user) return response()->json(['error' => 'Unauthorized'], Http::HTTP_UNAUTHORIZED);

        $note = Note::where('user_id', $user->id)->find($noteId);
        if (!$note) return response()->json(['error' => 'Note not found or unauthorized'], Http::HTTP_NOT_FOUND);

        /** @var NoteFile|null $noteFile */
        $noteFile = $note->files()->find($fileId); // Znajdź plik w kontekście notatki
        if (!$noteFile) {
            return response()->json(['error' => 'File not found within this note'], Http::HTTP_NOT_FOUND); // 404
        }

        try {
            DB::transaction(function() use ($noteFile) {
                // Usuń plik z dysku, jeśli istnieje
                if ($noteFile->file_path && Storage::disk('public')->exists($noteFile->file_path)) {
                    Storage::disk('public')->delete($noteFile->file_path);
                }
                // Usuń rekord z bazy danych
                $noteFile->delete(); // Rekord NoteFile
            });
        } catch (\Exception $e) {
            Log::error("Deleting file {$fileId} from note {$noteId} failed: " . $e->getMessage());
            return response()->json(['error' => 'Failed to delete file.'], Http::HTTP_INTERNAL_SERVER_ERROR);
        }

        return response()->json(['message' => 'File deleted successfully from note.'], Http::HTTP_OK); // 200 OK
    }

    /**
     * GET /api/me/notes/{noteId}/files/{fileId}/download
     * NOWA FUNKCJA: Pobiera konkretny plik z notatki.
     */
    public function downloadNoteFile(Request $request, int $noteId, int $fileId): BinaryFileResponse|JsonResponse
    {
        $user = $this->me();
        if (!$user) return response()->json(['error' => 'Unauthorized'], Http::HTTP_UNAUTHORIZED);

        // Znajdź plik przez ID, weryfikując jednocześnie przynależność do notatki użytkownika
        $noteFile = NoteFile::where('id', $fileId)
            ->whereHas('note', function (EloquentBuilder $query) use ($user, $noteId) {
                $query->where('user_id', $user->id)->where('id', $noteId);
            })
            ->first();

        if (!$noteFile) {
            return response()->json(['error' => 'File not found, does not belong to this note, or unauthorized.'], Http::HTTP_NOT_FOUND); // 404
        }

        // Sprawdź istnienie pliku na dysku
        if (!$noteFile->file_path || !Storage::disk('public')->exists($noteFile->file_path)) {
            Log::error("File record found (ID {$fileId}) but file missing on disk at path: {$noteFile->file_path}");
            return response()->json(['error' => 'File not found on storage'], Http::HTTP_NOT_FOUND); // 404
        }

        // Zwróć plik do pobrania, używając oryginalnej nazwy
        $absolutePath = Storage::disk('public')->path($noteFile->file_path);
        $originalName = $noteFile->original_name ?? basename($absolutePath); // Użyj oryginalnej nazwy lub fallback

        // `response()->download()` ustawi nagłówki Content-Type i Content-Disposition
        return response()->download($absolutePath, $originalName);
    }


    /** POST /api/me/notes/{noteId}/share/{courseId} */
    public function shareNoteWithCourse(Request $request, $noteId, $courseId): JsonResponse {
        $user = $this->me(); if (!$user) return response()->json(['error'=>'Unauthorized'],401);
        $note = Note::where('user_id', $user->id)->find($noteId); if (!$note) return response()->json(['error'=>'Note not found'],404);
        $course = Course::find($courseId); if (!$course) return response()->json(['error'=>'Course not found'],404);
        $isOwner = ((int) $course->user_id === (int) $user->id);
        $roleInCourse = DB::table('courses_users')->where('course_id',$course->id)->where('user_id',$user->id)->value('role');
        $statusInCourse = DB::table('courses_users')->where('course_id',$course->id)->where('user_id',$user->id)->value('status');
        $acceptedStatuses = ['accepted','active','approved','joined'];
        $isMember = in_array($statusInCourse, $acceptedStatuses, true);
        if (!$isOwner && !$isMember && !in_array($roleInCourse, ['admin','moderator'])) return response()->json(['error'=>'Forbidden'],403);
        try { DB::transaction(function() use ($note,$courseId) { if ($note->is_private) { $note->is_private=false; $note->save(); } $note->courses()->syncWithoutDetaching([$courseId]); }); }
        catch (\Exception $e) { Log::error("Share note {$noteId} failed: {$e->getMessage()}"); return response()->json(['error'=>'Failed to share'],500); }
        // --- ZMIANA: Load 'files' ---
        $note->load(['courses:id,title', 'files']);
        return response()->json(['message' => 'Note shared', 'note' => $note], 200);
    }

    /** DELETE /api/me/notes/{noteId}/share/{courseId} */
    public function unshareNoteFromCourse(Request $request, $noteId, $courseId): JsonResponse {
        $user = $this->me(); if (!$user) return response()->json(['error'=>'Unauthorized'],401);
        $note = Note::with('courses:id')->where('user_id', $user->id)->find($noteId); if (!$note) return response()->json(['error'=>'Note not found'],404);
        if (!Course::where('id',$courseId)->exists()) return response()->json(['error'=>'Course not found'],404);
        $isAttached = $note->courses->contains($courseId);
        if (!$isAttached) { if ($note->courses->isEmpty() && !$note->is_private) { try { $note->is_private=true; $note->save(); } catch (\Exception $e) {} }
            // --- ZMIANA: Load 'files' ---
            return response()->json(['message' => 'Not shared', 'note' => $note->load('files')], 200);
        }
        try { DB::transaction(function() use ($note,$courseId) { $note->courses()->detach($courseId); $note->load('courses:id'); if ($note->courses->isEmpty() && !$note->is_private) { $note->is_private=true; $note->save(); } }); }
        catch (\Exception $e) { Log::error("Unshare note {$noteId} failed: {$e->getMessage()}"); return response()->json(['error'=>'Failed to unshare'],500); }
        // --- ZMIANA: Load 'files' ---
        return response()->json(['message' => 'Note unshared', 'note' => $note->load('files')], 200);
    }

    /** DELETE /api/me/notes/{id} */
    public function destroy($id): JsonResponse { // Logika bez zmian, model event obsługuje pliki
        $user = $this->me(); if (!$user) return response()->json(['error'=>'Unauthorized'],401);
        $note = Note::where('user_id', $user->id)->find($id); if (!$note) return response()->json(['error'=>'Not found'],404);
        try { $note->delete(); } catch (\Exception $e) { Log::error("Note delete {$id} failed: {$e->getMessage()}"); return response()->json(['error'=>'Delete failed'],500); }
        return response()->json(['message' => 'Note deleted'], 200);
    }

    /** GET /api/courses/{courseId}/notes */
    public function notesForCourse(Request $request, int $courseId): JsonResponse { // Logika bez zmian, ładuje 'files'
        $me = $this->me(); if (!$me) return response()->json(['error'=>'Unauthorized'],401);
        $course = Course::find($courseId); if (!$course) return response()->json(['error'=>'Course not found'],404);
        // Autoryzacja bez zmian
        $roleInCourse = DB::table('courses_users')->where('course_id',$course->id)->where('user_id',$me->id)->value('role'); $statusInCourse = DB::table('courses_users')->where('course_id',$course->id)->where('user_id',$me->id)->value('status'); $isOwner = ((int)$course->user_id === (int)$me->id); $acceptedStatuses = ['accepted','active','approved','joined']; $isMember = in_array($statusInCourse, $acceptedStatuses, true); $isAdminLike = $isOwner || in_array($roleInCourse, ['owner','admin','moderator']);
        if (!$isAdminLike && !$isMember) return response()->json(['error'=>'Forbidden'],403);
        $canViewPrivate = $isAdminLike;
        // Filtry, sortowanie, paginacja bez zmian
        $needle = $request->string('q')->trim()->toString() ?: null; $authorId = $request->integer('user_id') ?: null; $visibility = $request->string('visibility')->trim()->toString() ?: 'all'; $sort = $request->string('sort')->trim()->toString() ?: 'created_at'; $order = strtolower($request->string('order')->trim()->toString() ?: 'desc'); $order = in_array($order, ['asc','desc'], true) ? $order : 'desc'; $perPage = max(1, min(100, (int)($request->input('per_page', 20))));
        // --- ZMIANA: Added with('files') ---
        $q = $course->notes()->with(['user:id,name,avatar', 'files']);
        // Logika widoczności bez zmian
        if (!$canViewPrivate) { $q->where(function(EloquentBuilder $subQ) use ($me) { $subQ->where('notes.is_private', false)->orWhere('notes.user_id', $me->id); }); if ($visibility === 'public') $q->where('notes.is_private', false); elseif ($visibility === 'private' || $visibility === 'my_private') $q->where('notes.user_id', $me->id)->where('notes.is_private', true); }
        else { if ($visibility === 'public') $q->where('notes.is_private', false); elseif ($visibility === 'private') $q->where('notes.is_private', true); elseif ($visibility === 'my_private') $q->where('notes.user_id', $me->id)->where('notes.is_private', true); }
        // Filtry bez zmian
        if ($authorId) $q->where('notes.user_id', (int)$authorId); if ($needle) { $like = "%{$needle}%"; $q->where(function(EloquentBuilder $subQ) use ($like) { $subQ->where('notes.title','like', $like)->orWhere('notes.description','like', $like); }); }
        // Sortowanie bez zmian
        $sortColumn = match ($sort) { 'title' => 'notes.title', 'updated_at' => 'notes.updated_at', default => 'notes.created_at' }; $q->orderBy($sortColumn, $order);
        // Paginacja
        $page = $q->paginate($perPage);
        // --- ZMIANA: Mapping toArray obejmuje 'files' ---
        $items = $page->getCollection()->map(fn(Note $n) => $n->toArray())->all();
        // Metadane kursu bez zmian
        $owner = User::select('id','name','avatar')->find($course->user_id); $avatarUrl = $course->avatar_url; $totalNotesInCourse = $course->notes()->count(); $acceptedMembersCount = DB::table('courses_users')->where('course_id', $course->id)->whereIn('status', $acceptedStatuses)->count();
        return response()->json(['course' => [ 'id' => $course->id, 'title' => $course->title, 'description' => $course->description, 'type' => $course->type, 'avatar_url' => $avatarUrl, 'owner' => $owner ? ['id' => $owner->id, 'name' => $owner->name, 'avatar_url' => $owner->avatar_url] : null, 'my_role' => $roleInCourse, 'my_status' => $statusInCourse, 'stats' => [ 'members_accepted' => $acceptedMembersCount, 'notes_total_in_course' => $totalNotesInCourse, 'notes_filtered' => $page->total(), ], 'created_at' => $course->created_at?->toISOString(), 'updated_at' => $course->updated_at?->toISOString(), ], 'filters' => [ /* unchanged */ ], 'pagination' => [ /* unchanged */ ], 'notes' => $items, ]);
    }

} // Koniec klasy NoteController

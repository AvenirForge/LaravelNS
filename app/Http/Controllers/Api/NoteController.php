<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Note;
use App\Models\Course;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB; // <-- DODANY IMPORT DB
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as Http;

class NoteController extends Controller
{
    /**
     * GET /api/me/notes?top=&skip=
     * Paginowana lista notatek użytkownika.
     */
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();
        $top  = max(1, (int) $request->query('top', 10));
        $skip = max(0, (int) $request->query('skip', 0));

        // ZMIANA: Dodajemy ładowanie relacji 'courses', aby UI wiedziało, gdzie notatka jest udostępniona
        $notes = $user->notes()
            ->with('courses:id,title') // <-- Ładujemy powiązane kursy (tylko ID i tytuł)
            ->skip($skip)
            ->take($top)
            ->latest() // <-- Dodano sortowanie dla spójności
            ->get();

        return response()->json([
            'data'  => $notes,
            'skip'  => $skip,
            'top'   => $top,
            'count' => $user->notes()->count(),
        ]);
    }

    /**
     * POST /api/me/notes (multipart/form-data)
     * Tworzy nową notatkę osobistą. (Bez zmian logiki N:M)
     */
    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();
        $isPrivate = $request->has('is_private') ? $request->boolean('is_private') : true;

        $validator = Validator::make($request->all(), [
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'file'        => 'required|file|mimes:pdf,xlsx,jpg,jpeg,png|max:2048',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], Http::HTTP_BAD_REQUEST);
        }
        if (!$request->hasFile('file')) {
            return response()->json(['error' => 'No file uploaded'], Http::HTTP_BAD_REQUEST);
        }

        $note              = new Note();
        $note->title       = (string) $request->input('title');
        $note->description = $request->input('description');
        $note->is_private  = $isPrivate;
        $note->user_id     = $user->id;
        $path              = $request->file('file')->store('users/notes', 'public');
        $note->file_path   = $path;
        $note->save();

        return response()->json(['message' => 'Note created successfully!', 'note' => $note], Http::HTTP_CREATED);
    }

    /**
     * GET /api/me/notes/{id}
     * Zwraca notatkę użytkownika.
     */
    public function show($id): \Illuminate\Http\JsonResponse
    {
        $note = Note::find($id);
        if (!$note || (int) $note->user_id !== (int) Auth::id()) {
            return response()->json(['error' => 'Note not found or unauthorized'], Http::HTTP_NOT_FOUND);
        }

        // ZMIANA: Dołączamy listę kursów, w których notatka jest udostępniona
        $note->load('courses:id,title');

        return response()->json($note);
    }

    /**
     * PATCH /api/me/notes/{id}
     * Aktualizacja pól tekstowych notatki. (Bez zmian logiki N:M)
     */
    public function edit(Request $request, $id): \Illuminate\Http\JsonResponse
    {
        $note = Note::find($id);
        if (!$note || (int) $note->user_id !== (int) Auth::id()) {
            return response()->json(['error' => 'Note not found or unauthorized'], Http::HTTP_NOT_FOUND);
        }

        $validator = Validator::make($request->all(), [
            'title'       => 'sometimes|nullable|string|max:255',
            'description' => 'sometimes|nullable|string|max:5000',
            'is_private'  => 'sometimes|boolean',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], Http::HTTP_BAD_REQUEST);
        }

        if ($request->has('title')) $note->title = $request->input('title');
        if ($request->has('description')) $note->description = $request->input('description');
        if ($request->has('is_private')) $note->is_private = $request->boolean('is_private');
        $note->save();

        return response()->json(['message' => 'Note text updated successfully!', 'note' => $note]);
    }

    /**
     * POST /api/me/notes/{id}/patch (multipart/form-data)
     * Podmiana pliku notatki. (Bez zmian logiki N:M)
     */
    public function patchFile(Request $request, $id): \Illuminate\Http\JsonResponse
    {
        $note = Note::find($id);
        if (!$note || (int) $note->user_id !== (int) Auth::id()) {
            return response()->json(['error' => 'Note not found or unauthorized'], Http::HTTP_NOT_FOUND);
        }
        if (!$request->hasFile('file')) {
            return response()->json(['error' => ['file' => ['The file field is required.']]], Http::HTTP_BAD_REQUEST);
        }

        $validator = Validator::make($request->all(), [
            'file' => 'required|file|max:2048|mimes:jpg,jpeg,png,pdf,doc,docx,xlsx',
        ], ['file.*' => 'Invalid file. Max 2MB, allowed types: jpg, png, pdf, doc(x), xlsx.']);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], Http::HTTP_BAD_REQUEST);
        }

        if ($note->file_path && Storage::disk('public')->exists($note->file_path)) {
            Storage::disk('public')->delete($note->file_path);
        }
        $newPath = $request->file('file')->store('users/notes', 'public');
        $note->file_path = $newPath;
        $note->save();

        return response()->json(['message' => 'Note file updated successfully!', 'note' => $note]);
    }

    /**
     * POST /api/me/notes/{noteId}/share/{courseId}
     * ZMIANA: Używa relacji N:M (syncWithoutDetaching).
     */
    public function shareNoteWithCourse(Request $request, $noteId, $courseId): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();
        $note = Note::where('user_id', $user->id)->findOrFail($noteId);
        $course = Course::findOrFail($courseId);

        // Autoryzacja: Musisz być członkiem kursu (lub właścicielem)
        $isOwner = ((int) $course->user_id === (int) $user->id);
        $isMember = DB::table('courses_users')
            ->where('course_id', $course->id)->where('user_id', $user->id)
            ->where('status', 'accepted')->exists();
        if (!$isOwner && !$isMember) {
            return response()->json(['error' => 'You must be a member of this course to share notes'], Http::HTTP_FORBIDDEN);
        }

        // Logika udostępniania
        $note->is_private = false; // Udostępniona notatka jest publiczna w kontekście kursu
        $note->save();
        $note->courses()->syncWithoutDetaching([$courseId]); // Dodaj powiązanie, jeśli nie istnieje
        $note->load('courses:id,title'); // Odśwież relacje

        return response()->json(['message' => 'Notatka udostępniona w kursie', 'note' => $note], Http::HTTP_OK);
    }

    /**
     * DELETE /api/me/notes/{noteId}/share/{courseId}
     * ZMIANA: Używa relacji N:M (detach) i logiki przywracania prywatności.
     */
    public function unshareNoteFromCourse(Request $request, $noteId, $courseId): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();
        $note = Note::with('courses')->where('user_id', $user->id)->find($noteId);
        if (!$note) return response()->json(['error' => 'Note not found'], Http::HTTP_NOT_FOUND);
        $course = Course::find($courseId);
        if (!$course) return response()->json(['error' => 'Course not found'], Http::HTTP_NOT_FOUND);

        $isAttached = $note->courses->contains($course->id);

        if (!$isAttached) {
            // Idempotencja: Jeśli nie jest już nigdzie udostępniona, upewnij się, że jest prywatna
            if ($note->courses->isEmpty() && !$note->is_private) {
                $note->is_private = true;
                $note->save();
            }
            return response()->json(['message' => 'Note is not shared with this course or already private', 'note' => $note], Http::HTTP_OK);
        }

        // Logika odpinania
        $note->courses()->detach($courseId);
        $note->load('courses'); // Odśwież relacje

        // Jeśli nie jest już udostępniona w ŻADNYM kursie, oznacz jako prywatną
        if ($note->courses->isEmpty()) {
            $note->is_private = true;
            $note->save();
        }

        return response()->json(['message' => 'Notatka została usunięta z kursu', 'note' => $note], Http::HTTP_OK);
    }

    /**
     * DELETE /api/me/notes/{id}
     * Usuwa notatkę użytkownika. (Bez zmian logiki N:M - kaskada FK działa)
     */
    public function destroy($id): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();
        $note = Note::find($id);
        if (!$note || (int) $note->user_id !== (int) $user->id) {
            return response()->json(['error' => 'Note not found or unauthorized'], Http::HTTP_NOT_FOUND);
        }

        if ($note->file_path && Storage::disk('public')->exists($note->file_path)) {
            Storage::disk('public')->delete($note->file_path);
        }
        $note->delete();

        return response()->json(['message' => 'Note deleted successfully']);
    }

    /**
     * GET /api/me/notes/{id}/download
     * Pobiera plik notatki. (Bez zmian logiki N:M)
     */
    public function download($id): BinaryFileResponse|\Illuminate\Http\JsonResponse
    {
        $note = Note::find($id);
        if (!$note || (int) $note->user_id !== (int) Auth::id()) {
            return response()->json(['error' => 'Note not found or unauthorized'], Http::HTTP_NOT_FOUND);
        }
        if (!$note->file_path || !Storage::disk('public')->exists($note->file_path)) {
            return response()->json(['error' => 'File not found'], Http::HTTP_NOT_FOUND);
        }
        $absolute = Storage::disk('public')->path($note->file_path);
        return response()->download($absolute);
    }

    /**
     * GET /api/courses/{courseId}/notes
     * ZMIANA: Pobiera notatki przez relację N:M z kursu.
     */
    public function notesForCourse(Request $request, int $courseId)
    {
        $me = Auth::user();
        $course = Course::find($courseId);
        if (!$course) return response()->json(['error' => 'Course not found'], Http::HTTP_NOT_FOUND);

        // Autoryzacja - taka sama jak w poprzedniej wersji, spójna z E2E
        $meId = $me?->id;
        $isOwner = $meId ? ((int)$course->user_id === (int)$meId) : false;
        $pivot = $meId ? DB::table('courses_users')->where('course_id', $course->id)->where('user_id', $meId)->first() : null;
        $role = $pivot?->role; $status = $pivot?->status;
        $ACCEPTED_STATUSES = ['accepted', 'active', 'approved', 'joined'];
        $isMemberAccepted = $status ? in_array($status, $ACCEPTED_STATUSES, true) : false;
        $isAdminLike = $isOwner || in_array($role, ['owner','admin','moderator'], true);
        if (!$isOwner && !$isAdminLike && !$isMemberAccepted) {
            return response()->json(['error' => 'Unauthorized'], Http::HTTP_FORBIDDEN);
        }

        // Filtry, sortowanie, paginacja (bez zmian)
        $needle = $request->string('q')->trim()->toString() ?: null;
        $authorId = $request->integer('user_id') ?: null;
        $visibility = $request->string('visibility')->trim()->toString() ?: 'auto';
        $sort = $request->string('sort')->trim()->toString() ?: 'created_at';
        $order = strtolower($request->string('order')->trim()->toString() ?: 'desc');
        $order = in_array($order, ['asc','desc'], true) ? $order : 'desc';
        $perPage = max(1, min(100, (int)($request->input('per_page', 20))));

        // ZMIANA: Zapytanie budowane na relacji N:M
        $q = $course->notes()->with(['user:id,name,avatar']);

        // Logika widoczności (bez zmian)
        if ($isAdminLike) {
            if ($visibility === 'public') $q->where('is_private', false);
            elseif ($visibility === 'private') $q->where('is_private', true);
        } else {
            $q->where(fn($w) => $w->where('is_private', false)->orWhere('user_id', $meId));
            if ($visibility === 'public') $q->where('is_private', false);
            elseif ($visibility === 'private') $q->where('user_id', $meId)->where('is_private', true);
        }

        // Filtry (bez zmian)
        if ($needle) $q->where(fn($w) => $w->where('title','like',"%{$needle}%")->orWhere('description','like',"%{$needle}%"));
        if ($authorId) $q->where('user_id', (int)$authorId);

        // Sortowanie (używamy pola z tabeli 'notes')
        $sortColumn = match ($sort) { 'title' => 'notes.title', 'updated_at' => 'notes.updated_at', default => 'notes.created_at' };
        $q->orderBy($sortColumn, $order);

        $page = $q->paginate($perPage);

        // Mapowanie (bez zmian)
        $items = $page->getCollection()->map(fn(Note $n) => [
            'id' => $n->id, 'title' => $n->title, 'description' => $n->description,
            'is_private' => (bool)$n->is_private, 'file_url' => $n->file_url,
            'user' => ['id' => $n->user?->id, 'name' => $n->user?->name, 'avatar_url' => $n->user?->avatar_url],
            'created_at' => optional($n->created_at)?->toISOString(), 'updated_at' => optional($n->updated_at)?->toISOString(),
        ])->all();

        // Metadane kursu (bez zmian logiki, tylko dostosowanie zliczania)
        $owner = User::select('id','name','avatar')->find($course->user_id);
        $avatarUrl = $course->avatar ? Storage::disk('public')->url($course->avatar) : null;
        $totalNotesInCourse = $course->notes()->count(); // ZMIANA: Zliczanie przez relację
        $acceptedMembers = DB::table('courses_users')->where('course_id', $course->id)->whereIn('status', $ACCEPTED_STATUSES)->count();

        return response()->json([
            'course' => [ /* ... bez zmian ... */
                'id' => $course->id, 'title' => $course->title, 'description' => $course->description, 'type' => $course->type,
                'avatar_path' => $course->avatar, 'avatar_url' => $avatarUrl,
                'owner' => $owner ? ['id' => $owner->id, 'name' => $owner->name, 'avatar_url' => $owner->avatar_url] : null,
                'my_role' => $role, 'my_status' => $status,
                'stats' => ['members_accepted' => $acceptedMembers, 'notes_total' => $totalNotesInCourse, 'notes_filtered' => $page->total()],
                'created_at' => optional($course->created_at)?->toISOString(), 'updated_at' => optional($course->updated_at)?->toISOString(),
            ],
            'filters' => [ /* ... bez zmian ... */
                'q' => $needle, 'user_id' => $authorId, 'visibility' => $visibility,
                'sort' => $sort, 'order' => $order, 'per_page' => $perPage,
            ],
            'pagination' => [ /* ... bez zmian ... */
                'total' => $page->total(), 'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            ],
            'notes' => $items,
        ]);
    }
}

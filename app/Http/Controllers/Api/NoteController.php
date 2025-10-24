<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Note;
use App\Models\Course;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as Http;

class NoteController extends Controller
{
    /**
     * GET /api/me/notes?top=&skip=
     * Paginowana lista notatek użytkownika (bez plików).
     */
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();

        $top  = max(1, (int) $request->query('top', 10));
        $skip = max(0, (int) $request->query('skip', 0));

        $notes = $user->notes()
            ->skip($skip)
            ->take($top)
            ->get();

        return response()->json([
            'data'  => $notes,
            'skip'  => $skip,
            'top'   => $top,
            'count' => $user->notes()->count(),
        ]);
    }

    /**
     * POST /api/me/notes  (multipart/form-data)
     * Tworzy nową notatkę wraz z plikiem (dysk 'public').
     * Uwaga: is_private normalizujemy ręcznie — brak walidacji boolean.
     */
    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();

        // Normalizacja booleana (działa dla JSON i multipart)
        $isPrivate = $request->has('is_private')
            ? $request->boolean('is_private')
            : true; // domyślnie prywatna

        $validator = Validator::make($request->all(), [
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'file'        => 'required|file|mimes:pdf,xlsx,jpg,jpeg,png|max:2048',
            // is_private - bez walidacji, sami narzucamy wartość
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

        // Zapis pliku do storage/app/public/users/notes
        $path = $request->file('file')->store('users/notes', 'public');
        $note->file_path = $path;

        $note->save();

        return response()->json([
            'message' => 'Note created successfully!',
            'note'    => $note,
        ], Http::HTTP_CREATED);
    }

    /**
     * GET /api/me/notes/{id}
     * Zwraca notatkę użytkownika (jeśli istnieje i należy do niego).
     */
    public function show($id): \Illuminate\Http\JsonResponse
    {
        $note = Note::find($id);
        if (!$note) {
            return response()->json(['error' => 'Note not found'], Http::HTTP_NOT_FOUND);
        }
        if ((int) $note->user_id !== (int) Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], Http::HTTP_FORBIDDEN);
        }

        return response()->json($note);
    }

    /**
     * PATCH /api/me/notes/{id}
     * Częściowa aktualizacja pól tekstowych (title, description, is_private).
     * is_private normalizujemy ręcznie — bez walidacji boolean.
     */
    public function edit(Request $request, $id): \Illuminate\Http\JsonResponse
    {
        $note = Note::find($id);
        if (!$note) {
            return response()->json(['error' => 'Note not found'], Http::HTTP_NOT_FOUND);
        }
        if ((int) $note->user_id !== (int) Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], Http::HTTP_FORBIDDEN);
        }

        $validator = Validator::make($request->all(), [
            'title'       => 'sometimes|nullable|string|max:255',
            'description' => 'sometimes|nullable|string|max:5000',
            'is_private'  => 'sometimes|boolean', // po walidacji: true/false
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], Http::HTTP_BAD_REQUEST);
        }

        if ($request->has('title')) {
            $note->title = $request->input('title');
        }
        if ($request->has('description')) {
            $note->description = $request->input('description');
        }
        if ($request->has('is_private')) {
            $note->is_private = $request->boolean('is_private');
        }

        $note->save();

        return response()->json([
            'message' => 'Note text updated successfully!',
            'note'    => $note,
        ]);
    }

    /**
     * POST /api/me/notes/{id}/patch  (multipart/form-data)
     * Podmiana pliku notatki (bez zmiany pól tekstowych).
     */
    public function patchFile(Request $request, $id): \Illuminate\Http\JsonResponse
    {
        $note = Note::find($id);
        if (!$note) {
            return response()->json(['error' => 'Note not found'], Http::HTTP_NOT_FOUND);
        }
        if ((int) $note->user_id !== (int) Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], Http::HTTP_FORBIDDEN);
        }
        if (!$request->hasFile('file')) {
            return response()->json(['error' => ['file' => ['The file field is required.']]], Http::HTTP_BAD_REQUEST);
        }

        $validator = Validator::make($request->all(), [
            'file' => 'required|file|max:2048|mimes:jpg,jpeg,png,pdf,doc,docx,xlsx',
        ], [
            'file.required' => 'Nie dodano pliku.',
            'file.mimes'    => 'Dozwolone formaty to jpg, jpeg, png, pdf, doc, docx, xlsx.',
            'file.max'      => 'Plik nie może być większy niż 2MB.',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], Http::HTTP_BAD_REQUEST);
        }

        // Usuń poprzedni plik (na dysku public), jeśli istnieje
        if ($note->file_path && Storage::disk('public')->exists($note->file_path)) {
            Storage::disk('public')->delete($note->file_path);
        }

        $newPath = $request->file('file')->store('users/notes', 'public');
        $note->file_path = $newPath;
        $note->save();

        return response()->json([
            'message' => 'Note file updated successfully!',
            'note'    => $note,
        ]);
    }

    /**
     * POST /api/me/notes/{noteId}/share/{courseId}
     * Udostępnia notatkę w kursie (ustawia is_private=false oraz course_id).
     */
    public function shareNoteWithCourse(Request $request, $noteId, $courseId): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();

        // Notatka musi należeć do użytkownika
        $note = Note::where('user_id', $user->id)->findOrFail($noteId);

        // Kurs musi istnieć
        $course = Course::findOrFail($courseId);

        $note->is_private = false;
        $note->course()->associate($course);
        $note->save();

        return response()->json([
            'message' => 'Notatka udostępniona w kursie',
            'note'    => $note,
        ], Http::HTTP_OK);
    }

    /**
     * DELETE /api/me/notes/{noteId}/share/{courseId}
     * Zdejmuje notatkę z kursu (bez wymogu rangi), przywraca prywatność.
     * Warunki:
     *  - notatka musi należeć do zalogowanego użytkownika,
     *  - kurs musi istnieć,
     *  - jeśli notatka nie jest przypięta do kursu → 200 OK (idempotencja),
     *  - jeśli przypięta do innego kursu → 409 CONFLICT.
     */
    public function unshareNoteFromCourse(Request $request, $noteId, $courseId): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();

        /** @var Note|null $note */
        $note = Note::where('user_id', $user->id)->find($noteId);
        if (!$note) {
            return response()->json(['error' => 'Note not found'], Http::HTTP_NOT_FOUND);
        }

        /** @var Course|null $course */
        $course = Course::find($courseId);
        if (!$course) {
            return response()->json(['error' => 'Course not found'], Http::HTTP_NOT_FOUND);
        }

        // Idempotencja: już prywatna
        if ($note->course_id === null) {
            return response()->json([
                'message' => 'Notatka jest już prywatna',
                'note'    => $note,
            ], Http::HTTP_OK);
        }

        // Mismatch kursu → nie zdejmujemy "cudzych" powiązań
        if ((int)$note->course_id !== (int)$course->id) {
            return response()->json([
                'error'              => 'Note is not shared with this course',
                'note_id'            => (int)$note->id,
                'course_id_passed'   => (int)$courseId,
                'course_id_actual'   => (int)$note->course_id,
            ], Http::HTTP_CONFLICT);
        }

        // Przywrócenie prywatności
        $note->is_private = true;
        $note->course()->dissociate();
        $note->save();

        return response()->json([
            'message' => 'Notatka została usunięta z kursu i ustawiona jako prywatna',
            'note'    => $note,
        ], Http::HTTP_OK);
    }

    /**
     * DELETE /api/me/notes/{id}
     */
    public function destroy($id): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();

        $note = Note::find($id);
        if (!$note) {
            return response()->json(['error' => 'Note not found'], Http::HTTP_NOT_FOUND);
        }
        if ((int) $note->user_id !== (int) $user->id) {
            return response()->json(['error' => 'Unauthorized'], Http::HTTP_FORBIDDEN);
        }

        if ($note->file_path && Storage::disk('public')->exists($note->file_path)) {
            Storage::disk('public')->delete($note->file_path);
        }

        $note->delete();

        return response()->json(['message' => 'Note deleted successfully']);
    }

    /**
     * GET /api/me/notes/{id}/download
     */
    public function download($id): BinaryFileResponse|\Illuminate\Http\JsonResponse
    {
        $note = Note::find($id);
        if (!$note) {
            return response()->json(['error' => 'Note not found'], Http::HTTP_NOT_FOUND);
        }

        $user = Auth::user();
        if ((int) $note->user_id !== (int) $user->id) {
            return response()->json(['error' => 'Unauthorized'], Http::HTTP_FORBIDDEN);
        }

        if (!$note->file_path || !Storage::disk('public')->exists($note->file_path)) {
            return response()->json(['error' => 'File not found'], Http::HTTP_NOT_FOUND);
        }

        $absolute = Storage::disk('public')->path($note->file_path);
        return response()->download($absolute);
    }

    /**
     * GET /api/courses/{courseId}/notes
     * Lista notatek w kursie z filtrami i metadanymi kursu.
     */
    public function notesForCourse(Request $request, int $courseId)
    {
        $me = Auth::user();

        /** @var Course|null $course */
        $course = Course::find($courseId);
        if (!$course) {
            return response()->json(['error' => 'Course not found'], Http::HTTP_NOT_FOUND);
        }

        // Ustalenie ról/członkostwa
        $meId    = $me?->id;
        $isOwner = $meId ? ((int)$course->user_id === (int)$meId) : false;
        $pivot   = $meId ? $course->users()->where('user_id', $meId)->first() : null;

        $role    = $pivot?->pivot?->role;
        $status  = $pivot?->pivot?->status;

        // Akceptowane statusy członkostwa (spójne z testami)
        $ACCEPTED_STATUSES = ['accepted', 'active', 'approved', 'joined'];
        $isMemberAccepted  = $status ? in_array($status, $ACCEPTED_STATUSES, true) : false;

        $isAdminLike = $isOwner || in_array($role, ['owner','admin','moderator'], true);

        // TWARDY wymóg członkostwa
        if (!$isOwner && !$isAdminLike && !$isMemberAccepted) {
            return response()->json(['error' => 'Unauthorized'], Http::HTTP_FORBIDDEN);
        }

        // Filtry i parametry
        $needle     = $request->string('q')->trim()->toString() ?: null;
        $authorId   = $request->integer('user_id') ?: null;
        $visibility = $request->string('visibility')->trim()->toString() ?: 'auto';

        $sort  = $request->string('sort')->trim()->toString() ?: 'created_at';
        $order = strtolower($request->string('order')->trim()->toString() ?: 'desc');
        $order = in_array($order, ['asc','desc'], true) ? $order : 'desc';

        $perPage = max(1, min(100, (int)($request->input('per_page', 20))));

        // Query na notatki w kursie
        $q = Note::query()
            ->where('course_id', $course->id)
            ->with(['user:id,name,avatar']);

        // Widoczność wg ról
        if ($isAdminLike) {
            if ($visibility === 'public')      { $q->where('is_private', false); }
            elseif ($visibility === 'private')  { $q->where('is_private', true); }
        } else {
            // Member accepted: publiczne + prywatne własne
            $q->where(function($w) use ($meId) {
                $w->where('is_private', false)
                    ->orWhere('user_id', $meId);
            });
            if ($visibility === 'public') {
                $q->where('is_private', false);
            } elseif ($visibility === 'private') {
                $q->where('user_id', $meId)->where('is_private', true);
            }
        }

        // Filtry
        if ($needle) {
            $like = "%{$needle}%";
            $q->where(function($w) use ($like) {
                $w->where('title','like',$like)
                    ->orWhere('description','like',$like);
            });
        }
        if ($authorId) {
            $q->where('user_id', (int)$authorId);
        }

        // Sort
        if (in_array($sort, ['created_at','updated_at','title'], true)) {
            $q->orderBy($sort, $order);
        } else {
            $q->orderBy('created_at', 'desc');
        }

        $page = $q->paginate($perPage);

        $items = $page->getCollection()->map(function (Note $n) {
            return [
                'id'          => $n->id,
                'title'       => $n->title,
                'description' => $n->description,
                'is_private'  => (bool)$n->is_private,
                'file_url'    => $n->file_url, // accessor
                'user'        => [
                    'id'         => $n->user?->id,
                    'name'       => $n->user?->name,
                    'avatar_url' => $n->user?->avatar_url,
                ],
                'created_at'  => optional($n->created_at)?->toISOString(),
                'updated_at'  => optional($n->updated_at)?->toISOString(),
            ];
        })->all();

        // Metadane kursu
        $owner = null;
        if (method_exists($course, 'user')) {
            $course->loadMissing(['user:id,name,avatar']);
            $owner = $course->user;
        } else {
            $owner = User::select('id','name','avatar')->find($course->user_id);
        }

        $avatarUrl = $course->avatar ? Storage::disk('public')->url($course->avatar) : null;

        $totalNotesInCourse = Note::where('course_id', $course->id)->count();
        $acceptedMembers = method_exists($course, 'users')
            ? $course->users()->wherePivotIn('status', $ACCEPTED_STATUSES)->count()
            : null;

        return response()->json([
            'course' => [
                'id'          => $course->id,
                'title'       => $course->title,
                'description' => $course->description,
                'type'        => $course->type,
                'avatar_path' => $course->avatar,
                'avatar_url'  => $avatarUrl,
                'owner'       => $owner ? [
                    'id'         => $owner->id ?? null,
                    'name'       => $owner->name ?? null,
                    'avatar_url' => $owner->avatar_url ?? null,
                ] : null,
                'my_role'     => $role,
                'my_status'   => $status,
                'stats'       => [
                    'members_accepted' => $acceptedMembers,
                    'notes_total'      => $totalNotesInCourse,
                    'notes_filtered'   => $page->total(),
                ],
                'created_at' => optional($course->created_at)?->toISOString(),
                'updated_at' => optional($course->updated_at)?->toISOString(),
            ],
            'filters' => [
                'q'          => $needle,
                'user_id'    => $authorId,
                'visibility' => $visibility,
                'sort'       => $sort,
                'order'      => $order,
                'per_page'   => $perPage,
            ],
            'pagination' => [
                'total'        => $page->total(),
                'per_page'     => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
            ],
            'notes' => $items,
        ]);
    }
}

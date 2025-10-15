<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Note;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
            return response()->json(['error' => $validator->errors()], 400);
        }

        if (!$request->hasFile('file')) {
            return response()->json(['error' => 'No file uploaded'], 400);
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
        ], 201);
    }

    /**
     * GET /api/me/notes/{id}
     * Zwraca notatkę użytkownika (jeśli istnieje i należy do niego).
     */
    public function show($id): \Illuminate\Http\JsonResponse
    {
        $note = Note::find($id);
        if (!$note) {
            return response()->json(['error' => 'Note not found'], 404);
        }
        if ((int) $note->user_id !== (int) Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
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
            return response()->json(['error' => 'Note not found'], 404);
        }
        if ((int) $note->user_id !== (int) Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'title'       => 'sometimes|nullable|string|max:255',
            'description' => 'sometimes|nullable|string|max:5000',
            'is_private'  => 'sometimes|boolean', // ★ DODANE: wymagaj booleana, inaczej 400
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        if ($request->has('title')) {
            $note->title = $request->input('title');
        }
        if ($request->has('description')) {
            $note->description = $request->input('description');
        }
        if ($request->has('is_private')) {
            // po walidacji to wartości dozwolone (true/false/0/1/'true'/'false')
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
            return response()->json(['error' => 'Note not found'], 404);
        }
        if ((int) $note->user_id !== (int) Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        if (!$request->hasFile('file')) {
            return response()->json(['error' => ['file' => ['The file field is required.']]], 400);
        }

        $validator = Validator::make($request->all(), [
            'file' => 'required|file|max:2048|mimes:jpg,jpeg,png,pdf,doc,docx,xlsx',
        ], [
            'file.required' => 'Nie dodano pliku.',
            'file.mimes'    => 'Dozwolone formaty to jpg, jpeg, png, pdf, doc, docx, xlsx.',
            'file.max'      => 'Plik nie może być większy niż 2MB.',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
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
        ], 200);
    }

    /**
     * DELETE /api/me/notes/{id}
     */
    public function destroy($id): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();

        $note = Note::find($id);
        if (!$note) {
            return response()->json(['error' => 'Note not found'], 404);
        }
        if ((int) $note->user_id !== (int) $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
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
            return response()->json(['error' => 'Note not found'], 404);
        }

        $user = Auth::user();
        if ((int) $note->user_id !== (int) $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if (!$note->file_path || !Storage::disk('public')->exists($note->file_path)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        $absolute = Storage::disk('public')->path($note->file_path);
        return response()->download($absolute);
    }


    public function indexForCourse(Request $request, int $courseId)
    {
        $me = Auth::user();

        /** @var Course|null $course */
        $course = Course::find($courseId);
        if (!$course) {
            return response()->json(['error' => 'Course not found'], 404);
        }

        // Ustalenie ról/członkostwa
        $meId    = $me?->id;
        $isOwner = $meId ? ((int)$course->user_id === (int)$meId) : false;
        $pivot   = $meId ? $course->users()->where('user_id', $meId)->first() : null;

        $role    = $pivot?->pivot?->role;
        $status  = $pivot?->pivot?->status;

        // Zależnie od projektu możesz mieć różne etykiety „zaakceptowania”
        $ACCEPTED_STATUSES = ['accepted', 'active', 'approved', 'joined'];
        $isMemberAccepted  = $status ? in_array($status, $ACCEPTED_STATUSES, true) : false;

        $isAdminLike = $isOwner || in_array($role, ['owner','admin','moderator'], true);

        // TWARDY wymóg członkostwa: brak wyjątków dla kursów publicznych
        if (!$isOwner && !$isAdminLike && !$isMemberAccepted) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Filtry i parametry
        $needle     = $request->string('q')->trim()->toString() ?: null;
        $authorId   = $request->integer('user_id') ?: null;
        $visibility = $request->string('visibility')->trim()->toString() ?: 'auto';

        $sort  = $request->string('sort')->trim()->toString() ?: 'created_at';
        $order = strtolower($request->string('order')->trim()->toString() ?: 'desc');
        $order = in_array($order, ['asc','desc'], true) ? $order : 'desc';

        $perPage = max(1, min(100, (int)($request->input('per_page', 20))));

        $q = Note::query()
            ->where('course_id', $course->id)
            ->with(['user:id,name,avatar']);

        // Widoczność wg ról
        if ($isAdminLike) {
            if ($visibility === 'public')   { $q->where('is_private', false); }
            elseif ($visibility === 'private') { $q->where('is_private', true); }
            // 'all'/'auto' → bez dodatkowych ograniczeń
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
                'id'         => $n->id,
                'title'      => $n->title,
                'description'=> $n->description,
                'is_private' => (bool)$n->is_private,
                'file_url'   => $n->file_url, // accessor
                'user'       => [
                    'id'         => $n->user?->id,
                    'name'       => $n->user?->name,
                    'avatar_url' => $n->user?->avatar_url,
                ],
                'created_at' => optional($n->created_at)?->toISOString(),
                'updated_at' => optional($n->updated_at)?->toISOString(),
            ];
        })->all();

        return response()->json([
            'course' => [
                'id'                  => $course->id,
                'title'               => $course->title,
                'type'                => $course->type,
                'role'                => $role,
                'can_see_all_private' => $isAdminLike,
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

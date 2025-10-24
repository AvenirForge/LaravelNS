<?php

namespace App\Http\Controllers\Api;

use App\Models\Course;
use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\Http\JsonResponse;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;

class UserController extends Controller
{
    // POST /api/login
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email'    => 'required|string|email',
            'password' => 'required|string',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $credentials = $validator->validated();

        // Guard 'api' = JWT (config/auth.php)
        if (!$token = auth('api')->attempt($credentials)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // W tym request nie ma jeszcze Authorization header – pobierz usera z nowo wydanego tokenu
        $user = auth('api')->setToken($token)->user();

        return response()->json([
            'message'     => 'Login successful',
            'userId'      => $user->id,
            'token'       => $token,
            'token_type'  => 'Bearer',
            'expires_in'  => auth('api')->factory()->getTTL() * 60, // sekundy
            'user'        => $user->only(['id', 'name', 'email']) + ['avatar_url' => $user->avatar_url],
        ]);
    }

    // POST /api/users/register
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name'                  => 'required|string|max:255',
            'email'                 => 'required|string|email|max:255|unique:users,email',
            'password'              => 'required|string|min:8|confirmed',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $user = new User();
        $user->name     = (string) $request->input('name');
        $user->email    = (string) $request->input('email');

        // Dzięki casts ['password' => 'hashed'] hasło zostanie zahashowane automatycznie
        $user->password = (string) $request->input('password');

        // Domyślny avatar
        $user->avatar   = User::DEFAULT_AVATAR_RELATIVE;
        $user->save();

        return response()->json([
            'message' => 'User created successfully!',
            'user'    => $user->only(['id', 'name', 'email']) + ['avatar_url' => $user->avatar_url],
        ], 201);
    }

    // PATCH /api/me/profile
    public function update(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user(); // spójnie z middleware 'auth:api'
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validator = Validator::make($request->all(), [
            'name'     => 'sometimes|present|string|max:255',
            'email'    => 'sometimes|present|string|email|max:255|unique:users,email,' . $user->id,
            'password' => 'sometimes|present|string|min:8|confirmed',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        if ($request->has('name'))     { $user->name     = (string) $request->input('name'); }
        if ($request->has('email'))    { $user->email    = (string) $request->input('email'); }
        if ($request->has('password')) { $user->password = (string) $request->input('password'); } // cast 'hashed'

        $user->save();
        $user->refresh();

        return response()->json([
            'message' => 'User updated successfully!',
            'user'    => $user->only(['id', 'name', 'email']) + ['avatar_url' => $user->avatar_url],
        ]);
    }

    // POST /api/me/profile/avatar
    public function updateAvatar(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if (!$request->hasFile('avatar')) {
            return response()->json(['error' => ['avatar' => ['The avatar file is required.']]], 400);
        }

        $validator = Validator::make($request->all(), [
            'avatar' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        if ($user->avatar && $user->avatar !== User::DEFAULT_AVATAR_RELATIVE) {
            if (Storage::disk('public')->exists($user->avatar)) {
                Storage::disk('public')->delete($user->avatar);
            }
        }

        $path = $request->file('avatar')->store('users/avatars', 'public');
        $user->avatar = $path;
        $user->save();
        $user->refresh();

        return response()->json([
            'message'    => 'Avatar updated successfully!',
            'avatar_url' => $user->avatar_url,
            'user'       => $user->only(['id', 'name', 'email']) + ['avatar_url' => $user->avatar_url],
        ]);
    }

    // GET /api/me/profile/avatar
    public function downloadAvatar(): BinaryFileResponse|JsonResponse
    {
        /** @var User|null $user */
        $user = request()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $relative = $user->avatar ?: User::DEFAULT_AVATAR_RELATIVE;

        if (!Storage::disk('public')->exists($relative)) {
            $relative = User::DEFAULT_AVATAR_RELATIVE;
            if (!Storage::disk('public')->exists($relative)) {
                return response()->json(['error' => 'Avatar not found'], 404);
            }
        }

        $absolute = Storage::disk('public')->path($relative);
        return response()->download($absolute);
    }

    // DELETE /api/me/profile
    public function destroy(): JsonResponse
    {
        /** @var User|null $user */
        $user = request()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if ($user->avatar && $user->avatar !== User::DEFAULT_AVATAR_RELATIVE) {
            if (Storage::disk('public')->exists($user->avatar)) {
                Storage::disk('public')->delete($user->avatar);
            }
        }

        $user->delete();

        return response()->json(['message' => 'User deleted successfully']);
    }

    // POST /api/me/logout
    public function logout(): JsonResponse
    {
        try {
            auth('api')->logout(); // unieważnij bieżący JWT
        } catch (JWTException $e) {
            // fallback – jeśli guard nie miał tokenu, spróbuj jawnie
            try {
                $token = JWTAuth::getToken();
                if ($token) {
                    JWTAuth::invalidate($token);
                }
            } catch (\Throwable $t) {
                // no-op
            }
        }

        return response()->json(['message' => 'User logged out successfully']);
    }

    // POST /api/me/refresh
    public function refresh(): JsonResponse
    {
        try {
            // Odśwież token. To automatycznie unieważni stary token (doda do blacklisty)
            // i zwróci nowy, ważny token.
            $newToken = auth('api')->refresh();

            /** @var User $user */
            $user = auth('api')->user();

        } catch (JWTException $e) {
            // Błąd odświeżania (np. stary token wygasł i minął refresh_ttl,
            // jest na czarnej liście, LUB podpis jest niepoprawny - Twój przypadek)
            return response()->json(['error' => 'Could not refresh token: ' . $e->getMessage()], 401);
        }

        // Zwróć odpowiedź w formacie identycznym jak login()
        return response()->json([
            'message'     => 'Token refreshed successfully',
            'userId'      => $user->id,
            'token'       => $newToken,
            'token_type'  => 'Bearer',
            'expires_in'  => auth('api')->factory()->getTTL() * 60, // sekundy
            'user'        => $user->only(['id', 'name', 'email']) + ['avatar_url' => $user->avatar_url],
        ]);
    }

    // GET /api/me/profile (alias show)
    public function show(): JsonResponse
    {
        /** @var User|null $user */
        $user = request()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return response()->json([
            'user' => $user->only(['id', 'name', 'email']) + ['avatar_url' => $user->avatar_url],
        ]);
    }

    public function usersForCourse(Request $request, int $courseId)
    {
        $me = Auth::user();

        /** @var Course|null $course */
        $course = Course::find($courseId);
        if (!$course) {
            return response()->json(['error' => 'Course not found'], 404);
        }

        // --- Aliasy statusów i ról ---
        $ACCEPTED_STATUSES = ['accepted','active','approved','joined'];
        $ROLE_ALIASES = [
            'member'    => ['member','user'],
            'admin'     => ['admin'],
            'owner'     => ['owner'],
            'moderator' => ['moderator'],
        ];

        // Czy JA jestem właścicielem / admin-like / accepted member?
        $meId    = $me?->id;
        $isOwner = $meId ? ((int)$course->user_id === (int)$meId) : false;

        // Użyj bezpośrednio pivotu z DB
        $pivot = $meId
            ? DB::table('courses_users')->where('course_id', $course->id)->where('user_id', $meId)->first()
            : null;

        $role   = $pivot->role   ?? null;
        $status = $pivot->status ?? null;

        $isMemberAccepted = $status ? in_array($status, $ACCEPTED_STATUSES, true) : false;
        $isAdminLike      = $isOwner || in_array($role, ['owner','admin','moderator'], true);

        if (!$isOwner && !$isMemberAccepted && !$isAdminLike) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // --- Filtry / sort / paginacja ---
        $needle  = $request->string('q')->trim()->toString() ?: null;
        $roleF   = $request->string('role')->trim()->toString() ?: null;
        $statusF = $request->string('status')->trim()->toString() ?: null;

        $sort  = $request->string('sort')->trim()->toString() ?: 'name';
        $order = strtolower($request->string('order')->trim()->toString() ?: 'asc');
        $order = in_array($order, ['asc','desc'], true) ? $order : 'asc';

        $perPage = max(1, min(100, (int)($request->input('per_page', 20))));

        $builder = $course->users()
            ->select('users.id','users.name','users.email','users.avatar')
            ->withPivot(['role','status','created_at'])
            ->when(!$isAdminLike, function($q) use ($ACCEPTED_STATUSES) {
                $q->whereIn('courses_users.status', $ACCEPTED_STATUSES);
            })
            ->when($needle, function($q, $n) {
                $like = "%{$n}%";
                $q->where(function($w) use ($like) {
                    $w->where('users.name','like',$like)
                        ->orWhere('users.email','like',$like);
                });
            })
            // role filter: akceptuj aliasy (member→member|user)
            ->when($roleF, function($q) use ($ROLE_ALIASES, $roleF) {
                $opts = $ROLE_ALIASES[$roleF] ?? [$roleF];
                $q->whereIn('courses_users.role', $opts);
            })
            // status filter (aliasy); dla 'all' nie filtruj
            ->when($statusF && $statusF !== 'all', function($q) use ($ACCEPTED_STATUSES, $statusF) {
                $opts = $statusF === 'accepted' ? $ACCEPTED_STATUSES : [$statusF];
                $q->whereIn('courses_users.status', $opts);
            });

        if ($sort === 'role') {
            $builder->orderBy('courses_users.role', $order)->orderBy('users.name', 'asc');
        } elseif ($sort === 'joined') {
            $builder->orderBy('courses_users.created_at', $order);
        } else {
            $builder->orderBy('users.name', $order);
        }

        $page = $builder->paginate($perPage);

        $items = $page->getCollection()->map(function(\App\Models\User $u) {
            return [
                'id'         => $u->id,
                'name'       => $u->name,
                'email'      => $u->email,
                'avatar_url' => $u->avatar_url,
                'role'       => $u->pivot?->role,
                'status'     => $u->pivot?->status,
                'joined_at'  => optional($u->pivot?->created_at)?->toISOString(),
            ];
        })->all();

        // ── Rozszerzone metadane kursu (nieinwazyjne dla istniejących testów) ──
        // Owner (preferuj relację 'user', w razie czego fallback do SELECT)
        $owner = null;
        if (method_exists($course, 'user')) {
            $course->loadMissing(['user:id,name,avatar']);
            $owner = $course->user;
        } else {
            $owner = User::select('id','name','avatar')->find($course->user_id);
        }

        // Publiczny URL avatara (null jeśli brak ścieżki — nie psuje testów 404 na endpointzie pobierania)
        $avatarUrl = $course->avatar ? Storage::disk('public')->url($course->avatar) : null;

        // Statystyki (pomocne w UI; nie zmieniają kontraktu testów)
        $acceptedMembers = method_exists($course, 'users')
            ? $course->users()->wherePivotIn('status', $ACCEPTED_STATUSES)->count()
            : null;

        $permissions = [
            'is_owner'            => $isOwner,
            'is_admin_like'       => $isAdminLike,
            'is_member_accepted'  => $isMemberAccepted,
            'can_manage_members'  => $isOwner || in_array($role, ['owner','admin','moderator'], true),
        ];

        return response()->json([
            // ⬇️ zachowuję istniejące klucze i dodaję tylko nowe pola
            'course' => [
                'id'          => $course->id,
                'title'       => $course->title,
                'type'        => $course->type,
                'role'        => $role,
                'is_owner'    => $isOwner,

                // NOWE (pełne info)
                'description' => $course->description,
                'avatar_path' => $course->avatar,
                'avatar_url'  => $avatarUrl,
                'owner'       => $owner ? [
                    'id'         => $owner->id ?? null,
                    'name'       => $owner->name ?? null,
                    'avatar_url' => $owner->avatar_url ?? null,
                ] : null,
                'stats' => [
                    'members_accepted' => $acceptedMembers,
                    'users_filtered'   => $page->total(),
                ],
                'permissions' => $permissions,
                'created_at'  => optional($course->created_at)?->toISOString(),
                'updated_at'  => optional($course->updated_at)?->toISOString(),
            ],
            'filters' => [
                'q'        => $needle,
                'role'     => $roleF,
                'status'   => $statusF ?: ($isAdminLike ? 'all' : 'accepted'),
                'sort'     => $sort,
                'order'    => $order,
                'per_page' => $perPage,
            ],
            'pagination' => [
                'total'        => $page->total(),
                'per_page'     => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
            ],
            'users' => $items,
        ]);
    }

}

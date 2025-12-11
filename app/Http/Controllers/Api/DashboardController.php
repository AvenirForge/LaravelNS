<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Invitation;
use App\Models\Note;
use App\Models\Test;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as Http;

class DashboardController extends Controller
{
    public function getDashboard(Request $request): JsonResponse
    {
        $user = Auth::guard('api')->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], Http::HTTP_UNAUTHORIZED);
        }

        $limit = max(1, min(50, (int) $request->query('limit', 10)));
        $includes = explode(',', $request->query('include', 'stats,myCourses,memberCourses,recentActivities,invitations'));
        $includes = array_fill_keys($includes, true);

        $allowedCourseIds = $this->getAllowedCourseIds($user->id);

        $data = [];

        if (isset($includes['stats'])) {
            $data['stats'] = $this->getStats($user, $allowedCourseIds);
        }

        if (isset($includes['myCourses'])) {
            $data['myCourses'] = $this->getMyCourses($user->id, $request, $limit);
        }

        if (isset($includes['memberCourses'])) {
            $data['memberCourses'] = $this->getMemberCourses($user->id, $request, $limit);
        }

        if (isset($includes['recentActivities'])) {
            $data['recentActivities'] = $this->getRecentActivities($user->id, $allowedCourseIds, $request, $limit);
        }

        if (isset($includes['invitations'])) {
            $data['invitations'] = $this->getInvitations($user, $limit);
        }

        return response()->json([
            'meta' => [
                'requested_at' => now()->toIso8601String(),
                'included_widgets' => array_keys($includes),
            ],
            'data' => $data,
        ], Http::HTTP_OK);
    }

    private function getAllowedCourseIds(int $userId): array
    {
        $owned = Course::where('user_id', $userId)->pluck('id')->toArray();

        $member = DB::table('courses_users')
            ->where('user_id', $userId)
            ->whereIn('status', ['accepted', 'active', 'approved', 'joined'])
            ->pluck('course_id')
            ->toArray();

        return array_values(array_unique(array_merge($owned, $member)));
    }

    private function getStats(User $user, array $allowedCourseIds): array
    {
        $ownedCount = Course::where('user_id', $user->id)->count();
        $memberCount = count($allowedCourseIds) - $ownedCount;

        $notesCount = Note::where('user_id', $user->id)->count();
        $testsCount = Test::where('user_id', $user->id)->count();

        $canonicalEmail = $this->canonicalEmail($user->email);
        $pendingInvites = Invitation::where('status', 'pending')
            ->where(function (Builder $query) use ($canonicalEmail, $user) {
                $query->whereRaw('LOWER(TRIM(invited_email)) = ?', [$canonicalEmail])
                    ->orWhere('user_id', $user->id);
            })
            ->count();

        return [
            'courses_owned' => $ownedCount,
            'courses_member' => max(0, $memberCount),
            'notes_total' => $notesCount,
            'tests_total' => $testsCount,
            'invitations_pending' => $pendingInvites,
        ];
    }

    private function getMyCourses(int $userId, Request $request, int $limit): Collection
    {
        $queryStr = $request->query('courses_q');
        $sortCol = $request->query('courses_sort', 'updated_at');
        $sortDir = $request->query('courses_order', 'desc');

        if (!in_array($sortCol, ['title', 'created_at', 'updated_at'])) {
            $sortCol = 'updated_at';
        }

        return Course::where('user_id', $userId)
            ->when($queryStr, fn($q) => $q->where('title', 'like', "%{$queryStr}%"))
            ->orderBy($sortCol, $sortDir)
            ->limit($limit)
            ->get()
            ->map(fn($c) => $this->formatCourse($c, 'owner'));
    }

    private function getMemberCourses(int $userId, Request $request, int $limit): Collection
    {
        $queryStr = $request->query('courses_q');
        $sortCol = $request->query('courses_sort', 'updated_at');
        $sortDir = $request->query('courses_order', 'desc');

        if (!in_array($sortCol, ['title', 'created_at', 'updated_at'])) {
            $sortCol = 'updated_at';
        }

        return Course::query()
            ->join('courses_users', 'courses.id', '=', 'courses_users.course_id')
            ->where('courses_users.user_id', $userId)
            ->whereIn('courses_users.status', ['accepted', 'active', 'approved', 'joined'])
            ->where('courses.user_id', '!=', $userId)
            ->with('user:id,name,avatar')
            ->select('courses.*', 'courses_users.role as pivot_role')
            ->when($queryStr, fn($q) => $q->where('courses.title', 'like', "%{$queryStr}%"))
            ->orderBy("courses.$sortCol", $sortDir)
            ->limit($limit)
            ->get()
            ->map(function ($course) {
                $data = $this->formatCourse($course, $course->pivot_role);
                if ($course->user) {
                    $data['owner'] = [
                        'id' => $course->user->id,
                        'name' => $course->user->name,
                        'avatar_url' => $course->user->avatar_url,
                    ];
                }
                return $data;
            });
    }

    private function getRecentActivities(int $userId, array $allowedCourseIds, Request $request, int $limit): array
    {
        $queryStr = $request->query('activities_q');
        $type = $request->query('activities_type', 'all');
        $sortCol = $request->query('activities_sort', 'updated_at');
        $sortDir = $request->query('activities_order', 'desc');

        if (!in_array($sortCol, ['title', 'created_at', 'updated_at'])) {
            $sortCol = 'updated_at';
        }

        $notesQuery = Note::query()
            ->select([
                'id',
                'title',
                'description',
                'updated_at',
                'created_at',
                'user_id',
                DB::raw("'note' as type")
            ])
            ->where(function (Builder $q) use ($userId, $allowedCourseIds) {
                $q->where('user_id', $userId)
                    ->orWhereHas('courses', fn($cq) => $cq->whereIn('courses.id', $allowedCourseIds));
            })
            ->when($queryStr, function ($q) use ($queryStr) {
                $q->where(function ($sub) use ($queryStr) {
                    $sub->where('title', 'like', "%{$queryStr}%")
                        ->orWhere('description', 'like', "%{$queryStr}%");
                });
            });

        $testsQuery = Test::query()
            ->select([
                'id',
                'title',
                'description',
                'updated_at',
                'created_at',
                'user_id',
                DB::raw("'test' as type")
            ])
            ->where(function (Builder $q) use ($userId, $allowedCourseIds) {
                $q->where('user_id', $userId)
                    ->orWhereHas('courses', fn($cq) => $cq->whereIn('courses.id', $allowedCourseIds));
            })
            ->when($queryStr, function ($q) use ($queryStr) {
                $q->where(function ($sub) use ($queryStr) {
                    $sub->where('title', 'like', "%{$queryStr}%")
                        ->orWhere('description', 'like', "%{$queryStr}%");
                });
            });

        if ($type === 'note') {
            $unionQuery = $notesQuery;
        } elseif ($type === 'test') {
            $unionQuery = $testsQuery;
        } else {
            $unionQuery = $notesQuery->unionAll($testsQuery);
        }

        $paginator = $unionQuery->orderBy($sortCol, $sortDir)->paginate($limit);

        $items = $paginator->getCollection();
        $noteIds = $items->where('type', 'note')->pluck('id');
        $testIds = $items->where('type', 'test')->pluck('id');

        $notes = Note::with(['user:id,name,avatar', 'courses:id,title,avatar,type', 'files'])
            ->whereIn('id', $noteIds)
            ->get()
            ->keyBy('id');

        $tests = Test::with(['user:id,name,avatar', 'courses:id,title,avatar,type'])
            ->withCount('questions')
            ->whereIn('id', $testIds)
            ->get()
            ->keyBy('id');

        $hydrated = $items->map(function ($item) use ($notes, $tests) {
            $model = match ($item->type) {
                'note' => $notes->get($item->id),
                'test' => $tests->get($item->id),
                default => null,
            };

            if (!$model) return null;

            $data = $model->toArray();
            $data['type'] = $item->type;

            if ($model->relationLoaded('user') && $model->user) {
                $data['user']['avatar_url'] = $model->user->avatar_url;
            }

            if ($model->relationLoaded('courses')) {
                $data['courses'] = $model->courses->map(fn($c) => [
                    'id' => $c->id,
                    'title' => $c->title,
                    'type' => $c->type,
                    'avatar_url' => $c->avatar_url,
                ]);
            }

            return $data;
        })->filter()->values();

        return [
            'data' => $hydrated,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'next_page_url' => $paginator->nextPageUrl(),
                'prev_page_url' => $paginator->previousPageUrl(),
            ]
        ];
    }

    private function getInvitations(User $user, int $limit): Collection
    {
        $canonicalEmail = $this->canonicalEmail($user->email);

        return Invitation::where('status', 'pending')
            ->where(function (Builder $query) use ($canonicalEmail, $user) {
                $query->whereRaw('LOWER(TRIM(invited_email)) = ?', [$canonicalEmail])
                    ->orWhere('user_id', $user->id);
            })
            ->with(['course:id,title,avatar', 'inviter:id,name,avatar'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(fn($inv) => $this->formatInvitation($inv));
    }

    private function formatCourse(Course $course, ?string $role = null): array
    {
        $data = [
            'id' => $course->id,
            'title' => $course->title,
            'avatar_url' => $course->avatar_url,
            'type' => $course->type,
            'updated_at' => $course->updated_at?->toIso8601String(),
        ];

        if ($role) {
            $data['role'] = $role;
        }

        return $data;
    }

    private function formatInvitation(Invitation $inv): array
    {
        return [
            'id' => $inv->id,
            'token' => $inv->token,
            'status' => $inv->status,
            'role' => $inv->role,
            'expires_at' => $inv->expires_at?->toIso8601String(),
            'created_at' => $inv->created_at?->toIso8601String(),
            'course' => $inv->course ? [
                'id' => $inv->course->id,
                'title' => $inv->course->title,
                'avatar_url' => $inv->course->avatar_url,
            ] : null,
            'inviter' => $inv->inviter ? [
                'id' => $inv->inviter->id,
                'name' => $inv->inviter->name,
                'avatar_url' => $inv->inviter->avatar_url,
            ] : null,
        ];
    }

    private function canonicalEmail(string $email): string
    {
        $email = trim(mb_strtolower($email));
        if (!str_contains($email, '@')) return $email;

        [$local, $domain] = explode('@', $email, 2);
        $domainAscii = $domain;

        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii !== false) $domainAscii = $ascii;
        }

        if (in_array($domainAscii, ['gmail.com', 'googlemail.com'], true)) {
            $plusPos = strpos($local, '+');
            if ($plusPos !== false) $local = substr($local, 0, $plusPos);
            $local = str_replace('.', '', $local);
        }

        return $local.'@'.$domainAscii;
    }
}

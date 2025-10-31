<?php

namespace App\Http\Controllers;

use App\Models\Test;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use App\Models\Quiz;
use App\Models\Note;

class DashboardController extends Controller
{
    /**
     * Wyświetla pulpit użytkownika z połączoną listą quizów i notatek.
     *
     * @param Request $request
     * @return \Inertia\Response
     */
    public function dashboard(Request $request)
    {
        $user = Auth::user();

        // 1. Walidacja i pobranie filtrów z requestu
        //    Domyślnym typem jest 'test'
        $type = $request->input('type', 'test');

        // Bezpieczne sortowanie - dopuszczamy tylko kolumny wspólne lub logiczne
        $allowedSorts = ['title', 'updated_at', 'created_at'];
        $sortBy = $request->input('sort_by', 'updated_at');
        $sortBy = in_array($sortBy, $allowedSorts) ? $sortBy : 'updated_at';

        $sortDir = strtolower($request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $perPage = $request->input('per_page', 15);
        $searchTerm = $request->input('search', '');

        // 2. Budowanie bazowego zapytania (Query Builder) w zależności od typu
        $query = null;

        if ($type === 'note') {
            // Budujemy zapytanie dla Notatek
            // Ładujemy autora (user) i powiązane pliki (files)
            $query = Note::with(['user', 'files'])
                ->where('user_id', $user->id);
        } else {
            // Domyślnie (i dla 'test') budujemy zapytanie dla Testów
            $type = 'test'; // Upewnij się, że 'type' jest poprawny dla widoku
            // Ładujemy autora (user)
            $query = Test::with('user')
                ->where('user_id', $user->id);
        }

        // 3. Dynamiczne dodawanie filtrów (wyszukiwanie)
        // Oba modele (Test i Note) mają 'title' i 'description'
        if (!empty($searchTerm)) {
            $query->where(function ($q) use ($searchTerm) {
                $q->where('title', 'like', "%{$searchTerm}%")
                    ->orWhere('description', 'like', "%{$searchTerm}%");
            });
        }

        // 4. Dodawanie sortowania
        $query->orderBy($sortBy, $sortDir);

        // 5. Wykonanie zapytania i paginacja
        //    ->paginate() zwraca obiekt LengthAwarePaginator
        //    ->withQueryString() automatycznie dodaje wszystkie parametry (filtry, sortowanie)
        //      do linków paginacji.
        $items = $query->paginate($perPage)->withQueryString();

        // 6. Przekazanie danych do widoku Inertia
        return Inertia::render('Dashboard', [
            // 'items' zawiera teraz obiekt paginacji (data, links, total, etc.)
            'items' => $items,

            // Przekazujemy filtry z powrotem do frontendu,
            // aby mógł on utrzymać stan pól formularzy (np. pola search)
            'filters' => [
                'type' => $type,
                'search' => $searchTerm,
                'sort_by' => $sortBy,
                'sort_dir' => $sortDir,
                'per_page' => (int)$perPage,
            ],
        ]);
    }
}

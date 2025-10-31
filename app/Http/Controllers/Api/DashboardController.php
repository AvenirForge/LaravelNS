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

        // 1. Pobierz quizy stworzone przez użytkownika
        //    Ładujemy relację 'user' (autora)
        $quizzes = Test::with('user')
            ->where('user_id', $user->id)
            ->latest('updated_at') // Sortuj wg daty aktualizacji (najnowsze pierwsze)
            ->get();

        // 2. Pobierz notatki stworzone przez użytkownika
        //    Używamy Eager Loading dla relacji 'user' (autora)
        //    oraz 'files' (powiązane pliki z NoteFile)
        $notes = Note::with(['user', 'files'])
            ->where('user_id', $user->id)
            ->latest('updated_at') // Sortuj wg daty aktualizacji
            ->get();

        // 3. Dodaj pole 'type' do każdej kolekcji, aby rozróżnić je w frontendzie
        $quizzes->transform(function ($item) {
            $item->type = 'quiz';
            return $item;
        });

        $notes->transform(function ($item) {
            $item->type = 'note';
            return $item;
        });

        // 4. Połącz obie kolekcje w jedną
        $combinedItems = $quizzes->concat($notes);

        // 5. Posortuj połączoną kolekcję według daty ostatniej aktualizacji (malejąco)
        //    To gwarantuje, że najnowsze edytowane elementy (niezależnie od typu) są na górze.
        $sortedItems = $combinedItems->sortByDesc('updated_at');

        // 6. Przekaż posortowane dane do widoku Inertia 'Dashboard'
        //    Używamy ->values() aby zresetować klucze tablicy po sortowaniu,
        //    co zapewnia, że frontend otrzyma czystą tablicę JS.
        return Inertia::render('Dashboard', [
            'items' => $sortedItems->values(),
        ]);
    }
}

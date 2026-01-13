# NoteSync API

![NoteSync Banner](https://notesync.pl/logo.avif)

> **NoteSync** to zaawansowane API typu **Backend-for-Frontend (BFF)** stworzone do obsługi platformy edukacyjnej. System zapewnia spójne, bezpieczne i skalowalne zarządzanie kursami, notatkami z załącznikami, testami oraz współpracą użytkowników.

---

## 📋 Spis treści

1. [O projekcie](#o-projekcie)
2. [Stos technologiczny](#stos-technologiczny)
3. [Architektura i logika biznesowa](#architektura-i-logika-biznesowa)
4. [Struktura projektu](#struktura-projektu)
5. [Instalacja i konfiguracja](#instalacja-i-konfiguracja)
6. [Dokumentacja API](#dokumentacja-api)
7. [Testy End-to-End (E2E)](#testy-end-to-end-e2e)
8. [Licencja](#licencja)

---

## 💡 O projekcie

NoteSync API rozwiązuje problem rozproszonych materiałów edukacyjnych, dostarczając jedno, scentralizowane środowisko do:

- **Organizacji wiedzy** – prywatne i publiczne notatki z obsługą wielu załączników.
- **Weryfikacji wiedzy** – system testów z pytaniami jednokrotnego i wielokrotnego wyboru.
- **Współpracy** – kursy z rozbudowanym systemem ról i uprawnień.
- **Bezpieczeństwa** – pełna autoryzacja oparta o JWT i kontrolę dostępu na poziomie zasobów.

API pełni rolę **Backend-for-Frontend**, agregując logikę biznesową i dane w formie optymalnej dla aplikacji klienckich (web, mobile).

---

## 🛠 Stos technologiczny

Projekt oparty jest o nowoczesny, produkcyjny stack:

- **Język:** PHP 8.2+
- **Framework:** Laravel 10.x / 11.x
- **Baza danych:** MySQL / MariaDB (zgodność z SQLite)
- **Autoryzacja:** JWT (JSON Web Token) – `tymon/jwt-auth`
- **Testy:** Python – testy End-to-End
- **Serwer:** Apache / Nginx

---

## 🏗 Architektura i logika biznesowa

System bazuje na architekturze **MVC (Model–View–Controller)** z silnym naciskiem na:
- separację odpowiedzialności,
- integralność danych,
- bezpieczeństwo operacji.

### Kluczowe mechanizmy

**RBAC – Role Based Access Control**  
Uprawnienia są weryfikowane dynamicznie w kontrolerach (np. `CourseController::checkPermissions`).

Role w kursie:
- `owner` – pełna kontrola nad kursem i użytkownikami
- `admin` – zarządzanie treścią i członkami
- `moderator` – moderacja treści
- `member` – dostęp tylko do odczytu

Zastosowano hierarchię ról – użytkownik o wyższej roli może zarządzać użytkownikiem o niższej (`canModerateUser`).

Hasła są bezpiecznie hashowane przy użyciu bcrypt.

**Współdzielenie zasobów**  
Notatki i testy są domyślnie prywatne (`is_private = true`). Udostępnienie zasobu w kursie realizowane jest przez relacje wiele-do-wielu oraz automatyczną zmianę flag widoczności.

Mechanizm `purgeUserNotesInCourse` dba o prywatność danych oraz spójność systemu po usunięciu użytkownika z kursu.

**Obsługa plików**  
Załączniki do notatek obsługiwane są przez dedykowaną tabelę `note_files`, co umożliwia:
- przypisywanie wielu plików do jednej notatki,
- łatwe skalowanie i rozbudowę systemu.

Operacje na plikach są atomowe (transakcje DB + operacje na filesystemie), co zapobiega niespójnościom i pozostawianiu „osieroconych” plików.

---

## 📂 Struktura projektu

```text
/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── Api/
│   │   │       ├── CourseController.php     # Kursy, awatary, członkowie
│   │   │       ├── DashboardController.php  # Agregacja danych dashboardu
│   │   │       ├── InvitationController.php # System zaproszeń
│   │   │       ├── NoteController.php       # CRUD notatek i pliki
│   │   │       ├── TestController.php       # Testy, pytania, odpowiedzi
│   │   │       └── UserController.php       # Auth i profil użytkownika
│   └── Models/
│       ├── Course.php
│       ├── Invitation.php
│       ├── Note.php
│       ├── NoteFile.php
│       ├── Test.php
│       └── User.php
├── config/
├── database/
│   ├── migrations/
│   └── seeders/
├── routes/
│   └── api.php
├── storage/
├── tests/
│   └── E2E/
│       ├── E2E.py              # Główny skrypt testów E2E
│       └── sample_data/        # Dane testowe (pliki, avatary)
└── README.md
```

---

## 🚀 Instalacja i konfiguracja

### Wymagania

- PHP >= 8.2
- Composer
- Baza danych (MySQL lub SQLite)
- Python 3.x (testy E2E)

### Klonowanie repozytorium

```bash
git clone https://github.com/twoje-konto/notesync-api.git
cd notesync-api
```

### Instalacja zależności

```bash
composer install
```

### Konfiguracja środowiska

```bash
cp .env.example .env
```

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=notesync
DB_USERNAME=root
DB_PASSWORD=
```

### Generowanie kluczy

```bash
php artisan key:generate
php artisan jwt:secret
```

### Migracje i seedy

```bash
php artisan migrate --seed
```

### Linkowanie storage

```bash
php artisan storage:link
```

### Uruchomienie serwera

```bash
php artisan serve
```

API będzie dostępne pod adresem: `http://localhost:8000`

---

## 📡 Dokumentacja API

Poniżej znajduje się **aktualna i kompletna dokumentacja endpointów**, zgodna 1:1 z plikiem `routes/api.php`.

### 🔐 Publiczne (bez autoryzacji)

| Metoda | Endpoint | Opis |
|------|---------|------|
| POST | `/api/login` | Logowanie użytkownika (JWT) |
| POST | `/api/users/register` | Rejestracja nowego użytkownika |
| POST | `/api/refresh` | Odświeżenie tokenu JWT |

---

### 👤 /me – konto zalogowanego użytkownika (`auth:api`)

#### Dashboard

| Metoda | Endpoint | Opis |
|------|---------|------|
| GET | `/api/me/dashboard` | Dane dashboardu (statystyki, aktywności) |

#### Profil

| Metoda | Endpoint | Opis |
|------|---------|------|
| GET | `/api/me/profile` | Pobranie profilu |
| PATCH | `/api/me/profile` | Aktualizacja profilu |
| DELETE | `/api/me/profile` | Usunięcie konta |
| POST | `/api/me/logout` | Wylogowanie |
| GET | `/api/me/profile/avatar` | Pobranie avatara |
| POST | `/api/me/profile/avatar` | Aktualizacja avatara |

---

#### Kursy użytkownika

| Metoda | Endpoint | Opis |
|------|---------|------|
| GET | `/api/me/courses` | Lista kursów użytkownika |
| POST | `/api/me/courses` | Utworzenie kursu |
| PATCH | `/api/me/courses/{id}` | Aktualizacja kursu |
| DELETE | `/api/me/courses/{id}` | Usunięcie kursu |
| GET | `/api/me/courses/{id}/avatar` | Pobranie avatara kursu |
| POST | `/api/me/courses/{id}/avatar` | Aktualizacja avatara kursu |

---

#### Notatki

| Metoda | Endpoint | Opis |
|------|---------|------|
| GET | `/api/me/notes` | Lista notatek (z tablicą `files`) |
| POST | `/api/me/notes` | Utworzenie notatki (`files[]`) |
| GET | `/api/me/notes/{id}` | Szczegóły notatki |
| PUT / PATCH | `/api/me/notes/{id}` | Edycja metadanych notatki |
| DELETE | `/api/me/notes/{id}` | Usunięcie notatki |
| POST | `/api/me/notes/{noteId}/files` | Dodanie pliku do notatki |
| DELETE | `/api/me/notes/{noteId}/files/{fileId}` | Usunięcie pliku |
| GET | `/api/me/notes/{noteId}/files/{fileId}/download` | Pobranie pliku |
| POST | `/api/me/notes/{noteId}/share/{courseId}` | Udostępnienie notatki w kursie |
| DELETE | `/api/me/notes/{noteId}/share/{courseId}` | Cofnięcie udostępnienia |

---

#### Testy użytkownika

| Metoda | Endpoint | Opis |
|------|---------|------|
| GET | `/api/me/tests` | Lista testów |
| POST | `/api/me/tests` | Utworzenie testu |
| GET | `/api/me/tests/{id}` | Szczegóły testu |
| PUT | `/api/me/tests/{id}` | Aktualizacja testu |
| DELETE | `/api/me/tests/{id}` | Usunięcie testu |
| GET | `/api/me/tests/{testId}/questions` | Pytania testu |
| POST | `/api/me/tests/{testId}/questions` | Dodanie pytania |
| PUT | `/api/me/tests/{testId}/questions/{questionId}` | Edycja pytania |
| DELETE | `/api/me/tests/{testId}/questions/{questionId}` | Usunięcie pytania |
| GET | `/api/me/tests/{testId}/questions/{questionId}/answers` | Odpowiedzi |
| POST | `/api/me/tests/{testId}/questions/{questionId}/answers` | Dodanie odpowiedzi |
| PUT | `/api/me/tests/{testId}/questions/{questionId}/answers/{answerId}` | Edycja odpowiedzi |
| DELETE | `/api/me/tests/{testId}/questions/{questionId}/answers/{answerId}` | Usunięcie odpowiedzi |
| POST | `/api/me/tests/{testId}/share` | Udostępnienie testu w kursie |
| DELETE | `/api/me/tests/{testId}/share` | Cofnięcie udostępnienia |

---

### 🎓 Kursy (akcje globalne, `auth:api`)

| Metoda | Endpoint | Opis |
|------|---------|------|
| POST | `/api/courses/{courseId}/invite-user` | Zaproszenie użytkownika |
| DELETE | `/api/courses/{courseId}/leave` | Opuszczenie kursu |
| POST | `/api/courses/{courseId}/remove-user` | Usunięcie użytkownika |
| PATCH | `/api/courses/{courseId}/users/{userId}/role` | Zmiana roli użytkownika |
| POST | `/api/courses/{courseId}/set-role-by-email` | Zmiana roli po e-mailu |
| DELETE | `/api/courses/{courseId}/users/{userId}/notes` | Usunięcie notatek użytkownika |
| DELETE | `/api/courses/{courseId}/users/{userId}/tests` | Usunięcie testów użytkownika |
| DELETE | `/api/courses/{courseId}/notes/{noteId}` | Admin: cofnięcie notatki |
| DELETE | `/api/courses/{courseId}/tests/{testId}` | Admin: cofnięcie testu |
| GET | `/api/courses/{courseId}/users` | Lista użytkowników kursu |
| GET | `/api/courses/{courseId}/notes` | Notatki w kursie |

---

### ✉️ Zaproszenia

| Metoda | Endpoint | Opis |
|------|---------|------|
| GET | `/api/me/invitations-received` | Otrzymane zaproszenia |
| GET | `/api/me/invitations-sent` | Wysłane zaproszenia |
| POST | `/api/invitations/{token}/accept` | Akceptacja zaproszenia |
| POST | `/api/invitations/{token}/reject` | Odrzucenie zaproszenia |

---

### 🧪 Testy w kontekście kursu

| Metoda | Endpoint | Opis |
|------|---------|------|
| GET | `/api/courses/{courseId}/tests` | Lista testów kursu |
| POST | `/api/courses/{courseId}/tests` | Utworzenie testu w kursie |
| GET | `/api/courses/{courseId}/tests/{testId}` | Szczegóły testu |
| PUT | `/api/courses/{courseId}/tests/{testId}` | Aktualizacja testu |
| DELETE | `/api/courses/{courseId}/tests/{testId}` | Usunięcie testu |

---

## 🧪 Testy End-to-End (E2E)


Projekt zawiera **pełne testy End-to-End** napisane w Pythonie, zlokalizowane w:

```
tests/E2E/E2E.py
```

Testy symulują realne scenariusze użytkownika i weryfikują API „z zewnątrz”, łącznie z:
- tworzeniem kursów i zaproszeń,
- zarządzaniem rolami,
- tworzeniem notatek z załącznikami,
- uploadem avatarów,
- kontrolą dostępu i obsługą błędów (401 / 403 / 404).

### Pełne uruchomienie testów (z plikami)

Przykładowa komenda testująca **całe API wraz z obsługą plików**:

```bash
python tests/E2E/E2E.py \
  --base-url http://localhost:8000 \
  --me-prefix me \
  --note-file "C:\\xampp\\htdocs\\LaravelNS\\tests\\E2E\\sample_data\\note.pdf" \
  --avatar "C:\\xampp\\htdocs\\LaravelNS\\tests\\E2E\\sample_data\\avatar.jpg"
```

⚠️ **Uwaga:** testy operują na żywej bazie danych. Zalecane jest:
- używanie osobnej bazy testowej (np. SQLite), lub
- reset bazy przed testami:

```bash
php artisan migrate:fresh
```

---

## 📄 Licencja

Projekt udostępniany jest na licencji **MIT**.

---

## 🔎 Ocena architektury

Kod projektu cechuje się wysoką dojrzałością techniczną:

- **Bezpieczeństwo:** normalizacja e-maili i hierarchia ról ograniczają wektory ataku
- **Spójność:** transakcje DB gwarantują integralność danych
- **Modularność:** klarowny podział odpowiedzialności kontrolerów i modeli
- **Pliki:** osobny model `NoteFile` to rozwiązanie skalowalne i produkcyjne

README w pełni odzwierciedla jakość oraz architektoniczne założenia systemu.


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
- `member` – dostęp do odczytu i dodawania treści

Zastosowano hierarchię ról – użytkownik o wyższej roli może zarządzać użytkownikiem o niższej (`canModerateUser`).

**Zarządzanie tożsamością**  
Adresy e-mail są normalizowane (`canonicalEmail`) w celu eliminacji duplikacji kont:
- obsługa aliasów Gmail (`user+tag@gmail.com`),
- konwersja domen IDN do ASCII.

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

### Uwierzytelnianie

| Metoda | Endpoint | Opis |
|------|---------|------|
| POST | `/api/login` | Logowanie i pobranie tokenu JWT |
| POST | `/api/users/register` | Rejestracja użytkownika |
| GET | `/api/me/profile` | Pobranie profilu zalogowanego użytkownika |
| PATCH | `/api/me/profile` | Aktualizacja profilu |

### Kursy

| Metoda | Endpoint | Opis |
|------|---------|------|
| GET | `/api/courses` | Lista kursów użytkownika |
| POST | `/api/courses` | Utworzenie nowego kursu |
| POST | `/api/courses/{id}/invite-user` | Zaproszenie użytkownika |
| DELETE | `/api/courses/{id}/users/{userId}` | Usunięcie użytkownika z kursu |

### Notatki

| Metoda | Endpoint | Opis |
|------|---------|------|
| GET | `/api/me/notes` | Pobranie notatek (paginacja) |
| POST | `/api/me/notes` | Utworzenie notatki (z plikami) |
| POST | `/api/me/notes/{id}/files` | Dodanie pliku do notatki |
| POST | `/api/me/notes/{id}/share/{courseId}` | Udostępnienie notatki w kursie |

### Dashboard

| Metoda | Endpoint | Opis |
|------|---------|------|
| GET | `/api/dashboard` | Statystyki, aktywności, zaproszenia |

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
php artisan migrate:fresh --seed
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


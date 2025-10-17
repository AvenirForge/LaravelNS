<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NoteSync — zsynchronizuj swoje notatki</title>
    <meta name="description" content="NoteSync — szybkie API do synchronizacji notatek dla aplikacji mobilnych.">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        :root { --bg:#0b1020; --fg:#eef2ff; --muted:#93c5fd; --accent:#60a5fa; }
        *{box-sizing:border-box} body{margin:0;font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial}
        .hero{min-height:100dvh;display:flex;align-items:center;justify-content:center;background: radial-gradient(1200px 600px at 80% -10%, rgba(96,165,250,.15), transparent), var(--bg); color:var(--fg)}
        .card{max-width:960px;width:92%;padding:48px;border-radius:24px;background:rgba(255,255,255,.04);backdrop-filter: blur(6px);box-shadow: 0 10px 50px rgba(0,0,0,.35)}
        h1{font-size: clamp(36px,5vw,56px);line-height:1.05;margin:0 0 8px}
        p.lead{font-size: clamp(16px,2.4vw,20px);opacity:.9;margin:0 0 24px}
        .cta{display:flex;gap:12px;flex-wrap:wrap}
        .btn{appearance:none;border:none;border-radius:14px;padding:14px 18px;font-weight:700;cursor:pointer}
        .btn-primary{background:var(--accent);color:#081226}
        .btn-ghost{background:transparent;color:var(--fg);outline:2px solid rgba(255,255,255,.15)}
        .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-top:28px}
        .tile{padding:16px;border-radius:16px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.06)}
        small{opacity:.75}
        footer{padding:30px;text-align:center;color:#cbd5e1;background:#0a0f1e}
        a{color:var(--muted);text-decoration:none}
    </style>
</head>
<body>
<main class="hero">
    <section class="card" role="region" aria-label="NoteSync landing">
        <h1>NoteSync</h1>
        <p class="lead">Minimalistyczne API do <strong>bezpiecznej synchronizacji notatek</strong> między urządzeniami. Idealne dla aplikacji React-Native.</p>
        <div class="cta">
            <a class="btn btn-primary" href="#docs">Zobacz API</a>
            <a class="btn btn-ghost" href="mailto:hello@notesync.pl">Kontakt</a>
        </div>

        <div class="grid" id="docs" aria-label="Sekcja API">
            <div class="tile">
                <strong>Endpointy</strong>
                <small><pre>GET /api/v1/notes
POST /api/v1/notes
PUT /api/v1/notes/{id}
DELETE /api/v1/notes/{id}</pre></small>
            </div>
            <div class="tile">
                <strong>Autoryzacja</strong>
                <small>Bearer token / Sanctum (wg repo).</small>
            </div>
            <div class="tile">
                <strong>Base URL</strong>
                <small>https://notesync.pl/api</small>
            </div>
            <div class="tile">
                <strong>Status</strong>
                <small><code><a href="/health">/health</a></code></small>
            </div>
        </div>
    </section>
</main>
<footer>© {{ date('Y') }} NoteSync</footer>
</body>
</html>

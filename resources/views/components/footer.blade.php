<style>
    footer {
        border-top: 1px solid var(--border);
        padding: 60px 0;
        text-align: center;
        color: var(--fg-muted);
        font-size: 14px;
        background: var(--bg2);
    }
    .footer-content {
        display: flex; flex-direction: column; align-items: center; gap: 24px;
    }
    .footer-brand {
        font-weight: 800; font-size: 24px; color: var(--fg); letter-spacing: -1px;
    }
</style>

<footer>
    <div class="container footer-content">
        <div class="footer-brand">NoteSync</div>
        <div>
            © {{ now()->year }} NoteSync. Native Performance.
        </div>
    </div>
</footer>

<div class="app-container">
    <div class="page-card">
        <h1 class="page-title"><?= htmlspecialchars($title ?? 'Welcome') ?></h1>
        <p class="page-lead"><?= htmlspecialchars($message ?? 'Hello World!') ?></p>

        <div class="notice">
            <strong>🎉 Congratulations!</strong> Your PHP application is running successfully.
        </div>

        <div class="cards">
            <div class="card">
                <h2>📦 Installed Packages</h2>
                <ul class="card-list">
                    <li>✅ Core PHP Framework</li>
                    <li>✅ PHP Router</li>
                </ul>
            </div>
            <div class="card">
                <h2>🚀 Next Steps</h2>
                <ul class="card-list">
                    <li>Create your controllers</li>
                    <li>Add your views</li>
                    <li>Configure your database</li>
                </ul>
            </div>
        </div>
    </div>
</div>

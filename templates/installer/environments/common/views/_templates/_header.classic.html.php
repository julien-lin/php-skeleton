<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'Application') ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="site-body">
    <?php
    // Afficher les messages flash (success et error) depuis la session
    use JulienLinard\Core\Session\Session;
    $headerSuccess = Session::getFlash('success');
    $headerError = Session::getFlash('error');
    ?>

    <?php if ($headerSuccess): ?>
    <div class="flash-wrapper">
        <div class="flash flash--success">
            <p><?= htmlspecialchars($headerSuccess) ?></p>
        </div>
    </div>
    <script>
        setTimeout(() => document.querySelector('.flash--success')?.closest('.flash-wrapper')?.remove(), 5000);
    </script>
    <?php endif; ?>

    <?php if ($headerError): ?>
    <div class="flash-wrapper">
        <div class="flash flash--error">
            <p><?= htmlspecialchars($headerError) ?></p>
        </div>
    </div>
    <script>
        setTimeout(() => document.querySelector('.flash--error')?.closest('.flash-wrapper')?.remove(), 5000);
    </script>
    <?php endif; ?>

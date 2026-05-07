<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'RMUTP Project Tracker') ?></title>
</head>
<body>
    <?php require __DIR__ . '/../components/navbar.php'; ?>
    <main>
        <?php require $contentFile ?? __DIR__ . '/../components/placeholder.php'; ?>
    </main>
</body>
</html>

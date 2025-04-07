<!DOCTYPE html>
<html>
<head>
    <title>404 Not Found</title>
    <style>body{font-family:sans-serif;padding:20px;background-color:#f8f8f8;color:#333}h1{color:#6c757d;border-bottom:1px solid #eee;padding-bottom:10px}p{font-size:1.1em}</style>
</head>
<body>
    <h1>404 - Not Found</h1>
    <p>Sorry, the page you are looking for could not be found.</p>
    <?php if (isset($debug) && $debug && isset($message) && $message !== 'Route Not Found' && $message !== 'View not found') { ?>
        <p><strong>Details:</strong> <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
    <?php } ?>
</body>
</html>
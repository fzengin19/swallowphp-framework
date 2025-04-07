<!DOCTYPE html>
<html>
<head>
    <title>500 Internal Server Error</title>
    <style>body{font-family:sans-serif;padding:20px;background-color:#f8f8f8;color:#333}h1{color:#d9534f;border-bottom:1px solid #eee;padding-bottom:10px}p{font-size:1.1em}pre{background-color:#eee;border:1px solid #ccc;padding:10px;overflow-x:auto;font-size:0.9em;line-height:1.4em;white-space:pre-wrap;word-wrap:break-word}</style>
</head>
<body>
    <h1>500 - Internal Server Error</h1>
    <p>Sorry, something went wrong on our servers.</p>
    <?php if (isset($debug) && $debug && isset($exception)) { ?>
        <hr><h2>Details</h2>
        <p><strong>Exception:</strong> <?= htmlspecialchars(get_class($exception), ENT_QUOTES, 'UTF-8') ?></p>
        <p><strong>Message:</strong> <?= htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8') ?></p>
        <p><strong>File:</strong> <?= htmlspecialchars($exception->getFile(), ENT_QUOTES, 'UTF-8') ?></p>
        <p><strong>Line:</strong> <?= htmlspecialchars($exception->getLine(), ENT_QUOTES, 'UTF-8') ?></p>
        <h3>Trace:</h3><pre><?= htmlspecialchars($exception->getTraceAsString(), ENT_QUOTES, 'UTF-8') ?></pre>
    <?php } ?>
</body>
</html>
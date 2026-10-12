<?php require_once __DIR__.'/functions.php'; ?>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php foreach (['bootstrap.min', 'common', 'header', 'vmenu', 'body', 'footer'] as $stylesheet): ?>
  <link rel="stylesheet" href="css/<?= $stylesheet ?>.css">
  <?php endforeach ?>
  <?php if (!empty($portalStyles)): ?><link rel="stylesheet" href="css/portal.css"><?php endif ?>
  <title>Еринские Высоты / <?= htmlspecialchars($pageTitle ?? page_title($_SERVER['REQUEST_URI'] ?? '/'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
</head>
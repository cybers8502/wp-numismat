<!DOCTYPE html>
<html lang="uk">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />

  <meta name="viewport" content="user-scalable=no, width=device-width, initial-scale=1, maximum-scale=1">

  <!-- Headless backend - no real public-facing frontend content here (see
       README.md), so nothing to index or share on social media. -->
  <meta name="robots" content="noindex, nofollow">

  <?php wp_head() ?>

  <title><?= esc_html(get_bloginfo('name')) ?> | <?= esc_html(get_bloginfo('description')) ?></title>

</head>
<body data-action="<?= esc_url(admin_url('admin-ajax.php')) ?>">

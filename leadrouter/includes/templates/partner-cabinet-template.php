<?php
/**
 * Мінімальний шаблон сторінки кабінету партнера.
 *
 * Свідомо НЕ підключає header.php/footer.php теми cargo — щоб уникнути
 * Webflow-стилів і колізій із Tabler. На цій сторінці вантажиться лише
 * Tabler (+ бренд-тема), які enqueue-ляться у LR_Partner_Portal::enqueue_assets().
 *
 * wp_head()/wp_footer() лишаємо для коректної роботи WP (enqueue, плагіни).
 * Шрифти Onest/Anybody — з Google Fonts (як і основна тема).
 */

defined('ABSPATH') || exit;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family=Anybody:wght@600;700;800&family=Onest:wght@400;500;600;700&display=swap">
    <?php wp_head(); ?>
</head>
<body <?php body_class('lr-cabinet-page'); ?>>
<?php
// Рендеримо контент сторінки (там shortcode [lr_partner_cabinet],
// який сам обгортає все в .lr-cabinet).
while (have_posts()) {
    the_post();
    the_content();
}
?>
<?php wp_footer(); ?>
</body>
</html>

<?php

declare(strict_types=1);

/**
 * Страница с одним сообщением и предложением действия.
 *
 * Отказ рисуется баннером из шаблона: появление ошибки здесь и есть сообщение,
 * а не украшение вокруг неё. Текст отказа выводит оболочка — один на все
 * страницы вне панели, — поэтому здесь только предложение действия.
 *
 * @var \MyEdu\Core\View $view
 * @var ?array{label:string,url:string} $action
 */
?>
<div class="auth-card">
    <?php if (!empty($action)): ?>
        <p class="auth-subtitle"><?= $view->e($action['label']) ?></p>
        <a class="btn btn-primary btn-lg btn-block"
           href="<?= $view->e($action['url']) ?>"><?= $view->e($action['label']) ?></a>
    <?php endif; ?>
</div>
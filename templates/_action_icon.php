<?php

declare(strict_types=1);

/**
 * Значок действия: рисует значок из карты `templates/_action_icons.php`.
 *
 * Оформление значков меню (`_panel_icon.php`) и значков действий разошлось
 * намеренно: у меню набор шаблона gentelella, у действий свои контуры. Карты
 * разные, а порядок вызова одинаковый — задал имя, получил значок.
 *
 * Имя обязательно: у значка действия нет запасного варианта, и молчаливый
 * «какой-нибудь значок вместо» означал бы кнопку, которая выглядит работающей
 * и не делает ничего.
 *
 * Ожидает: $icon — имя значка.
 *
 * @var string $icon
 */

/** @var array<string,string> $actionIcons */
$actionIcons = require __DIR__ . '/_action_icons.php';

$name = (string) $icon;

if (!isset($actionIcons[$name])) {
    throw new RuntimeException('Значок действия не найден: ' . $name);
}

echo $actionIcons[$name];

<?php

declare(strict_types=1);

namespace MyEdu\Plugin;

use MyEdu\Auth\Auth;
use MyEdu\Core\App;
use MyEdu\Core\Config;
use MyEdu\Core\Csrf;
use MyEdu\Core\Database;
use MyEdu\Core\DatabaseDump;
use MyEdu\Core\Session;
use MyEdu\Core\View;
use MyEdu\Repository\AssignmentRepository;
use MyEdu\Repository\RatingRepository;

/**
 * То, что плагин имеет право брать у приложения.
 *
 * Раньше плагин получал контейнер `App` целиком, и этим «можно было всё»:
 * прочитать любую настройку, поменять её, дотянуться до чужого плагина. Формально
 * плагин — обычная программа на PHP с полным доступом к файлам и базе, и об этом
 * честно сказано на странице плагинов. Но держать в руках у каждого плагина
 * весь контейнер — значит отдавать ему ещё и то, о чём владелец плагина и не
 * подозревает: например, возможность переписать чужую настройку и не заметить
 * этого.
 *
 * Поэтому плагин получает не контейнер, а этот список. Всё, что здесь есть,
 * плагину действительно нужно: база, вид, сессия, защитное поле, кто смотрит
 * страницу, копия базы перед миграциями и разбор конфигурации. Чего нет — не
 * достаётся в принципе.
 *
 * `PluginManager` отдаётся отдельным методом и тоже узким: плагину нужен свой
 * префикс и свой каталог миграций, а реестр остальных плагинов ему не нужен.
 */
final class PluginServices
{
    public function __construct(
        private readonly App $app,
        /**
         * `id` этого плагина.
         *
         * Нужен не для красоты: по нему плагин узнаёт себя в манифесте. Раньше
         * `id`, `name` и `version` были написаны и в классе, и в `plugin.json`,
         * и расходились при первом же переименовании — причём тихо: плагин
         * называл себя одной версией, а обновления приходили по другой.
         */
        private readonly string $pluginId = ''
    ) {
    }

    /**
     * Сведения о плагине из манифеста.
     *
     * Единственный источник: класс их не повторяет, а значит и разойтись им
     * больше негде.
     */
    public function manifest(): ?PluginDescriptor
    {
        return $this->app->get(PluginManager::class)->descriptor($this->pluginId);
    }

    public function database(): Database
    {
        return $this->app->get(Database::class);
    }

    public function view(): View
    {
        return $this->app->get(View::class);
    }

    public function session(): Session
    {
        return $this->app->get(Session::class);
    }

    public function csrf(): Csrf
    {
        return $this->app->get(Csrf::class);
    }

    /** Кто смотрит страницу: роль, права, собственные плагины. */
    public function auth(): Auth
    {
        return $this->app->get(Auth::class);
    }

    /**
     * Настройки — читать.
     *
     * Объект конфигурации отдаётся копией: `Config` менять нельзя, иначе плагин,
     * пожелавший «поправить настройку», менял её у всего сайта на время своего
     * запроса — и только у себя, что хуже всего: в журнале изменение не
     * оставалось, а поведение уезжало.
     */
    public function config(): Config
    {
        return new Config($this->app->config()->all());
    }

    /** Копия базы, снимаемая перед миграциями плагина. */
    public function dump(): DatabaseDump
    {
        return $this->app->get(DatabaseDump::class);
    }

    /** Работы: позиция и её результат. */
    public function assignments(): AssignmentRepository
    {
        return $this->app->get(AssignmentRepository::class);
    }

    /** Отзывы о работах. */
    public function ratings(): RatingRepository
    {
        return $this->app->get(RatingRepository::class);
    }

    /**
     * Реестр — только два ответа, которые нужны плагину.
     *
     * Список плагинов, их настройки и включённость плагину не нужны, а через
     * `PluginManager` он мог бы, например, выключить соседний плагин.
     */
    public function ownPlugin(string $id): PluginLocator
    {
        return new PluginLocator($this->app->get(PluginManager::class), $id);
    }
}
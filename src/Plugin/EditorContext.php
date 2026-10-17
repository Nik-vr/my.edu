<?php

declare(strict_types=1);

namespace MyEdu\Plugin;

use MyEdu\Auth\Auth;
use MyEdu\Auth\User;
use MyEdu\Core\Csrf;
use MyEdu\Core\View;
use MyEdu\Ui\Ui;

/**
 * Что плагину известно о редакторе задания.
 *
 * Ядро намеренно отдаёт мало: всё, чего плагин не знает, — это его собственные
 * данные. Зависимости передаются явно, а не достаются из сервис-контейнера,
 * иначе плагин незаметно начнёт зависеть от внутренностей ядра.
 */
final class EditorContext
{
    /**
     * @param array<string,mixed> $task данные задания из базы плагина; null — создаётся новое
     * @param list<TaskKind>     $kinds виды заданий, объявленные плагином
     * @param string             $backUrl куда возвращаться после сохранения; пусто — возврата нет
     * @param bool               $assignmentPublished опубликована ли работа, в которой лежит
     *                                             задание. Плагин о статусе работы не знает
     *                                             и догадываться не должен: у задания свой
     *                                             статус — «черновик» или «идёт проверка», —
     *                                             и по нему публикацию работы не определить.
     * @param int|null           $itemId номер позиции работы, которой принадлежит задание;
     *                                           нужно плагину, у которого своя цена, и
     *                                           который не может знать номер чужой позиции
     *                                           по одной лишь странице редактора
     * @param string             $closeUrl адрес редактора работы без открытого задания —
     *                                          закрыть редактор. Отличается от backUrl: тот
     *                                          ведёт обратно с открытым заданием, а закрыть
     *                                          значит уйти без него
     */
    public function __construct(
        public readonly ?TaskRef $task,
        public readonly string $kind,
        public readonly array $taskData,
        public readonly array $kinds,
        public readonly ?User $teacher,
        public readonly View $view,
        public readonly Csrf $csrf,
        public readonly Auth $auth,
        public readonly string $backUrl = '',
        public readonly bool $assignmentPublished = false,
        public readonly ?int $itemId = null,
        public readonly string $closeUrl = ''
    ) {
    }

    /** Редактор открыт для создания нового задания. */
    public function isNew(): bool
    {
        return $this->task === null;
    }

    /**
     * Типовые элементы интерфейса: поля, редактор, загрузка картинок, сообщения.
     *
     * Хук обязателен для плагина: рисовать свои формы не нужно и нельзя. Плагин,
     * который соберёт разметку сам, рано или поздно покажет поле, которого нет в
     * ядре, — и расхождение придётся искать по скриншотам.
     */
    public function ui(): Ui
    {
        return $this->view->ui();
    }
}
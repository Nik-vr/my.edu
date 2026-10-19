<?php

declare(strict_types=1);

/**
 * Каталог учебных предметов.
 *
 * Страница по образцу gentelella/tables.html: заголовок с кнопкой справа,
 * таблица с поиском и сортировкой, действия в последнем столбце. Кнопки Export
 * из образца не переносятся: выгрузки у предметов нет, а кнопка, которая
 * ничего не делает, хуже отсутствующей.
 *
 * Название предмета правится прямо в строке — так же, как ФИО ученика на
 * странице класса: по умолчанию текст и карандаш, по нажатию карандаша поле и
 * кнопка «Сохранить». Открытие поля осталось серверным (?edit=<номер>), отправку
 * забирает скрипт, а без него форма уходит обычным POST.
 *
 * Столбцов «Состояние» и «Выключить» нет: состояние предмета видно по тому,
 * предлагается ли его список выбора, а переключатель отдельной кнопкой в строке
 * занимал место рядом с единственным действием.
 *
 * @var \MyEdu\Core\View $view
 * @var list<array<string,mixed>> $subjects
* @var ?int $editSubjectId
 * @var string $editName название, от которого сервер отказался: поле остаётся открытым
 * @var string $newName название, от которого сервер отказался: окно откроется заново
 */

$view->crumbs([
        ['label' => 'Панель управления', 'url' => 'panel'],
        ['label' => 'Предметы'],
    ]);

/*
 * Обычный вид ячейки собирается один раз на строку: подставляется как есть,
 * когда правка не открыта, и внутрь <template>, когда открыта. Скрипту после
 * сохранения нужно вернуть строку к этому виду.
 */
$valueBlock = static function (array $row) use ($view): string {
    ob_start();
    ?>
    <div class="inline-value">
        <span class="inline-value__text"><?= $view->e($row['name']) ?></span>
        <a class="icon-btn"
           href="<?= $view->e($view->url('panel/subjects?edit=' . (int) $row['id'])) ?>"
           title="Изменить название"
           aria-label="Изменить название предмета: <?= $view->e($row['name']) ?>">
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor"
                 stroke-width="1.6" aria-hidden="true">
                <path d="M13.5 3.5 16.5 6.5 7 16H4v-3z"/>
                <path d="M12 5l3 3"/>
            </svg>
        </a>
    </div>
    <?php

    return (string) ob_get_clean();
};
?>

<?php
/*
 * Сообщения выводит оболочка: она получает те же данные и печатает их сама,
 * над содержимым страницы. Здесь они печатались вторым баннером — учитель
 * видел одну и ту же фразу дважды и на разных высотах, а это читается как
 * два разных сообщения.
 */
?>
<?php
$title = 'Предметы';
$subtitle = 'Список, из которого выбирается предмет в работе и в классе. '
    . 'Название правится прямо в строке.';
require __DIR__ . '/_page_header.php';
?>

<div class="card">
    <div class="card-header">
        <div>
        <button type="button" class="btn btn-primary" id="subject-add-opener"
                data-modal-open="subject-add">
            Добавить предмет
        </button>
    </div>

    <?php if ($subjects === []): ?>
        <div class="card-body">
            <p class="muted">
                Предметов пока нет. Добавьте первый — он сразу появится в выборе
                предмета в работе и в списке предметов класса.
            </p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table" data-datatable data-page-length="25">
                <thead>
                <tr>
                    <th scope="col">Предмет</th>
                    <th scope="col">Классы</th>
                    <th scope="col">Работы</th>
                    <th scope="col" data-orderable="false" class="row-actions"></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($subjects as $row): ?>
                    <?php
                    $subjectId = (int) $row['id'];
                    $isEditing = $editSubjectId === $subjectId;
                    ?>
                    <tr>
                        <td class="inline-cell">
                        <?php if ($isEditing): ?>
                            <form method="post"
                                  action="<?= $view->e($view->url('panel/subjects/' . $subjectId)) ?>"
                                  class="inline-form"
                                  data-inline-form
                                  data-inline-message="Название сохранено">
                                <?= $view->raw($view->csrfField()) ?>
                                <input type="text" name="name" required maxlength="191"
                                       value="<?= $view->e($editName !== '' ? $editName : $row['name']) ?>"
                                       aria-label="Название предмета"
                                       autofocus>
                                <button type="submit" class="icon-btn icon-btn--save"
                                        title="Сохранить название">
                                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor"
                                         stroke-width="1.6" aria-hidden="true">
                                        <path d="M4 4h9l3 3v9H4z"/>
                                        <path d="M7 4v4h6V4"/>
                                        <path d="M7 16v-4h6v4"/>
                                    </svg>
                                    <span class="visually-hidden">Сохранить название</span>
                                </button>
                                <a class="icon-btn" title="Отменить"
                                   href="<?= $view->e($view->url('panel/subjects')) ?>">
                                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor"
                                         stroke-width="1.6" aria-hidden="true">
                                        <path d="M6 6l8 8M14 6l-8 8"/>
                                    </svg>
                                    <span class="visually-hidden">Отменить</span>
                                </a>
                            </form>
                            <template data-inline-template><?= $view->raw($valueBlock($row)) ?></template>
                        <?php else: ?>
                            <?= $view->raw($valueBlock($row)) ?>
                        <?php endif; ?>
                        </td>

                        <td><?= $view->e((int) $row['class_count']) ?></td>
                        <td><?= $view->e((int) $row['work_count']) ?></td>

                        <td class="row-actions">
                            <details class="row-menu">
                                <summary class="card-opt-btn"
                                         aria-label="Действия: <?= $view->e($row['name']) ?>">
                                    <svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                                        <circle cx="8" cy="3" r="1.2"/>
                                        <circle cx="8" cy="8" r="1.2"/>
                                        <circle cx="8" cy="13" r="1.2"/>
                                    </svg>
                                </summary>
                                <div class="menu-popover" role="menu">
                                    <?php /*
                                     * Пункт один, но подтверждение обязательно:
                                     * предмет исчезает из выбора сразу, и человек
                                     * должен знать это, нажимая.
                                     */ ?>
                                    <form method="post"
                                          action="<?= $view->e($view->url('panel/subjects/' . $subjectId . '/delete')) ?>">
                                        <?= $view->raw($view->csrfField()) ?>
                                        <button type="submit" class="menu-item menu-item--danger"
                                                role="menuitem"
                                                data-confirm="Удалить предмет <?= $view->e($row['name']) ?>?"
                                                data-confirm-body="Предмет исчезнет из списков выбора. В старых работах он останется, но без предмета."
                                                data-confirm-yes="Удалить предмет">
                                            Удалить предмет
                                        </button>
                                    </form>
                                </div>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card-body">
            <p class="muted">Всего предметов: <?= $view->e(count($subjects)) ?>.</p>
        </div>
    <?php endif; ?>
</div>

<?php /*
 * Окно добавления — разметка шаблона (.modal-dialog, .modal-header, .modal-body,
 * .modal-footer). Форма настоящая, отправляется обычным POST.
 *
 * Без скриптов окно просто показано: правило в theme.css открывает всё, что
 * помечено data-modal, пока на странице нет отметки data-js. Иначе добавление
 * предмета зависело бы от JavaScript.
 */ ?>
<div class="modal" data-modal id="subject-add" role="dialog" aria-modal="true"
     aria-labelledby="subject-add-title"
     <?= $newName !== '' ? 'data-open-on-load' : '' ?>>
    <div class="modal-dialog">
        <div class="modal-header">
            <h2 class="modal-title" id="subject-add-title">Новый предмет</h2>
            <button type="button" class="modal-close" data-modal-close aria-label="Закрыть">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.5" aria-hidden="true">
                    <path d="M18 6 6 18M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <form method="post" action="<?= $view->e($view->url('panel/subjects')) ?>">
            <div class="modal-body">
                <?= $view->raw($view->csrfField()) ?>

                <div class="form-group">
                    <label class="form-label" for="subject-name">Название</label>
                    <input class="form-control" type="text" id="subject-name" name="name"
                           required maxlength="191" placeholder="Биология"
                           value="<?= $view->e($newName) ?>"
                    <div class="form-help">
                        Кода у предмета нет: он нигде не участвует — работы и
                        выгрузки знают предмет по названию.
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-modal-close>Отмена</button>
                <button type="submit" class="btn btn-primary">Добавить предмет</button>
            </div>
        </form>
    </div>
</div>

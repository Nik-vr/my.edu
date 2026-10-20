<?php

declare(strict_types=1);

namespace MyEdu\Http\Presenter;

use MyEdu\Auth\Auth;
use MyEdu\Auth\Role;
use MyEdu\Core\Database;
use MyEdu\Repository\AssignmentRepository;

/**
 * Меню панели управления: что в нём есть и что подсвечено.
 *
 * Вынесено из шаблона: шаблон не должен ни знать про права, ни считать
 * счётчики. Иначе список пунктов разъезжался бы между страницами, а числа
 * считались бы в разных местах кода.
 *
 * Счётчики рядом с пунктами — только те, с которыми что-то можно сделать:
 * сколько выдач ждут проверки и сколько человек ждут подтверждения адреса.
 * Число без действия — украшение, и через месяц его никто не замечает.
 *
 * Активным считается пункт, чей адрес совпадает с текущим или является его
 * началом: на `/panel/users/12` подсвечен «Пользователи», а не «Консоль».
 */
final class PanelNav
{
    public function __construct(
        private readonly Auth $auth,
        private readonly AssignmentRepository $assignments,
        private readonly Database $db
    ) {
    }

    /**
     * Группы и пункты меню для текущего пользователя.
     *
     * @return list<array{title:string, items:list<array<string,mixed>>}>
     */
    public function items(string $currentPath): array
    {
        $pendingReview = $this->auth->can(Role::TESTS_MANAGE)
            ? $this->assignments->countIssuesPendingReview()
            : 0;

        $pendingUsers = $this->auth->can(Role::USERS_MANAGE)
            ? (int) $this->db->value("SELECT COUNT(*) FROM users WHERE status = 'pending'")
            : 0;

        $groups = [];
        $matches = [];
        $deepest = 0;

        foreach ($this->definition($pendingReview, $pendingUsers) as $title => $items) {
            $visible = [];

            foreach ($items as $item) {
                if (isset($item['capability']) && !$this->auth->can((string) $item['capability'])) {
                    continue;
                }

                $url = (string) $item['url'];

                unset($item['capability']);

                $item['active'] = false;

                $segments = substr_count($url, '/');

                if ($this->matches($url, $currentPath)) {
                    $matches[] = $segments;

                    $deepest = max($deepest, $segments);
                }

                $visible[] = $item;
            }

            // Пустая группа в меню выглядит как ошибка вёрстки, а не как
            // «здесь ничего нет»: пунктов без прав у человека не бывает,
            // и пустое заголовок-группа только пугает.
            if ($visible !== []) {
                $groups[] = ['title' => (string) $title, 'items' => $visible];
            }
        }

        /*
         * Подсвечивается самый глубокий из подходящих пунктов, а не первый.
         *
         * «Консоль» — это /panel, и на /panel/users совпадает с адресом по
         * началу строки, то есть совпадает со всем разделом. Без выбора
         * самого глубокого подсвеченным оставался бы «Консоль» на любой
         * странице панели, а «Пользователи» не подсвечивался бы никогда.
         */
        if ($matches !== []) {
            foreach ($groups as $groupIndex => $group) {
                foreach ($group['items'] as $itemIndex => $item) {
                    $url = (string) $item['url'];

                    $groups[$groupIndex]['items'][$itemIndex]['active'] = $this->matches($url, $currentPath)
                        && substr_count($url, '/') === $deepest;
                }
            }
        }

        return $groups;
    }

/**
 * Описание меню целиком: группы и пункты в них.
     *
     * Порядок групп отвечает на вопрос «куда идти», а не «что есть»:
     * сначала то, чем пользуются каждый день, потом система.
     *
     * @return array<string, list<array<string,mixed>>>
     */
    private function definition(int $pendingReview, int $pendingUsers): array
    {
        return [
            'Главная' => [
                [
                    'label'      => 'Консоль',
                    'url'        => 'panel',
                    'icon'       => 'dashboard',
                    'capability' => Role::PANEL_ACCESS,
                ],
            ],
            'Работы' => [
                [
                    // Список и создание разведены по разным пунктам и разным
                    // адресам: рядом с кнопкой удаления чужой работы стояла
                    // кнопка «Составить», и промах вёк к сносу работы.
                    'label'      => 'Работы',
                    'url'        => 'panel/assignments',
                    'icon'       => 'work',
                    'capability' => Role::TESTS_MANAGE,
                ],
                [
                    /*
                     * Составить работу ведёт в тот же редактор, что и «Редактировать».
                     *
                     * Раньше ссылка вела на отдельный первый шаг мастера, и у
                     * проекта были два редактора работы с разными интерфейсами:
                     * этот — начинал новую, вкладчатый — правил существующую.
                     * Теперь адрес один: работа появляется черновиком, и открывается
                     * вкладчатый редактор.
                     */
                    'label'      => 'Составить работу',
                    'url'        => 'panel/assignments/new',
                    'icon'       => 'add',
                    'capability' => Role::TESTS_MANAGE,
                ],
                [
                    // Перенос — рядом с составлением: это второй путь появления
                    // работы, а не отдельный раздел панели.
                    'label'      => 'Перенести работу',
                    'url'        => 'panel/assignments/import',
                    'icon'       => 'upload',
                    'capability' => Role::TESTS_MANAGE,
                ],
                [
                    // Выдача — отдельным пунктом и не внутри «Работ»: работа
                    // собирается и выдаётся разными людьми в разные моменты, и
                    // «Выдать» под «Работы» читалось бы как ещё один шаг
                    // редактора.
                    'label'      => 'Выдать работу',
                    'url'        => 'panel/issues/new',
                    'icon'       => 'issue',
                    'capability' => Role::TESTS_MANAGE,
                ],
                [
                    // Число стоит здесь, а не только в шапке: человек ищет
                    // пункт, а не число, и без него не поймёт, куда нажать.
                    'label'      => 'Просмотр результатов',
                    'url'        => 'panel/results',
                    'icon'       => 'needs_review',
                    'badge'      => $pendingReview,
                    'capability' => Role::TESTS_MANAGE,
                ],
                [
                    'label'      => 'Журнал работ',
                    'url'        => 'panel/journal',
                    'icon'       => 'journal',
                    'capability' => Role::CLASS_STUDENTS,
                ],
                [
                    // Отдельный пункт, а не вкладка журнала: журнал отвечает
                    // на вопрос «что сдал вот этот ученик», а средний балл — на
                    // «как класс выглядит за период». Смешанные в одном экране
                    // таблицы отвечали бы на оба вопроса сразу и ни на один
                    // толком.
                    //
                    // Право — то же, что у «Просмотра результатов»: учитель видит
                    // средний по своим работам, администратор — по всем. Отдельного
                    // права на отчёты (reports.view) в системе пока нет, и заводить
                    // его ради одного экрана значило бы решить не как считать, а
                    // кто туда вообще попадёт.
                    'label'      => 'Средний балл',
                    'url'        => 'panel/ratings',
                    'icon'       => 'rating',
                    'capability' => Role::TESTS_MANAGE,
                ],
            ],
            'Школа' => [
                [
                    'label'      => 'Классы и ученики',
                    'url'        => 'classes',
                    'icon'       => 'classes',
                    'capability' => Role::CLASSES_MANAGE,
                ],
                [
                    'label'      => 'Предметы',
                    'url'        => 'panel/subjects',
                    'icon'       => 'subjects',
                    'capability' => Role::CLASSES_MANAGE,
                ],
            ],
            'Система' => [
                [
                    // Набор плагинов личный, поэтому пункт у учителя тоже:
                    // отключить лишние типы заданий у себя должен он сам, а не
                    // администратор всего сайта. Право — то же, что на работы.
                    'label'      => 'Плагины',
                    'url'        => 'panel/plugins',
                    'icon'       => 'plugins',
                    'capability' => Role::TESTS_MANAGE,
                ],
                [
                    'label'      => 'Пользователи',
                    'url'        => 'panel/users',
                    'icon'       => 'users',
                    'badge'      => $pendingUsers,
                    'capability' => Role::USERS_MANAGE,
                ],
                [
                    'label'      => 'Настройки',
                    'url'        => 'panel/settings',
                    'icon'       => 'settings',
                    'capability' => Role::USERS_MANAGE,
                ],
            ],
        ];
    }

    /**
     * Подходит ли адрес пункта под текущий адрес.
     *
     * Сравнение по началу строки, а не по точному адресу: у «Составить работу»
     * есть `/panel/assignments/12`, и при точном сравнении пункт не
     * подсвечивался бы почти нигде.
     *
     * Граница целая: наивное сравнение подсветило бы «Классы и ученики»
     * на странице другого раздела, потому что строка начинается с чужого
     * адреса. Поэтому `/classes` подходит `/classes/5`, но не `/classes-old`.
     */
    private function matches(string $url, string $currentPath): bool
    {
        $target = '/' . trim($url, '/');

        if ($currentPath === $target) {
            return true;
        }

        return str_starts_with($currentPath, $target . '/');
    }
}

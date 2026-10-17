<?php

declare(strict_types=1);

namespace MyEdu\Http\Controller\Panel;

use MyEdu\Auth\Auth;
use MyEdu\Auth\ClassRepository;
use MyEdu\Auth\StudentListCsv;
use MyEdu\Auth\StudentRepository;
use MyEdu\Core\App;
use MyEdu\Core\Config;
use MyEdu\Core\Csrf;
use MyEdu\Core\Database;
use MyEdu\Core\HttpException;
use MyEdu\Core\Request;
use MyEdu\Core\Response;
use MyEdu\Core\Session;
use MyEdu\Core\SiteUrl;
use MyEdu\Core\View;
use MyEdu\Http\Controller\ControllerHelpers;

/**
 * Регистрация учеников класса и учётные данные.
 *
 * Пароль выдаётся один раз и на бумаге: хранится только хеш, поэтому
 * показать его повторно нельзя — можно лишь сменить. Отсюда и формат
 * выдачи: сначала «сформировать», потом распечатать.
 */
final class StudentController
{
    use ControllerHelpers;

    public function __construct(
        private readonly App $app,
        private readonly Database $db,
        private readonly View $view,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly Config $config,
        private readonly Auth $auth,
        private readonly StudentRepository $students,
        private readonly ClassRepository $classes
    ) {
    }

    // ---------------------------------------------------------------- список

    /**
 * Список учеников класса.
 *
 * Отдельного списка больше нет: ученики, логины и пароли показываются
 * таблицей в редакторе класса, и второй список рядом с первым был бы одним
 * и тем же человеком, показанным дважды.
 *
 * Адрес остаётся рабочим и уводит в редактор класса — закладки и ссылки из
 * писем не должны превращаться в 404 после переноса.
     */
    public function index(Request $request, array $params): Response
    {
        $class = $this->loadClass($this->intParam($params, 'id'));

        // Через redirect(): в Location нужен абсолютный путь, иначе браузер
        // разрешит его относительно текущего адреса и получит 404.
        return $this->redirect('classes/' . (int) $class['id']);
    }

    // ---------------------------------------------------------------- добавление

    public function store(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        $class = $this->loadClass($this->intParam($params, 'id'));
        $classId = (int) $class['id'];

        $fullName = StudentRepository::normalizeName((string) $request->postParam('full_name', ''));

        if (mb_strlen($fullName, 'UTF-8') < 3) {
            $this->session->set('student_add_old', (string) $request->postParam('full_name', ''));
            $this->session->set('flash_error', 'Укажите фамилию и имя ученика');

            return $this->redirect('classes/' . $classId);
        }

        $existing = $this->students->findByName($fullName);

        // Уже заведённого ученика просто добавляем в класс повторно.
        if ($existing !== null) {
            if ($this->students->isInClass($classId, (int) $existing['id'])) {
                $this->session->set('student_add_old', (string) $request->postParam('full_name', ''));
                $this->session->set('flash_error', $fullName . ' уже состоит в этом классе');

                return $this->redirect('classes/' . $classId);
            }

            $this->students->addToClass($classId, (int) $existing['id']);

            $this->session->set(
                'flash_notice',
                $fullName . ' уже был зарегистрирован — добавлен в класс ' . $class['name']
            );

            return $this->redirect('classes/' . $classId);
        }

        $created = $this->students->create(
            $fullName,
            $this->students->generateLogin($fullName),
            $this->students->generatePassword(),
            $classId
        );

        /*
         * Лист с паролем — сразу в ответе на добавление, и всё.
         *
         * Раньше логин и пароль клались в сессию, а лист открывался позже, из
         * таблицы класса: «нажал „Печать паролей“ — получил бумагу». Пароль в
         * сессии означал, что он лежит открытым текстом в файле на диске всё
         * время, пока открыт сеанс учителя: на хостинге с общей папкой сессий
         * такой файл читается любым, у кого есть доступ к файлам, а пароль
         * ученика после этого меняется — и вместе с ним ломаются все, кто уже
         * записался под этим паролем.
         *
         * Теперь пароль показывается ровно один раз, сразу, и нигде не
         * остаётся. Если человек его не записал — пароль можно выдать заново
         * из меню ученика, и это честнее, чем держать чужие пароли в сессии.
         */
        return $this->credentialsSheet($class, [
            [
                'id'        => (int) $created['id'],
                'full_name' => $fullName,
                'login'     => (string) $created['login'],
                'password'  => (string) $created['password'],
            ],
        ]);
    }

    public function remove(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        $class = $this->loadClass($this->intParam($params, 'id'));
        $classId = (int) $class['id'];

        $studentId = (int) $request->postParam('student_id', '0');

        if (!$this->students->isInClass($classId, $studentId)) {
            $this->session->set('flash_error', 'Ученик не найден в этом классе');

            return $this->redirect('classes/' . $classId);
        }

        $this->students->removeFromClass($classId, $studentId);

        $this->session->set('flash_notice', 'Ученик убран из класса. Учётная запись сохранена.');

        return $this->redirect('classes/' . $classId);
    }

    // ------------------------------------------------------ правка и блокировка

    /**
     * Правка ФИО.
     *
     * Заводить ученика приходится по бумажному списку, и опечатка в фамилии
     * после этого оставалась навсегда: импорт CSV существующих пропускает, а
     * админская страница пользователей ученикам закрыта. Правка живёт
     * здесь же, в списке класса, — там, где завуч видит ученика.
     */
    public function rename(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        /*
         * Один и тот же адрес отвечает и форме, и скрипту.
         *
         * Форма отправляет обычный POST и ждёт перенаправления — так она
         * работает и без JavaScript. Скрипт просит JSON и получает его без
         * перезагрузки страницы: иначе сохранение одного имени уводило человека
         * с таблицы двадцати пяти учеников и возвращало её в начало.
         */
        $asJson = $this->wantsJson($request);

        $class = $this->loadClass($this->intParam($params, 'id'));
        $classId = (int) $class['id'];

        $studentId = (int) $request->postParam('student_id', '0');

        if (!$this->students->isInClass($classId, $studentId)) {
            if ($asJson) {
                return $this->json(['ok' => false, 'error' => 'Ученик не найден в этом классе']);
            }

            $this->session->set('flash_error', 'Ученик не найден в этом классе');

            return $this->redirect('classes/' . $classId);
        }

        $fullName = StudentRepository::normalizeName((string) $request->postParam('full_name', ''));

        if ($fullName === '') {
            if ($asJson) {
                return $this->json(['ok' => false, 'error' => 'Введите фамилию, имя и отчество']);
            }

            $this->rememberRenameInput($studentId, (string) $request->postParam('full_name', ''));
            $this->session->set('flash_error', 'Введите фамилию, имя и отчество');

            return $this->redirect('classes/' . $classId . '?edit=' . $studentId);
        }

        if (!$this->students->rename($studentId, $fullName)) {
            /*
             * Два разных отказа сведены в одно сообщение намеренно: сказав
             * «такое имя уже занято», мы подсказали бы, что ученик с таким
             * именем в системе есть. Для завуча это лишнее, а для постороннего
             * — утечка самой фамилии.
             */
            if ($asJson) {
                return $this->json([
                    'ok' => false,
                    'error' => 'Не удалось сохранить имя: проверьте, не занято ли оно другим учеником',
                ]);
            }

            $this->session->set(
                'flash_error',
                'Не удалось сохранить имя: проверьте, не занято ли оно другим учеником'
            );

            $this->rememberRenameInput($studentId, (string) $request->postParam('full_name', ''));

            return $this->redirect('classes/' . $classId . '?edit=' . $studentId);
        }

        $this->session->remove('student_rename_old');

        if ($asJson) {
            /*
             * Адрес возврата нужен скрипту, чтобы убрать из строки браузера
             * ?edit=. Без этого F5 вернул бы уже закрытое поле правки, и
             * человек решил бы, что правка не закрылась.
             */
            return $this->json([
                'ok'        => true,
                'full_name' => $fullName,
                'url'       => $this->view->url('classes/' . $classId),
            ]);
        }

        $this->session->set('flash_notice', 'Имя сохранено: ' . $fullName);

        return $this->redirect('classes/' . $classId);
    }

    /**
     * Блокировка и разблокировка ученика.
     *
     * Учётная запись не удаляется: у неё остаётся история попыток, а
     * разблокировка возвращает ученику ту же работу с теми же ответами.
     */
    public function setStatus(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        $class = $this->loadClass($this->intParam($params, 'id'));
        $classId = (int) $class['id'];

        $studentId = (int) $request->postParam('student_id', '0');

        if (!$this->students->isInClass($classId, $studentId)) {
            $this->session->set('flash_error', 'Ученик не найден в этом классе');

            return $this->redirect('classes/' . $classId);
        }

        $block = (string) $request->postParam('status', 'blocked') === 'blocked';

        $this->students->setStatus($studentId, $block ? 'blocked' : 'active');

        $this->session->set(
            'flash_notice',
            $block
                ? 'Ученик заблокирован: вход закрыт, история попыток сохранена'
                : 'Ученик разблокирован'
        );

        return $this->redirect('classes/' . $classId);
    }

    // ---------------------------------------------------------------- шаблон CSV

    public function template(Request $request, array $params): Response
    {
        $this->loadClass($this->intParam($params, 'id'));

        $csv = new StudentListCsv();
        $filename = 'spisok-uchenikov-' . date('Ymd') . '.csv';

        return Response::html(
            $csv->template(),
            200
        )->withHeader('Content-Type', 'text/csv; charset=utf-8')
         ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    /**
     * Загрузка списка из таблицы.
     */
    public function import(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        $class = $this->loadClass($this->intParam($params, 'id'));
        $classId = (int) $class['id'];

        $content = $this->readUpload();

        if ($content === null) {
            $this->session->set('flash_error', 'Файл не выбран или слишком большой (максимум 1 МБ)');

            return $this->redirect('classes/' . $classId);
        }

        $csv = new StudentListCsv();
        $parsed = $csv->parse($content);

        if ($parsed['rows'] === []) {
            $this->session->set('flash_error', 'В файле не найдено ни одной строки с учеником');
            $this->session->set('import_errors', $parsed['errors']);

            return $this->redirect('classes/' . $classId);
        }

        $issued = [];
        $skipped = [];
        $errors = $parsed['errors'];

        foreach ($parsed['rows'] as $row) {
            $fullName = StudentRepository::normalizeName(
                trim($row['family'] . ' ' . $row['name'] . ' ' . $row['middle'])
            );

            if ($this->students->findByName($fullName) !== null) {
                /*
                 * Формат замечания — такой же, как у ошибок разбора файла:
                 * массив с номером строки и текстом. Строкой здесь класть нельзя,
                 * шаблон раздела замечаний ждёт именно массив.
                 */
                $skipped[] = [
                    'line'    => $row['line'],
                    'message' => $fullName . ' — уже зарегистрирован',
                ];

                continue;
            }

            try {
                $created = $this->students->create(
                    $fullName,
                    $this->students->generateLogin($fullName),
                    $this->students->generatePassword(),
                    $classId
                );
                $issued[] = [
                    // Номер обязателен: он связывает карточку листа с учеником в
                    // базе, по нему же проверяется, что пароль выдан тому, кому
                    // надо.
                    'id'        => (int) $created['id'],
                'full_name' => $fullName,
                'login'     => (string) $created['login'],
                'password'  => (string) $created['password'],
                ];
            } catch (\Throwable $e) {
                $errors[] = [
                    'line'    => $row['line'],
                    'message' => 'не удалось создать: ' . $e->getMessage(),
                ];
            }
        }

        $this->session->set('import_errors', array_merge($errors, $skipped));

        if ($issued === []) {
            $this->session->set('flash_notice', sprintf(
                'Из файла зарегистрировано учеников: 0. Пропущено: %d.',
                count($skipped) + count($errors)
            ));

            return $this->redirect('classes/' . $classId);
        }

        /*
         * Отчёт и замечания по строкам — на странице класса, а не на листе:
         * лист режут и раздают, а замечания к файлу нужны тому, кто его
         * загрузил, и до следующего открытия класса.
         */
        $this->session->set('flash_notice', sprintf(
            'Из файла зарегистрировано учеников: %d. Пропущено: %d.',
            count($issued),
            count($skipped) + count($errors)
        ));

        /*
         * Лист на печать — сразу ответом на загрузку файла.
         *
         * Раньше выданные пароли клались в сессию и печатались позже, кнопкой
         * «Печать паролей». Теперь пароль нигде не остаётся после закрытия
         * вкладки, и держать его ради второго показа нельзя: файл с двадцатью
         * учениками — это двадцать паролей в файле сессии на диске.
         */
        return $this->credentialsSheet($class, $issued);
    }

    // ---------------------------------------------------------------- учётные данные

    /**
 * Лист печати: логины и пароли.
 *
 * Отдельного листа больше нет — таблица с логинами и паролями стоит в
 * редакторе класса вместе с учениками.
     */
    public function credentials(Request $request, array $params): Response
    {
        $class = $this->loadClass($this->intParam($params, 'id'));

        /*
         * Отдельного листа с паролями больше нет: логины, пароли и отметка для
         * печати стоят в одной таблице с учениками на вкладке редактора
         * класса. Два списка одних и тех же людей рядом сбивали с толку, и
         * отметка для печати была сразу в двух местах.
         *
         * Адрес остаётся рабочим и уводит в редактор: закладки и ссылки из
         * писем не должны превращаться в 404.
         *
         * Через redirect(): в Location нужен абсолютный путь, иначе браузер
         * разрешит его относительно текущего адреса и получит 404.
         */
        return $this->redirect('classes/' . (int) $class['id']);
    }

    /**
     * Лист печати паролей, выданных раньше в этом сеансе.
     *
     * Действия больше нет: пароль нигде не хранится, поэтому и печатать
     * нечего. Лист появляется сам — сразу тем ответом, которым пароль выдан:
     * «Выдать пароль» ученику или «Выдать новые пароли» отмеченным.
     *
     * Старый адрес оставлен и честно объясняет, куда делся функционал: человек
     * мог закладкой заходить сюда годами, и вместо тихого 404 он должен узнать,
     * что нажать.
     */
    public function printCredentials(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        $class = $this->loadClass($this->intParam($params, 'id'));
        $classId = (int) $class['id'];

        $this->session->set(
            'flash_error',
            'Отдельной печати выданных паролей больше нет: пароль показывается один раз, '
            . 'сразу при выдаче. Отметьте учеников и нажмите «Выдать новые пароли» — лист появится сразу.'
        );

        return $this->redirect('classes/' . $classId);
    }

/**
     * Выдача пароля одному ученику с немедленным лист на печать.
     *
     * Отдельное действие, а не «отметить и нажать Печать»: список отметок живёт
     * в форме, а меню вызывается из строки: там отметок нет, и человек,
     * которому нужен пароль одного ученика, иначе должен был бы искать галочку
     * в шапке таблицы, нажимать «Выдать новые пароли» и только потом видеть
     * бумагу. Здесь всё это — одно нажатие, и на экране сразу лист.
     *
     * Прежний пароль при этом теряется: он хранится хешем, и восстановить его
     * нельзя. Это сказано на листе и в подтверждении у пункта меню.
     */
    public function issuePassword(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        $class = $this->loadClass($this->intParam($params, 'id'));
        $classId = (int) $class['id'];
        $studentId = (int) $request->postParam('student_id', '0');

        if ($studentId <= 0) {
            $this->session->set('flash_error', 'Ученик не указан');

            return $this->redirect('classes/' . $classId);
        }

        if (!$this->students->isInClass($classId, $studentId)) {
            $this->session->set('flash_error', 'Этот ученик не входит в класс');

            return $this->redirect('classes/' . $classId);
        }

        $row = $this->students->find($studentId);

        if ($row === null) {
            $this->session->set('flash_error', 'Ученик не найден');

            return $this->redirect('classes/' . $classId);
        }

        try {
            $password = $this->students->generatePassword();
            $this->students->changePassword($studentId, $password);
        } catch (\Throwable $e) {
            $this->session->set('flash_error', 'Не удалось сохранить новый пароль: ' . $e->getMessage());

            return $this->redirect('classes/' . $classId);
        }

        $issued = [
            'id'        => $studentId,
            'full_name' => (string) $row['full_name'],
            'login'     => (string) $row['login'],
            'password'  => $password,
        ];

        /*
         * Лист и таблица показывали один и тот же пароль, поэтому он клался в
         * сессию и держался там до печати. Теперь таблица паролей не существует,
         * а лист — единственное место, где пароль вообще появляется: он уходит
         * вместе с этим ответом и нигде не сохраняется.
         */
        return $this->credentialsSheet($class, [$issued]);
    }

    /**
     * Лист печати для указанных учеников.
     *
     * @param array<string,mixed>            $class
     * @param list<array<string,mixed>>      $rows
     */
    private function credentialsSheet(array $class, array $rows): Response
    {
        return Response::html($this->view->render('panel/credentials_print', [
            'title'     => 'Логины и пароли — ' . $class['name'],
            'class'     => $class,
            'rows'      => $rows,
            // Полный адрес, а не путь: лист уходит в печать и из руки в руку, и
            // ученик идёт по нему, а не переносит его из адресной строки
            // браузера. Отдельная форма входа — с логином и паролем и без
            // e-mail, регистрации и прочего.
            'loginUrl'  => $this->app->get(SiteUrl::class)->to('student/login'),
            'printedAt' => date('d.m.Y'),
        ], ''));
    }

/**
     * Смена паролей отмеченным ученикам и лист на печать.
     *
     * Лист — сразу в этом ответе, а не в таблице класса: пароль нигде не
     * остаётся после закрытия вкладки, и «выдать заново» — единственный
     * способ увидеть его второй раз.
     */
    public function revealCredentials(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        $class = $this->loadClass($this->intParam($params, 'id'));
        $classId = (int) $class['id'];

        $studentIds = $this->idList($request->postList('students'));

        if ($studentIds === []) {
            $this->session->set('flash_error', 'Отметьте хотя бы одного ученика');

            return $this->redirect('classes/' . $classId);
        }

        $issued = [];
        $errors = [];

        foreach ($studentIds as $studentId) {
            if (!$this->students->isInClass($classId, $studentId)) {
                continue;
            }

            $row = $this->students->find($studentId);

            if ($row === null) {
                continue;
            }

            $password = $this->students->generatePassword();

            try {
                $this->students->changePassword($studentId, $password);
            } catch (\Throwable $e) {
                // Один несохранившийся пароль не должен отменять остальные:
                // вернуться к списку с отчётом полезнее, чем с ошибкой.
                $errors[] = (string) $row['full_name'] . ' — ' . $e->getMessage();

                continue;
            }

            $issued[] = [
                'id'        => $studentId,
                'full_name' => (string) $row['full_name'],
                'login'     => (string) $row['login'],
                'password'  => $password,
            ];
        }

        if ($issued === []) {
            $this->session->set('flash_error', 'Ни один из отмеченных учеников не найден в этом классе');

            return $this->redirect('classes/' . $classId);
        }

        if ($errors !== []) {
            $this->session->set(
                'flash_notice',
                'Новые пароли выданы: ' . count($issued)
                . '. Не сохранены: ' . implode('; ', $errors)
            );
        }

        return $this->credentialsSheet($class, $issued);
    }

    // ---------------------------------------------------------------- помощники

    /** @return array<string,mixed> */
    private function loadClass(int $id): array
    {
        if ($id <= 0) {
            throw new HttpException(404, 'Класс не найден');
        }

        $class = $this->classes->find($id);

        if ($class === null) {
            throw new HttpException(404, 'Класс не найден');
        }

        return $class;
    }

    /**
     * Чтение загруженного файла.
     *
     * Приходит лимит на размер запроса, поэтому файл проверяется до чтения,
     * чтобы не пытаться разобрать многомегабайтный файл целиком в памяти.
     */
    private function readUpload(): ?string
    {
        $file = $_FILES['file'] ?? null;

        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }

        $size = (int) ($file['size'] ?? 0);

        if ($size <= 0 || $size > 1024 * 1024) {
            return null;
        }

        $tmp = (string) ($file['tmp_name'] ?? '');

        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return null;
        }

        $content = @file_get_contents($tmp);

        return $content === false ? null : $content;
    }

    private function rememberRenameInput(int $studentId, string $fullName): void
    {
        $this->session->set('student_rename_old', [
            'id' => $studentId,
            'full_name' => $fullName,
        ]);
    }
}

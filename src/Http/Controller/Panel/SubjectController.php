<?php

declare(strict_types=1);

namespace MyEdu\Http\Controller\Panel;

use MyEdu\Auth\SubjectRepository;
use MyEdu\Core\Config;
use MyEdu\Core\Csrf;
use MyEdu\Core\HttpException;
use MyEdu\Core\Request;
use MyEdu\Core\Response;
use MyEdu\Core\Session;
use MyEdu\Core\View;
use MyEdu\Http\Controller\ControllerHelpers;

/**
 * Каталог учебных предметов.
 *
 * Отдельная страница, потому что предмет перестал быть делом класса. Пока он
 * создавался только как побочный эффект правки класса, посмотреть список целиком
 * было негде: предмет появлялся только вместе с классом, а удалить или
 * переименовать его было нечем.
 *
 * Удаления нет намеренно. На предмет ссылаются работы и журнал за прошлые
 * годы, и удаление оборвало бы их. Вместо этого предмет выключается: он
 * пропадает из списков, из которых его выбирают сейчас, и остаётся в старых
 * работах.
 */
final class SubjectController
{
    use ControllerHelpers;

    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly Config $config,
        private readonly SubjectRepository $subjects
    ) {
    }

    public function index(Request $request): Response
    {
        /*
         * Открытое поле правки — это адрес: /panel/subjects?edit=3.
         *
         * Так же, как на странице класса: открытое поле приезжает с сервера,
         * а не рисуется скриптом. Иначе страница зависела бы сразу от двух
         * вещей — от пришедшего стиля и от пришедшего скрипта, — и стоило бы
         * одной не дойти, как у первого предмета появилось бы поле правки,
         * которое никто не открывал.
         */
        $editSubjectId = (int) ($request->queryParam('edit', '0') ?? '0');

        /*
         * Открытая правка после отказа: адрес ?edit= приходит от человека, а
         * номер — из сессии, положенной обработчиком. Значение имени приходит
         * оттуда же, поэтому поле открывается с тем, что человек набрал.
         */
        $renameOld = (array) $this->session->pull('subjects_rename_old', []);
        $renameId = (int) $this->session->pull('subjects_rename_id', 0);

        if ($renameId > 0) {
            $editSubjectId = $renameId;
        }

        return $this->render('panel/subjects', [
            'title'         => 'Предметы',
            'subjects'      => $this->subjects->all(),
            'editSubjectId' => $editSubjectId > 0 ? $editSubjectId : null,
            'editName'      => (string) ($renameOld['name'] ?? ''),
            // Название, от которого сервер отказался: окно откроется заново.
            'newName'       => (string) (((array) $this->session->pull('subjects_new_old', []))['name'] ?? ''),
            'notice'        => $this->session->pull('flash_notice'),
            'error'         => $this->session->pull('flash_error'),
        ]);
    }

    /**
     * Новый предмет.
     *
     * Код не обязателен: он выводится из названия. Вводить его руками —
     * лишняя работа, а названия предметов у учителя всегда на русском.
     */
    public function store(Request $request): Response
    {
        $this->requireToken($request, $this->csrf);

        $name = trim((string) $request->postParam('name', ''));

        if ($name === '') {
            return $this->storeRefused($request, 'Укажите название предмета');
        }

        if (mb_strlen($name, 'UTF-8') > 191) {
            return $this->storeRefused($request, 'Слишком длинное название предмета');
        }

        /*
         * Кода у предмета больше нет — см. миграцию 017.
         *
         * Он был синтетическим: не идентификатором, а наполнителем колонки
         * NOT NULL и обслуживанием уникального индекса. Ни одна ссылка,
         * выгрузка и проверка коду не обращалась, а перенос работ сопоставляет
         * предметы по названию. Поэтому исчезла и проверка «занят ли код»:
         * защищать было нечего, а одинаковые названия предметов и раньше
         * разрешались — двум таким давались разные коды (BIO и BIO2).
         */
        $this->subjects->create(['name' => $name]);

        $this->session->set('flash_notice', 'Предмет «' . $name . '» добавлен');

        return $this->redirect('panel/subjects');
    }

/**
 * Отказ от добавления: список остаётся открытым, окно возвращается с тем,
 * что человек уже набрал.
 *
 * Правило общее для всех форм — отказ не должен стоить напечатанного. Без
 * этого окно закрывалось, поле пустело, и человек начинал бы заново.
 */
private function storeRefused(Request $request, string $message): Response
{
    $this->keepInput('subjects_new_old', $request);
    $this->session->set('flash_error', $message);

    return $this->redirect('panel/subjects');
}

/**
 * Переименование предмета.
     *
     * Один адрес отвечает и форме, и скрипту: форма ждёт перенаправления и
     * работает без JavaScript, скрипт просит JSON и получает его без
     * перезагрузки — иначе правка одного названия уводила бы человека с
     * таблицы к началу страницы.
     */
    public function rename(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        $asJson = $this->wantsJson($request);

        $id = $this->intParam($params, 'id');
        $subject = $this->subjects->find($id);

        if ($subject === null) {
            throw new HttpException(404, 'Предмет не найден');
        }

        $name = trim((string) $request->postParam('name', ''));

        if ($name === '') {
            return $this->renameRefused($request, $asJson, 'Укажите название предмета', $id);
        }

        if (mb_strlen($name, 'UTF-8') > 191) {
            return $this->renameRefused($request, $asJson, 'Слишком длинное название предмета', $id);
        }

        $this->subjects->rename($id, $name);

        if ($asJson) {
            return $this->json([
                'ok'   => true,
                'name' => $name,
                // Адрес возврата убирает ?edit= из строки браузера: иначе F5
                // вернул бы уже закрытое поле правки.
                'url'  => $this->view->url('panel/subjects'),
            ]);
        }

        $this->session->set('flash_notice', 'Название предмета сохранено');

        return $this->redirect('panel/subjects');
    }

    /**
     * Отказ от переименования двумя способами: страницей с сообщением и
     * ответом скрипту.
     */
    /**
 * Отказ от переименования.
 *
 * Правка возвращается открытой и с тем, что человек набрал: закрытое поле
 * выглядит так, будто правки и не было, а это заставляет искать предмет заново
 * и набирать название второй раз.
 */
private function renameRefused(Request $request, bool $asJson, string $message, int $subjectId): Response
    {
        if ($asJson) {
            return $this->json(['ok' => false, 'error' => $message]);
        }

        $this->session->set('flash_error', $message);
        $this->session->set('subjects_rename_id', $subjectId);
        $this->keepInput('subjects_rename_old', $request);

        return $this->redirect('panel/subjects');
    }

    /**
     * Удаление предмета.
     *
     * Удалить можно не всякий: пока на предмет ссылаются классы или работы,
     * удаление тихо уносило бы его из классов и оставляло бы работы без
     * предмета. Поэтому сначала считаются ссылки, и предмет с ними удалить
     * нельзя — сначала его надо выключить.
     */
    public function destroy(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        $id = $this->intParam($params, 'id');
        $subject = $this->subjects->find($id);

        if ($subject === null) {
            throw new HttpException(404, 'Предмет не найден');
        }

        $name = (string) $subject['name'];
        $usage = $this->subjects->usage($id);

        if ($usage['classes'] > 0 || $usage['works'] > 0) {
            $this->session->set('flash_error', 'Предмет «' . $name . '» удалить нельзя: он назначен '
                . $usage['classes'] . ' кл. и ' . $usage['works'] . ' раб. Сначала уберите его оттуда.');

            return $this->redirect('panel/subjects');
        }

        $this->subjects->delete($id);

        $this->session->set('flash_notice', 'Предмет «' . $name . '» удалён');

        return $this->redirect('panel/subjects');
    }

    /**
     * Включение и выключение.
     *
     * Выключение безопасно и потому, что на выключенный предмет всё равно
     * нельзя назначить работу: список для выбора отдаёт только активные.
     */
    public function toggle(Request $request, array $params): Response
    {
        $this->requireToken($request, $this->csrf);

        $id = $this->intParam($params, 'id');
        $subject = $this->subjects->find($id);

        if ($subject === null) {
            throw new HttpException(404, 'Предмет не найден');
        }

        $active = (int) $subject['is_active'] !== 1;

        $this->subjects->setActive($id, $active);

        $this->session->set(
            'flash_notice',
            $active
                ? 'Предмет «' . $subject['name'] . '» снова доступен'
                : 'Предмет «' . $subject['name'] . '» выключен. В прошлых работах он остаётся.'
        );

        return $this->redirect('panel/subjects');
    }
}
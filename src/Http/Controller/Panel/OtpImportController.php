<?php

declare(strict_types=1);

namespace MyEdu\Http\Controller\Panel;

use MyEdu\Auth\Auth;
use MyEdu\Auth\SubjectRepository;
use MyEdu\Core\App;
use MyEdu\Core\Csrf;
use MyEdu\Core\Database;
use MyEdu\Core\ImageUpload;
use MyEdu\Core\Request;
use MyEdu\Core\Response;
use MyEdu\Core\Session;
use MyEdu\Core\View;
use MyEdu\Http\Controller\ControllerHelpers;
use MyEdu\Import\ImportException;
use MyEdu\Import\OtpArchive;
use MyEdu\Import\OtpPackage;
use MyEdu\Import\OtpParser;
use MyEdu\Plugin\PluginManager;
use MyEdu\Transfer\TransferException;
use MyEdu\Transfer\WorkImporter;

/**
 * Мастер переноса теста из OnlineTestPad.
 *
 * Одна страница: сначала человек показывает файл и смотрит, что из него
 * получится, и только потом называет параллель и создаёт работу. Разбор файла
 * ничего не записывает — ровно как при переносе работ между инсталляциями:
 * узнать, что тест разобрался не так, можно только посмотрев на разбор, а не
 * post factum.
 *
 * Разбор и создание работы живут на одной странице, а не на двух. Решение
 * владельца: финальный шаг должен быть на той же самой странице, что и
 * разбор. Отдельный адрес уводил человека с разбора на другую страницу, где
 * он и так не знал, что введённое до этого сохранилось, — и возвращаться
 * пришлось бы адресом, который помнить трудно.
 *
 * Внос идёт через общий код переноса: разобранный тест собирается в тот же
 * пакет, который читает свой внос, и создаётся тем же кодом.
 */
final class OtpImportController
{
    use ControllerHelpers;

    /**
     * Больше теста переносить не о чем.
     *
     * Офлайн-версия весит больше самого теста — в ней лежат библиотеки
     * интерфейса, — но даже она меньше мегабайт с небольшим запасом.
     */
    private const MAX_UPLOAD_BYTES = 32 * 1024 * 1024;

    private const PARSED_KEY = 'otp_parsed';

    public function __construct(
        private readonly Auth $auth,
        private readonly Csrf $csrf,
        private readonly Session $session,
        private readonly View $view,
        private readonly Database $db,
        private readonly PluginManager $plugins,
        private readonly SubjectRepository $subjects,
        private readonly App $app
    ) {
    }

    /**
     * Страница переноса: файл, разбор и создание работы.
     *
     * Пока файла нет, страница спрашивает файл. После разбора она показывает
     * разбор и форму создания работы — на той же странице, а не на другой.
     *
     * @param array<string,mixed> $params
     */
    public function start(Request $request, array $params): Response
    {
        $parsed = $this->session->get(self::PARSED_KEY);

        if (!is_array($parsed)) {
            return $this->render('panel/import_otp_start', $this->page([
                'maxUpload' => self::MAX_UPLOAD_BYTES,
            ]));
        }

        return $this->render('panel/import_otp_start', $this->page([
            'maxUpload'  => self::MAX_UPLOAD_BYTES,
            'parsed'     => $parsed,
            'subjects'   => $this->subjects->all(),
            'kindTitles' => $this->kindTitles(),
            'old'        => $this->session->pull('otp_setup_old', []),
        ]));
    }

    /**
     * Начать заново: разобранный тест забывается, страница снова просит файл.
     *
     * @param array<string,mixed> $params
     */
    public function reset(Request $request, array $params): Response
    {
        $this->csrf->validate($request->postParam('_token'));

        $this->session->remove(self::PARSED_KEY);
        $this->session->remove('otp_setup_old');

        return $this->redirect('panel/assignments/import/otp');
    }

    /**
     * Разобрать приложенный файл и показать, что из него получится.
     *
     * @param array<string,mixed> $params
     */
    public function upload(Request $request, array $params): Response
    {
        $this->csrf->validate($request->postParam('_token'));

        $file = $request->file('package');
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error !== UPLOAD_ERR_OK) {
            $this->session->set('flash_error', $this->uploadError($error));

            return $this->redirect('panel/assignments/import/otp');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        $real = $tmp === '' ? '' : (string) @realpath($tmp);

        if ($real === '' || !is_file($real)) {
            $this->session->set('flash_error', 'Файл не получен.');

            return $this->redirect('panel/assignments/import/otp');
        }

        $size = (int) filesize($real);

        if ($size <= 0 || $size > self::MAX_UPLOAD_BYTES) {
            $this->session->set(
                'flash_error',
                $size <= 0
                    ? 'Файл пустой.'
                    : 'Файл больше ' . (int) (self::MAX_UPLOAD_BYTES / 1024 / 1024) . ' МБ.'
            );

            return $this->redirect('panel/assignments/import/otp');
        }

        /*
         * Расширение смотрим у ПРИСЛАННОГО имени, а не у временного файла:
         * последний называется PHP и расширения не имеет, и проверка по нему
         * отвергала бы всякий файл, включая правильный zip.
         *
         * PDF отличается от zip именно расширением, и сказать об этом сразу
         * полезнее, чем после сообщения «архив не распаковывается».
         */
        $refusal = OtpArchive::refuseIfForeign((string) ($file['name'] ?? ''));

        if ($refusal !== null) {
            $this->session->set('flash_error', $refusal);

            return $this->redirect('panel/assignments/import/otp');
        }

        try {
            $parsed = (new OtpParser())->parse(OtpArchive::read($real));
        } catch (ImportException $e) {
            $this->session->set('flash_error', $e->getMessage());

            return $this->redirect('panel/assignments/import/otp');
        }

        if ($parsed['questions'] === []) {
            $this->session->set(
                'flash_error',
                'В тесте не осталось ни одного задания, которое эта система умеет переносить: '
                . $this->firstReason($parsed)
            );

            return $this->redirect('panel/assignments/import/otp');
        }

        // Разобранный тест живёт в сессии между шагами: файл второй раз не
        // прикладывается и на диске не валяется без нужды.
        $this->session->set(self::PARSED_KEY, $parsed);

        return $this->redirect('panel/assignments/import/otp');
    }

    /**
     * Создание работы из разобранного теста.
     *
     * Страница прежняя — та же, что и разбор, — но адрес у формы отдельный:
     * отправка должна попадать сюда, а не на адрес, который открывает страницу.
     *
     * @param array<string,mixed> $params
     */
    public function setup(Request $request, array $params): Response
    {
        $parsed = $this->session->get(self::PARSED_KEY);

        if (!is_array($parsed)) {
            $this->session->set('flash_error', 'Разобранный тест потерялся. Приложите файл заново.');

            return $this->redirect('panel/assignments/import/otp');
        }

        if ($request->method() === 'POST') {
            return $this->create($request, $parsed);
        }

        /*
         * Старый адрес второго шага остаётся рабочим: на него могли остаться
         * ссылки из закладок и из писем. Открывать на нём отдельную страницу
         * больше нельзя — финальный шаг живёт на странице разбора, — поэтому
         * адрес просто отправляет туда.
         */
        return $this->redirect('panel/assignments/import/otp');
    }

    /**
     * Создать работу из разобранного теста.
     *
     * @param array<string,mixed> $parsed
     */
    private function create(Request $request, array $parsed): Response
    {
        $this->csrf->validate($request->postParam('_token'));

        $subject = trim((string) $request->postParam('subject', ''));
        $parallel = $this->parallel($request->postParam('parallel'));

        if ($subject === '') {
            // Отказ не должен стоить разбора: тест уже в сессии, повторно
            // прикладывать файл не приходится.
            $this->keepInput('otp_setup_old', $request);
            $this->session->set('flash_error', 'Укажите предмет — без него работа не попадёт в список.');

            return $this->redirect('panel/assignments/import/otp');
        }

        try {
            $report = (new WorkImporter($this->db, $this->plugins, $this->subjects))
                ->import(
                    (new OtpPackage())->build($parsed, $subject, $parallel),
                    $this->auth->id() ?? 0,
                    $this->uploadsDir()
                );
        } catch (TransferException $e) {
            $this->session->set('flash_error', $e->getMessage());

            return $this->redirect('panel/assignments/import/otp');
        }

        $this->session->remove(self::PARSED_KEY);

        /*
         * Перечисляются ОБЕ причины пропуска: и разбор (нечем переносить), и
         * внос (плагин не смог). Молча пропущенная позиция хуже отсутствующей:
         * работа выглядит целой, а ученик упрётся в задание, которого нет.
         */
        $missed = array_merge(
            array_map(
                static fn (array $item): string => '№' . $item['number'] . ' — ' . $item['reason'],
                $parsed['skipped']
            ),
            $report['skipped']
        );

        $this->session->set(
            'flash_notice',
            'Тест перенесён: заданий — ' . count($report['created']) . '.'
            . ($missed === [] ? '' : ' Не перенесено: ' . implode('; ', $missed))
        );

        return $this->redirect('panel/assignments/' . $report['assignmentId']);
    }

    /**
     * Общее для страниц переноса.
     *
     * Сообщения из сессии достаются здесь же: каждый адрес — отдельная
     * страница, и без них отказ о неверном файле просто исчезал бы вместе с
     * переходом — человек видел бы пустую страницу и не понимал, что его
     * отвергли.
     *
     * Полосы шагов здесь нет намеренно. Перенос из OnlineTestPad — одна
     * страница: приложить файл и, увидев разбор, создать работу. Два кружка
     * с пройденным этагом рядом с двумя вкладками «Откуда переносим» читались
     * как ещё один способ выбрать, куда идти, — а здесь выбора нет: файл
     * приложить можно только сюда, и разбор файла тоже появляется только здесь.
     *
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    private function page(array $data): array
    {
        return $data + [
            'title'  => 'Импорт из OnlineTestPad',
            'notice' => $this->session->pull('flash_notice'),
            'error'  => $this->session->pull('flash_error'),
        ];
    }

    /**
     * Названия наших типов заданий для списка разбора.
     *
     * Берутся у плагина, а не пишутся здесь строкой: иначе переименование
     * вида задания в плагине оставило бы в отчёте старое название и человек
     * искал бы несуществующий тип.
     *
     * @return array<string,string>
     */
    private function kindTitles(): array
    {
        $titles = [];

        foreach ($this->plugins->taskKinds('test') as $kind) {
            $titles[$kind->slug] = $kind->title;
        }

        return $titles;
    }

    /**
     * Параллель из поля формы.
     *
     * Принимается та же половка 1–11, что и в редакторе работы: параллель у
     * работы одна и та же, иначе работа оказалась бы сразу в двух папках.
     */
    private function parallel(?string $value): ?int
    {
        $parallel = (int) $value;

        return ($parallel < 1 || $parallel > 11) ? null : $parallel;
    }

    /** @param array<string,mixed> $parsed */
    private function firstReason(array $parsed): string
    {
        foreach ($parsed['skipped'] as $item) {
            return $item['reason'];
        }

        return 'причина неизвестна';
    }

    private function uploadsDir(): string
    {
        return $this->app->rootDir() . '/public' . ImageUpload::DIRECTORY;
    }

    private function uploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Файл слишком большой для сервера.',
            UPLOAD_ERR_PARTIAL => 'Файл загрузился не полностью. Попробуйте ещё раз.',
            UPLOAD_ERR_NO_FILE => 'Файл не выбран.',
            default => 'Файл не получен.',
        };
    }
}

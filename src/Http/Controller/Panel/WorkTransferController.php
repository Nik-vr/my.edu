<?php

declare(strict_types=1);

namespace MyEdu\Http\Controller\Panel;

use MyEdu\Auth\Auth;
use MyEdu\Auth\SubjectRepository;
use MyEdu\Core\App;
use MyEdu\Core\Csrf;
use MyEdu\Core\Database;
use MyEdu\Core\HttpException;
use MyEdu\Core\ImageUpload;
use MyEdu\Core\Request;
use MyEdu\Core\Response;
use MyEdu\Core\Session;
use MyEdu\Core\View;
use MyEdu\Http\Controller\ControllerHelpers;
use MyEdu\Plugin\PluginManager;
use MyEdu\Repository\AssignmentRepository;
use MyEdu\Transfer\TransferException;
use MyEdu\Transfer\WorkExporter;
use MyEdu\Transfer\WorkImporter;
use MyEdu\Transfer\WorkPackageReader;
use MyEdu\Transfer\Zip;

/**
 * Перенос работ между инсталляциями.
 *
 * Выгрузка отдаёт файл, внос принимает такой же файл. Переносится то, что
 * собрал мастер создания работы: настройки, шкала, задания и написанное в них —
 * вместе с картинками. Данные о выдаче не переносятся: на новом месте учеников
 * может не быть, и перенос начал бы заводить людей, которых никто не приглашал.
 *
 * Внос всегда начинается черновиком: перенесённую работу никто не видел, и
 * публиковать её должен человек.
 */
final class WorkTransferController
{
    use ControllerHelpers;

    public function __construct(
        private readonly Auth $auth,
        private readonly Csrf $csrf,
        private readonly Session $session,
        private readonly View $view,
        private readonly Database $db,
        private readonly AssignmentRepository $assignments,
        private readonly PluginManager $plugins,
        private readonly SubjectRepository $subjects,
        private readonly App $app
    ) {
    }

    /**
     * Файл переноса работы.
     *
     * Отдаётся файлом, а не страницей: учитель уносит его на другое место сам.
     */
    public function export(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $assignment = $this->assignments->find($id);

        if ($assignment === null) {
            throw new HttpException(404, 'Работа не найдена');
        }

        // Выгружает автор и только он: в файле лежат условия заданий и ответы
        // на них, и отдавать их постороннему по ссылке нельзя.
        if ((int) $assignment['author_id'] !== $this->authorId()) {
            throw new HttpException(403, 'Это работа другого автора');
        }

        $archive = null;

        try {
            $package = (new WorkExporter($this->db, $this->plugins))->export($id, $this->uploadsDir());
            $archive = Zip::write($package['files']);
            $content = (string) @file_get_contents($archive);
        } catch (TransferException $e) {
            $this->session->set('flash_error', 'Не удалось собрать пакет переноса: ' . $e->getMessage());

            return Response::redirect('panel/assignments/' . $id);
        } finally {
            if (is_string($archive)) {
                @unlink($archive);
            }
        }

        if ($content === '') {
            $this->session->set('flash_error', 'Не удалось собрать файл переноса.');

            return Response::redirect('panel/assignments/' . $id);
        }

        if ($package['skipped'] !== []) {
            $this->session->set(
                'flash_notice',
                'Выгружено без заданий, которые перенести нельзя: ' . implode('; ', $package['skipped'])
            );
        }

return Response::html($content)
            ->withHeader('Content-Type', Zip::contentType())
            ->withHeader('Content-Disposition', 'attachment; filename="'
                . $this->fileName((string) $assignment['title']) . '"')
            ->withHeader('Content-Length', (string) strlen($content));
    }

    /**
     * Страница вноса: форма и разбор уже приложенного пакета.
     *
     * @param array<string,mixed> $params
     */
    public function form(Request $request, array $params): Response
    {
        $package = $this->session->get('transfer_package');

        /*
         * Сообщения из сессии достаются здесь: без них отказ о негодном файле
         * исчезал бы вместе с переходом, и человек видел бы ту же форму,
         * что и до попытки, — без единого слова о том, что его отвергли.
         */
        return $this->render('panel/assignment_transfer', [
            'title'     => 'Перенос работ',
            'notice'    => $this->session->pull('flash_notice'),
            'error'     => $this->session->pull('flash_error'),
            'package'   => is_array($package) ? $package : null,
            'maxUpload' => Zip::maxUploadBytes(),
        ]);
    }

    /**
     * Приложить файл и разобрать его.
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

            return Response::redirect('panel/assignments/import');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        $real = $tmp === '' ? '' : (string) @realpath($tmp);

        if ($real === '' || !is_file($real)) {
            $this->session->set('flash_error', 'Файл не получен.');

            return Response::redirect('panel/assignments/import');
        }

        $size = (int) filesize($real);

        $maxUploadBytes = Zip::maxUploadBytes();

        if ($size <= 0 || $size > $maxUploadBytes) {
            $this->session->set(
                'flash_error',
                $size <= 0
                    ? 'Файл пустой.'
                    : 'Файл больше ' . (int) ($maxUploadBytes / 1024 / 1024) . ' МБ.'
            );

            return Response::redirect('panel/assignments/import');
        }

        try {
            $package = WorkPackageReader::read($real);
        } catch (TransferException $e) {
            $this->session->set('flash_error', $e->getMessage());

            return Response::redirect('panel/assignments/import');
        }

        /*
         * Пакет живёт в сессии между двумя нажатиями: человек сначала смотрит,
         * что внутри, и только потом решает. Повторно прикладывать один и тот же
         * файл не приходится, и на диске он не валяется без нужды.
         */
        $this->session->set('transfer_package', $package);

        return Response::redirect('panel/assignments/import');
    }

    /**
     * Внести пакет.
     *
     * @param array<string,mixed> $params
     */
    public function import(Request $request, array $params): Response
    {
        $this->csrf->validate($request->postParam('_token'));

        $package = $this->session->get('transfer_package');

        if (!is_array($package)) {
            $this->session->set('flash_error', 'Файл переноса потерялся. Приложите его заново.');

            return Response::redirect('panel/assignments/import');
        }

        try {
            $report = (new WorkImporter($this->db, $this->plugins, $this->subjects))
                ->import($package, $this->authorId(), $this->uploadsDir());
        } catch (TransferException $e) {
            $this->session->set('flash_error', $e->getMessage());

            return Response::redirect('panel/assignments/import');
        }

        $this->session->remove('transfer_package');

        $this->session->set(
            'flash_notice',
            'Работа перенесена: заданий — ' . count($report['created'])
            . ($report['media'] > 0 ? ', картинок — ' . $report['media'] : '')
            . '.'
            . ($report['skipped'] === [] ? '' : ' Не перенесено: ' . implode('; ', $report['skipped']))
        );

        return Response::redirect('panel/assignments/' . $report['assignmentId']);
    }

    /** Каталог загрузок: там лежат картинки из условий и вопросов. */
    private function uploadsDir(): string
    {
        return $this->app->rootDir() . '/public' . ImageUpload::DIRECTORY;
    }

    private function authorId(): int
    {
        return $this->auth->id() ?? 0;
    }

    /**
     * Имя файла для браузера.
     *
     * Латиницей: кириллица в заголовке Content-Disposition доезжает до
     * разных браузеров по-разному, а учителю нужно имя, по которому файл
     * узнаётся в списке загрузок.
     */
    private function fileName(string $title): string
    {
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
        $clean = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', (string) $transliterated), '-'));

        if ($clean === '') {
            $clean = 'rabota-' . substr(md5($title), 0, 8);
        }

        return 'rabota-' . substr($clean, 0, 40) . '.myedu.' . Zip::extension();
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

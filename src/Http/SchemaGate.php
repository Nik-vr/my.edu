<?php

declare(strict_types=1);

namespace MyEdu\Http;

use MyEdu\Core\Request;
use MyEdu\Core\Response;
use MyEdu\Core\SchemaMigrator;

/**
 * Проверка схемы базы перед тем, как отдать запрос.
 *
 * Нужна из-за выкатки на шаред-хостинг: владелец заливает новую версию файлами
 * по FTP и не может выполнить `php database/migrate.php` — консоли у него нет.
 * Без этой проверки первая же страница после выкатки упала бы с «Table ... doesn't
 * exist», и владелец не понял бы, что дело не в файлах, а в том, что на сервере
 * они изменились.
 *
 * Здесь две вещи, которых нет в самом миграторе: решение «показывать или нет» и
 * страница для показа. Сам мигратор ничего не знает про HTTP.
 *
 * Подробности ошибки — только с машины хостинга, как в `/health`: страницу об
 * ошибке видно и снаружи, но текст оператора, имя файла и текст исключения
 * MySQL наружу не уходят. Их владелец читает в `var/log/schema.log`.
 */
final class SchemaGate
{
    public function __construct(
        private readonly SchemaMigrator $migrator,
        private readonly Request $request,
        private readonly string $logFile
    ) {
    }

    /**
     * null — запрос можно обрабатывать обычным образом.
     *
     * @return Response|null
     */
    public function check(): ?Response
    {
        try {
            $result = $this->migrator->apply();
        } catch (\Throwable $e) {
            return $this->failure(
                'Не удалось проверить состояние базы',
                $e->getMessage(),
                500
            );
        }

        if ($result['status'] === 'applied') {
            $this->log('Применены миграции: ' . implode(', ', $result['applied']));

            /*
             * Пропущенные операторы — не сбой, но и не «ничего не делали»:
             * миграция нашла базу не в том состоянии, на которое рассчитана, и
             * часть работы уже была сделана раньше. Молчание об этом выглядело
             * бы как обычная выкатка.
             */
            foreach ($result['notes'] as $note) {
                $this->log($note);
            }

            return null;
        }

        /*
         * Журнал приведён в соответствие с базой. Сайт работает, и показывать
         * владельцу ничего не нужно: он ничего не делал и ничего не должен был
         * делать. В журнал пишется обязательно — потеря журнала на живой базе
         * событие не тихое, и по следующей выкатке должно быть видно, что это
         * случилось и чем закончилось.
         */
        if ($result['status'] === 'repaired') {
            $this->log(
                'Журнал миграций не совпадал с базой: база уже была новее журнала. '
                . 'Отмечено как применённые: ' . implode(', ', $result['repaired'])
            );

            /*
             * Предмет удаления мог остаться: миграция, которая что-то убирает,
             * отмечается применённой по совпадению с целевой схемой, а лишние
             * объекты сверка не трогает. На работу это не влияет, но оставить
             * это без записи значило бы через год гадать, откуда взялся лишний
             * столбец.
             */
            foreach ($result['leftOver'] ?? [] as $leftover) {
                $this->log(
                    'Внимание: ' . $leftover . ' осталась в базе, хотя миграция '
                    . 'её убирала. На работу не влияет, но расхождение со схемой есть.'
                );
            }

            return null;
        }

        if ($result['status'] === 'locked') {
            /*
             * Другой запрос уже применяет. Отдавать «подождите» — единственное
             * честное поведение: попытка обслужить сайт на старой схеме выглядела
             * бы как обычная страница с частично применённой миграцией.
             */
            return $this->page(
                'Обновляется структура базы',
                'Сейчас применяются миграции новой версии. Обновите страницу через минуту.',
                503
            );
        }

        if ($result['status'] === 'install') {
            return $this->page(
                'База ещё не установлена',
                'Откройте <code>install.php</code> — он создаст структуру с нуля. '
                . 'Свою версию на сервере вы уже залили.',
                503
            );
        }

        if ($result['status'] === 'failed') {
            return $this->failure(
                'Миграция не применилась: ' . $result['file'],
                $result['message'],
                500
            );
        }

        return null;
    }

    private function failure(string $title, string $detail, int $status): Response
    {
        $this->log($title . "\n" . $detail);

        /*
         * Снаружи — короткая причина и место, где искать подробности. Текст
         * исключения MySQL в ошибке ALTER может содержать названия таблиц и
         * колонок, а иногда и само имя базы.
         */
        return $this->page(
            $title,
            'Подробности записаны в <code>var/log/schema.log</code>. '
            . 'Правьте вручную через панель хостинга и заливайте исправленную миграцию.',
            $status,
            $this->request->isLoopback() ? $detail : null
        );
    }

    /**
     * @param string|null $detail подробности; показываются только как есть,
     *                            если вызывающий разрешил, иначе игнорируются
     */
    private function page(string $title, string $text, int $status, ?string $detail = null): Response
    {
        $extra = '';

        if ($detail !== null && $detail !== '') {
            $extra = '<pre>' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . '</pre>';
        }

        return Response::html(
            '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>'
            . '<style>body{font:16px/1.5 system-ui,sans-serif;margin:3rem auto;max-width:44rem;padding:0 1rem;'
            . 'color:#1b1b1b}h1{font-size:1.4rem}pre{background:#f5f5f5;padding:1rem;overflow:auto;'
            . 'white-space:pre-wrap;word-break:break-word}code{background:#f5f5f5;padding:.1em .35em}</style>'
            . '</head><body><h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
            . '<p>' . $text . '</p>' . $extra . '</body></html>',
            $status
        );
    }

    private function log(string $message): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
        $dir = dirname($this->logFile);

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        @file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
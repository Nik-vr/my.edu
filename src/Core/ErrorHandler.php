<?php

declare(strict_types=1);

namespace MyEdu\Core;

/**
 * Последняя страховка: ни одна ошибка не должна доходить до белого экрана.
 *
 * Ядро и так перехватывает исключения при разборе запроса, но перехват стоит
 * глубоко в стеке вызовов. Всё, что случилось до него или вне его — ошибка в
 * `config.local.php`, нехватка памяти, превышение времени, ошибка включённого
 * файла, — доходило до PHP как фатальная ошибка, а тот показывал пустую страницу.
 * Человек видел белое поле и не знал, куда смотреть; владелец на сервере без
 * доступа к журналу PHP не видел ничего вовсе.
 *
 * Обработчик ставится ДО загрузки конфигурации. Это главное: тот самый
 * `config.local.php`, из-за которого всё и затевалось, пришлось бы читать уже
 * внутри `App::boot()`, то есть после того, как поставить обработчик.
 *
 * Чего обработчик не делает намеренно:
 *
 * - Не показывает наружу текст ошибки, файл и строку. Путь к файлу на сервере и
 *   текст исключения — сведения о машине, а не о работе приложения. Подробности
 *   идут в журнал, а при `app.debug` показываются вместе с ними.
 * - Не рисует макет сайта. К моменту срабатывания нет ни контейнера, ни вида, ни
 *   сессии, а собирать их ради страницы ошибки значит упасть на том же месте,
 *   где упало в первый раз. Страница намеренно самодостаточна и без интерфейса.
 * - Не отправляет заголовки, если они уже ушли. Ответ, начатый раньше, добрать
 *   нельзя, и попытка вызвать `header()` после вывода бросила бы вторую ошибку
 *   поверх первой и убрала бы из журнала всё самое интересное.
 *
 * Токены в журнале укорачиваются: текст исключения нередко содержит адрес
 * целиком, а адрес вида `/auth/reset?token=…` — это готовый ключ сброса пароля.
 * Правило то же, что у журнала писем.
 */
final class ErrorHandler
{
    /**
     * Ошибки, на которых PHP прекращает работу и которые нельзя перехватить
     * обычным `try`. Остальные — предупреждения и заметки — перехватывать нельзя:
     * они не мешают выполнению, и превращение любого предупреждения в 500
     * сделало бы страницу нерабочей из-за безобидной опечатки.
     */
    private const FATAL_TYPES = [
        E_ERROR,
        E_PARSE,
        E_CORE_ERROR,
        E_CORE_WARNING,
        E_COMPILE_ERROR,
        E_COMPILE_WARNING,
    ];

    private static bool $reported = false;

    /** Поставить обработчик. Вызывается до загрузки конфигурации. */
    public static function register(string $logFile): void
    {
        set_exception_handler(static function (\Throwable $e) use ($logFile): void {
            self::report($e, $logFile);
        });

        /*
         * Предупреждения, заметки и сообщения об устаревании — в журнал, а не
         * в ответ.
         *
         * На боевом сервере `display_errors` бывает включён, и тогда PHP печатает
         * такое прямо в страницу: человек видел «Deprecated: curl_close() ...»
         * вместо результата обновления. Само по себе сообщение ничего не ломает,
         * но оно попадает туда, где человек ждёт ответа, и выглядит как поломка.
         *
         * Обработчик не превращает их в исключения: страница остаётся рабочей,
         * а сообщение уходит в `var/log/error.log` — там оно и место.
         */
        set_error_handler(static function (int $level, string $message, string $file = '', int $line = 0) use ($logFile): bool {
            if (in_array($level, self::FATAL_TYPES, true)) {
                return false;
            }

            self::write(
                $logFile,
                'Предупреждение: ' . $message . ' в ' . $file . ':' . $line
            );

            return true;
        });

        register_shutdown_function(static function () use ($logFile): void {
            self::reportFatal($logFile);
        });
    }

    /**
     * Фатальная ошибка, на которой PHP остановился.
     *
     * Проверка на «уже сообщённое» обязательна: после обычного обработчика
     * исключений PHP всё равно зовёт функцию завершения, и без этой проверки одно
     * исключение попало бы в журнал дважды — второй раз уже как фатальная ошибка.
     */
    private static function reportFatal(string $logFile): void
    {
        if (self::$reported) {
            return;
        }

        $last = error_get_last();

        if ($last === null || !in_array($last['type'], self::FATAL_TYPES, true)) {
            return;
        }

        self::$reported = true;

        $types = [
            E_ERROR => 'ошибка выполнения',
            E_PARSE => 'ошибка разбора',
            E_CORE_ERROR => 'ошибка ядра',
            E_CORE_WARNING => 'предупреждение ядра',
            E_COMPILE_ERROR => 'ошибка компиляции',
            E_COMPILE_WARNING => 'предупреждение компиляции',
        ];

        self::write(
            $logFile,
            sprintf(
                "Фатальная ошибка (%s): %s\nФайл: %s:%d\n",
                $types[$last['type']] ?? 'неизвестная',
                $last['message'],
                $last['file'],
                $last['line']
            )
        );

        self::respond(500, 'Фатальная ошибка', $last['message'], $last['file'], $last['line']);
    }

    private static function report(\Throwable $e, string $logFile): void
    {
        if (self::$reported) {
            return;
        }

        self::$reported = true;

        self::write(
            $logFile,
            sprintf(
                "%s: %s\nФайл: %s:%d\n%s\n",
                $e::class,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString()
            )
        );

        self::respond(500, 'Внутренняя ошибка', $e->getMessage(), $e->getFile(), $e->getLine());
    }

    /**
     * Подробности наружу — только при отладке.
     *
     * Конфигурация к этому моменту может быть и не загружена: ошибка в
     * `config.local.php` происходит ровно во время её загрузки. Отладочный
     * признак берётся у того контейнера, который успел подняться, а при его
     * отсутствии отладка выключается — иначе сломанный конфиг сам же себя и
     * покажет во всей красе на публичном сайте.
     */
    private static function debugOn(): bool
    {
        /*
         * `App::instance()` бросает исключение, пока контейнер не поднят, — а это
         * и есть самый частый случай: ошибка в `config.local.php` происходит до
         * `App::boot()`. Без защиты обработчик упал бы на месте, и вместо страницы
         * человек получил бы ровно то, от чего его спасают: ничего.
         */
        try {
            return App::instance()->config()->bool('app.debug');
        } catch (\Throwable) {
            return false;
        }
    }

    private static function write(string $logFile, string $text): void
    {
        $dir = dirname($logFile);

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        @file_put_contents(
            $logFile,
            '[' . date('Y-m-d H:i:s') . '] ' . self::redact($text) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * Убрать из записи то, что попадёт в чужой доступ.
     *
     * Текст ошибки почти всегда содержит адрес целиком, а в адресе сброса пароля
     * лежит сам ключ. Правило совпадает с журналом писем, чтобы не пришлось
     * вспоминать два разных.
     */
    private static function redact(string $text): string
    {
        $masked = (string) preg_replace_callback(
            '/\b(token|code)=([A-Za-z0-9._-]{12,})/i',
            static fn (array $m): string
                => $m[1] . '=' . substr($m[2], 0, 6) . '…(скрыт, ' . strlen($m[2]) . ' символов)',
            $text
        );

        return (string) preg_replace_callback(
            '/(?<![A-Za-z0-9_-])[0-9a-f]{32,}(?![A-Za-z0-9_-])/i',
            static fn (array $m): string => substr($m[0], 0, 6) . '…(скрыт, ' . strlen($m[0]) . ' символов)',
            $masked
        );
    }

    /**
     * Ответ страницей или данными — по тому, чего ждёт клиент.
     *
     * Запрос к данным, получивший страницу об ошибке, падает уже у клиента:
     * он не может её разобрать и показывает собственное общее сообщение вместо
     * причины. Признак тот же, что и у страницы ошибки ядра.
     */
    private static function respond(int $status, string $title, string $message, string $file, int $line): void
    {
        if (headers_sent()) {
            return;
        }

        $debug = self::debugOn();

        if (self::wantsJson()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            echo (string) json_encode(
                [
                    'ok' => false,
                    'error' => $title,
                    'message' => $debug ? $message : 'Подробности записаны в var/log/error.log',
                    'status' => $status,
                ],
                JSON_UNESCAPED_UNICODE
            );

            return;
        }

        $detail = $debug
            ? '<pre>' . htmlspecialchars(
                $message . "\n\n" . $file . ':' . $line,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            ) . '</pre>'
            : '<p>Подробности записаны владельцу сервера в <code>var/log/error.log</code>.</p>';

        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</title>'
            . '<style>body{font:16px/1.6 system-ui,sans-serif;margin:0;padding:2rem;background:#f7f7f8;'
            . 'color:#2b2b2b}main{max-width:40rem;margin:3rem auto;background:#fff;padding:2rem;'
            . 'border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.12)}h1{font-size:1.3rem;margin:0 0 1rem}'
            . 'pre{white-space:pre-wrap;background:#f2f2f4;padding:1rem;border-radius:6px;overflow:auto}'
            . 'a{color:#1a6fb4}</style></head><body><main><h1>'
            . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</h1><p>Страница не открылась. Попробуйте обновить её позже.</p>'
            . $detail
            . '<p><a href="/">Вернуться на главную</a></p></main></body></html>';
    }

    /*
 * Правило «хочет ли клиент данные» — то же, что и у ядра.
 *
 * Раньше обработчик повторял его у себя, и повторение разошлось с оригиналом:
 * здесь «text/html» в Accept отменял ответ данными, а в ядре — нет. Ошибка,
 * случившаяся на маршруте с данными, отдавалась страницей, а та же ошибка в
 * другом месте — данными. Теперь правило одно, и оно лежит в `Request`.
 */
private static function wantsJson(): bool
{
    return Request::wantsJsonRequest($_SERVER);
}
}
<?php

declare(strict_types=1);

namespace MyEdu\Core;

/**
 * Формирование HTTP-ответа. Отдаёт строку — так шаблоны остаются обычным PHP.
 */
final class Response
{
    /*
     * Заголовки безопасности, которые приложение ставит само.
     *
     * Раньше они жили только в public/.htaccess, то есть работали под Apache
     * и молча пропадали под nginx — а именно nginx документирован как способ
     * развёртывания. Приложение не должно зависеть от того, кто держит перед
     * собой соединение.
     *
     * Список короткий и осознанный:
     *   nosniff  — браузер не станет догадываться о типе ответа и выполнять
     *              файл вместо картинки;
     *   frame    — страницу нельзя встроить в чужой iframe (кликджакинг);
     *   referrer — полный адрес с токеном попытки не должен утекать наружу
     *              вместе с картинками сторонних сервисов, которых здесь нет.
     *
     * CSP без unsafe-inline возможен потому, что инлайн-скриптов и
     * инлайн-обработчиков в разметке не осталось: три оставшихся вынесены в
     * public/assets/js/. Возвращать inline-код нельзя — политику пришлось бы
     * ослабить целиком, то есть не ставить вовсе.
     */
    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'SAMEORIGIN',
        'Referrer-Policy'        => 'same-origin',
        'Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; "
            . "style-src 'self'; script-src 'self'; object-src 'none'; "
            . "base-uri 'self'; form-action 'self'; frame-ancestors 'self'",
    ];

    private string $body = '';

    private int $status = 200;

    /** @var array<string,string> */
    private array $headers = [];

    private function __construct(string $body, int $status = 200)
    {
        $this->body = $body;
        $this->status = $status;
    }

    public static function html(string $html, int $status = 200): self
    {
        $response = new self($html, $status);
        $response->headers['Content-Type'] = 'text/html; charset=utf-8';

        return $response;
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $response = new self($encoded === false ? '{}' : $encoded, $status);
        $response->headers['Content-Type'] = 'application/json; charset=utf-8';

        return $response;
    }

    public static function redirect(string $url, int $status = 302): self
    {
        $response = new self('', $status);
        $response->headers['Location'] = $url;

        return $response;
    }

    /**
     * Файл для скачивания.
     *
     * Имя файла обязательно: без него браузер предложит сохранить страницу под
     * именем адреса, и человек, качающий выгрузку перед удалением класса,
     * получал файл без расширения и не понимал, что с ним делать.
     *
     * `attachment` — тоже обязательный: выгрузку открывают не для просмотра, и
     * браузер, показавший CSV на странице, тут же предложил бы «сохранить как».
     */
    public static function download(string $body, string $filename, string $contentType): self
    {
        $safe = str_replace(['"', "\r", "\n"], '', $filename);

        $response = new self($body, 200);
        $response->headers['Content-Type'] = $contentType;
        $response->headers['Content-Disposition'] = 'attachment; filename="' . $safe . '"';
        $response->headers['Content-Length'] = (string) strlen($body);

        return $response;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): void
{
        if (!headers_sent()) {
        http_response_code($this->status);

            foreach (self::SECURITY_HEADERS as $name => $value) {
                // Заголовок, поставленный явно, важнее общего: так страница
                // сможет ослабить правило там, где это осознанно.
                if (!isset($this->headers[$name])) {
                    header($name . ': ' . $value);
                }
            }

          foreach ($this->headers as $name => $value) {
              header($name . ': ' . $value);
            }
        }

        echo $this->body;
    }
}
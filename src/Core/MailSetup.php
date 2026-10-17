<?php

declare(strict_types=1);

namespace MyEdu\Core;

/**
 * Как настроена отправка писем — в понятных странице терминах.
 *
 * Существует из-за одной практической беды: по наличию адреса локального
 * почтовика нельзя судить, уходят ли письма на самом деле. Одноимённая
 * настройка `dev_web_ui` оставалась в конфигурации с машины разработки после
 * переноса на боевой сервер, и страница регистрации начинала требовать открыть
 * `http://127.0.0.1:8025` на боевом сайте — там ничего не слушает, а письма
 * при этом уходят самым обычным образом.
 *
 * Три режима:
 *
 * - `real` — письма уходят получателям. Никаких подсказок про разработку;
 * - `local` — драйвер SMTP, но сервер на этой же машине: письма собирает
 *   локальный почтовик, а не почтовый оператор;
 * - `log` — отправки нет вовсе, письма пишутся в файл.
 */
final class MailSetup
{
    public const MODE_REAL = 'real';
    public const MODE_LOCAL = 'local';
    public const MODE_LOG = 'log';

    public function __construct(
        public readonly string $mode,
        public readonly string $driver,
        public readonly string $label,
        public readonly string $webUi = ''
    ) {
    }

    public function isReal(): bool
    {
        return $this->mode === self::MODE_REAL;
    }

    /** Отправки нет: письма лежат в файле. */
    public function isLog(): bool
    {
        return $this->mode === self::MODE_LOG;
    }

    /** Письма собирает локальный почтовик вместо отправки наружу. */
    public function isLocal(): bool
    {
        return $this->mode === self::MODE_LOCAL;
    }

    /**
     * Показать ли подсказку про то, что письма не уходят получателям.
     *
     * Подсказка нужна ровно в двух случаях, и в обоих человек должен знать,
     * куда смотреть за письмом. На настоящей отправке она не нужна и только
     * сбивает: человек ищет файл, которого нет.
     */
    public function needsHint(): bool
    {
        return $this->isLog() || $this->isLocal();
    }
}
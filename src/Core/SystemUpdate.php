<?php

declare(strict_types=1);

namespace MyEdu\Core;

use RuntimeException;
use Throwable;

/**
 * Обновление системы на сервере из релизов GitHub.
 *
 * Смысл: владелец сайта не должен заливать файлы по FTP ради каждой правки.
 * Он открывает «Настройки» (только администратор), видит, что на GitHub есть
 * более свежий релиз, и нажимает «Обновить»: система сама скачивает архив
 * релиза, снимает копию базы и раскладывает файлы поверх прежних.
 *
 * **Порядок именно такой, и он не случаен.**
 *
 * 1. Копия базы — до того, как что-либо поменялось. Миграция меняет схему, а
 *    DDL в MySQL не откатывается: `DROP COLUMN` или `DROP TABLE`, применённые
 *    по ошибке, возвращаются только из копии. Схему обновлённой версии
 *    применяет `SchemaGate` при первом же заходе на страницу, и копия должна
 *    быть уже снята к этому моменту.
 * 2. Архив скачивается и проверяется до того, как что-либо распаковывается:
 *    размер, состав, контрольная сумма. Сумма приходит от GitHub вместе со
 *    сведениями о релизе (поле `digest` у приложенного архива, `sha256:…`) и
 *    сверяется со скачанным файлом; у архива без суммы целостность держится
 *    только на TLS-соединении, и об этом журнал говорит прямо.
 * 3. Файлы кладутся поверх прежних. Файлы, которых в новой версии нет, остаются
 *    на месте: живой сайт не должен зависеть от того, что кто-то удалил файл
 *    из новой сборки, — решение об этом у владельца.
 *
 * **Что при обновлении не трогается.** Копия `config/config.local.php` с
 * доступами к базе, загруженные картинки (`public/uploads`), журналы и почта
 * (`var/`), каталог вендорных исходников. Архив релиза их и не содержит, но
 * проверка идёт и по списку: замена файлов не должна зависеть от того, что
 * именно попало в сборку.
 */
final class SystemUpdate
{
    /** Куда кладутся скачанные архивы. Вне проекта: файлы не должны попасть в корень сайта. */
    private const WORK_DIRECTORY = 'myedu-update';

    /** Сколько байт архива релиза считается допустимыми. */
    private const MAX_ASSET_BYTES = 64 * 1024 * 1024;

    /** Сколько секунд живёт ответ о последнем релизе. */
    private const CHECK_TTL = 900;

    /**
     * Куда в проекте нельзя класть файлы.
     *
     * Первые три — данные школы: без них сайт перестаёт работать или теряет
     * то, что в него налили. Остальное — то, что не должно попадать в корень
     * сайта ни при каких условиях.
     *
     * Установщик (`public/install.php`) в этом списке не случайно: свежую
     * установку он обслуживает и в релизном архиве остаётся, но обновляемому
     * серверу не нужен — там база уже работает, а установщик умеет только
     * создавать её на пустой базе, и точка входа на живом сервере ему ни к
     * чему. Релиз, пришедший обновлением, установщиком не распаковывается.
     *
     * @var list<string>
     */
    private const FORBIDDEN = [
        'var/',
        'config/config.local.php',
        'public/install.php',
        'public/uploads/',
        'resources/',
        '.git/',
    ];

    /**
     * Где на сервере обычно лежат сертификаты.
     *
     * Список — потому, что PHP ищет пакет сертификатов по своему адресу, а
     * на сервере он нередко лежит в другом месте: сертификаты есть, а найти
     * их нечем. Перебор ничего не портит — проверка сертификата либо проходит,
     * либо нет.
     *
     * @var list<string>
     */
    private const CA_BUNDLES = [
        '/etc/ssl/certs/ca-certificates.crt',
        '/etc/pki/tls/certs/ca-bundle.crt',
        '/etc/ssl/ca-bundle.pem',
        '/etc/pki/tls/cacert.pem',
        '/usr/local/share/certs/ca-root-nss.crt',
        '/usr/local/share/ca-certificates/ca-certificates.crt',
        '/etc/ssl/cert.pem',
    ];

    public function __construct(
        private readonly App $app,
        private readonly Config $config,
        private readonly DatabaseDump $dump
    ) {
    }

    /**
     * Последний релиз на GitHub.
     *
     * @return array{ok:bool,version?:string,tag?:string,url?:string,published?:string,error?:string}
     */
    public function latest(bool $fresh = false): array
    {
        $cache = $this->cacheFile();

        if (!$fresh && is_file($cache)) {
            $age = time() - (int) @filemtime($cache);
            $cached = @file_get_contents($cache);

            if ($age < self::CHECK_TTL && is_string($cached) && $cached !== '') {
                $decoded = json_decode($cached, true);

                if (is_array($decoded)) {
                    return $decoded + ['ok' => true];
                }
            }
        }

        $repository = trim((string) $this->config->string('app.update.repository', ''));

        if ($repository === '') {
            return ['ok' => false, 'error' => 'В настройках не указан репозиторий с релизами.'];
        }

        /*
         * Список релизов, а не «последний релиз».
         *
         * Адрес .../releases/latest отдаёт релиз без отметки «предрелиз»:
         * предрелизы в него не попадают. А беты проекта — они и есть
         * предрелизы, и с этой отметкой в GitHub их не видно вовсе: сайт с
         * бетой на сервере показывал «установлена последняя версия», хотя на
         * GitHub лежала версия новее, и обновление не предлагалось. Список
         * берётся целиком, а нужный релиз выбирается по номеру версии.
         */
        $url = 'https://api.github.com/repos/' . trim($repository, '/') . '/releases?per_page=30';
        $answer = $this->fetch($url, 20);

        if (!is_string($answer) || $answer === '') {
            $error = $this->lastError ?? 'Ответ GitHub не получен.';

            return ['ok' => false, 'error' => $error];
        }

        $releases = json_decode($answer, true);

        if (!is_array($releases)) {
            return ['ok' => false, 'error' => 'Ответ GitHub не разобран.'];
        }

        if (isset($releases['message']) && !isset($releases[0])) {
            return ['ok' => false, 'error' => 'GitHub: ' . (string) $releases['message']];
        }

        $release = self::pickLatest($releases);
        $prerelease = self::newestPrerelease($releases);

        if ($release === null) {
            if ($prerelease !== null) {
                return [
                    'ok' => false,
                    'error' => 'На GitHub есть только предрелиз ' . Version::display($prerelease)
                        . '. Он не предлагается для установки: снимите с него отметку «предрелиз» в GitHub.',
                ];
            }

            return ['ok' => false, 'error' => 'На GitHub нет ни одного релиза.'];
        }

        $tag = (string) ($release['tag_name'] ?? '');

        if ($tag === '') {
            return ['ok' => false, 'error' => 'В релизе не указан номер версии.'];
        }

        $asset = $this->releaseAsset(is_array($release['assets'] ?? null) ? $release['assets'] : []);

        /*
         * Откуда брать файлы.
         *
         * У релиза на GitHub может быть приложен готовый архив — его и берём
         * первым. Если приложенного архива нет, берётся исходный архив тега:
         * он и есть те же файлы проекта, только в каталоге
         * «имя-репозитория-тег», и общий каталог при распаковке снимается.
         *
         * Отдельный архив релиза остаётся лучшим выбором: в нём только то,
         * что нужно на сервере, и никаких наборов проверок с документацией.
         */
        $url = $asset !== null ? (string) $asset['url'] : $this->tagArchive($repository, $tag);

        $result = [
            'ok' => true,
            'version' => Version::display($tag),
            'tag' => $tag,
            'asset' => true,
            'url' => $url,
            /*
             * Контрольная сумма приложенного архива, как её отдал GitHub:
             * «sha256:…». У исходного архива тега суммы нет — он собирается
             * сервером GitHub на лету, и сверять его не с чем.
             */
            'digest' => $asset !== null ? (string) ($asset['digest'] ?? '') : '',
            'published' => (string) ($release['published_at'] ?? ''),
            'error' => '',
            /*
             * Предрелиз с GitHub, если он есть. Само обновление он не
             * заменяет: отметка «предрелиз» означает «не предлагать». Но
             * сказать о нём надо — иначе страница скажет «установлена
             * последняя версия», а на GitHub лежит версия новее, и выглядит
             * это как поломка проверки.
             */
            'prerelease' => $prerelease !== null ? Version::display($prerelease) : '',
        ];

        $this->cache($result);

        return $result;
    }

    /**
     * Новейший релиз из списка на GitHub.
     *
     * Черновики пропускаются, предрелизы — тоже: отметка «предрелиз» означает
     * «не предлагать для установки». Из остальных берётся наибольший номер
     * версии, а не самый свежий по дате: вышедший позже старый номер не
     * должен вытеснять новый.
     *
     * @param list<mixed> $releases список как его отдаёт GitHub
     *
     * @return array<string,mixed>|null
     */
    public static function pickLatest(array $releases): ?array
    {
        $best = null;
        $bestTag = '';

        foreach ($releases as $release) {
            if (!is_array($release)) {
                continue;
            }

            if (($release['draft'] ?? false) === true || ($release['prerelease'] ?? false) === true) {
                continue;
            }

            $tag = trim((string) ($release['tag_name'] ?? ''));

            if ($tag === '') {
                continue;
            }

            if ($best === null) {
                $best = $release;
                $bestTag = $tag;

                continue;
            }

            $comparison = Version::compare($tag, $bestTag);

            if ($comparison > 0) {
                $best = $release;
                $bestTag = $tag;

                continue;
            }

            /*
             * Один и тот же номер — берём тот, что вышел позже: тот у него
             * свежее приложенный архив.
             */
            if ($comparison === 0
                && (string) ($release['published_at'] ?? '') > (string) ($best['published_at'] ?? '')) {
                $best = $release;
                $bestTag = $tag;
            }
        }

        return $best;
    }

    /**
     * Тег новейшего предрелиза — если он есть.
     *
     * @param list<mixed> $releases список как его отдаёт GitHub
     */
    public static function newestPrerelease(array $releases): ?string
    {
        $best = null;

        foreach ($releases as $release) {
            if (!is_array($release)) {
                continue;
            }

            if (($release['draft'] ?? false) === true || ($release['prerelease'] ?? false) !== true) {
                continue;
            }

            $tag = trim((string) ($release['tag_name'] ?? ''));

            if ($tag === '') {
                continue;
            }

            if ($best === null || Version::compare($tag, $best) > 0) {
                $best = $tag;
            }
        }

        return $best;
    }

    /**
     * Адрес исходного архива тега.
     *
     * GitHub собирает его сам: `https://github.com/владелец/репозиторий/archive/refs/tags/тег.zip`.
     * Внутри — все файлы репозитория в каталоге `имя-репозитория-тег`.
     */
    private function tagArchive(string $repository, string $tag): string
    {
        return 'https://github.com/' . trim($repository, '/') . '/archive/refs/tags/' . rawurlencode($tag) . '.zip';
    }

    /**
     * Текущая версия системы.
     */
    public function currentVersion(): string
    {
        return (string) $this->config->string('app.version', '');
    }

    /**
     * Выключена ли проверка сертификата GitHub.
     *
     * Проверка выключается решением владельца, когда сервер не может проверить
     * сертификат. Обновление кладёт на сервер файлы, из которых работает весь
     * сайт, поэтому страница настроек обязана напоминать о выключенной
     * проверке, пока та выключена, — а не молча обновляться без неё.
     */
    public function tlsCheckDisabled(): bool
    {
        return $this->config->bool('app.update.allow_insecure_tls', false);
    }

    /**
     * Старше ли найденный на GitHub релиз установленной версии.
     *
     * Отдельный вопрос, а не следствие `hasUpdate()`. «Обновлений нет» и
     * «установлена последняя версия» — не одно и то же: если на сервере стоит
     * версия новее, чем лежит на GitHub, обновлений действительно нет, но
     * называть версию с GitHub последней нельзя. Проверка обновления кэшируется
     * на четверть часа, и сразу после выпуска свежего релиза кэш ещё старый:
     * без этого вопроса страница говорила «Установлена последняя версия» и
     * называла версию, которой на сервере нет.
     *
     * @param array<string,mixed> $latest
     */
    public function isReleaseOlder(array $latest): bool
    {
        $tag = (string) ($latest['tag'] ?? '');

        if ($tag === '') {
            return false;
        }

        return Version::isOlder($tag, $this->currentVersion());
    }

    /**
     * Есть ли на GitHub более свежий релиз.
     *
     * @param array<string,mixed> $latest
     */
    public function hasUpdate(array $latest): bool
    {
        if (($latest['ok'] ?? false) !== true) {
            return false;
        }

        $tag = (string) ($latest['tag'] ?? '');

        if ($tag === '') {
            return false;
        }

        /*
         * Без готового архива обновления нет: скачивать нечего, а собрать
         * архив релиза на сервере из исходников GitHub нельзя.
         */
        if (($latest['asset'] ?? false) !== true) {
            return false;
        }

        return Version::isNewer($tag, $this->currentVersion());
    }

    /**
     * Обновить систему.
     *
     * @return array{ok:bool,message:string,files?:int,backup?:string,version?:string}
     */
    public function apply(): array
    {
        $latest = $this->latest(true);

        if (($latest['ok'] ?? false) !== true) {
            $reason = (string) ($latest['error'] ?? 'неизвестная причина.');

            /*
             * Ошибка проверки версии пишется в журнал: раньше владелец видел
             * её только на странице, а в логах не было ничего — диагностировать
             * приходилось вслепую. `log()` дублирует запись в журнал PHP, куда
             * владелец смотрит на шаред-хостинге.
             */
            $this->log('Проверка новой версии не удалась: ' . $reason);

            return [
                'ok' => false,
                'message' => 'Обновление не выполнено: ' . $reason,
            ];
        }

        if (!$this->hasUpdate($latest)) {
            return [
                'ok' => false,
                'message' => 'Уже установлена последняя версия: ' . $this->currentVersion() . '.',
            ];
        }

        $url = (string) ($latest['url'] ?? '');

        if ($url === '') {
            return ['ok' => false, 'message' => 'В релизе нет архива для скачивания.'];
        }

        /*
         * Копия базы — до скачивания и до распаковки.
         *
         * `DatabaseDump` сам решает, чем снимать: утилитой, если она есть на
         * сервере, или средствами PHP, если нет. Отказ снимает копии при
         * `security.backup_before_migrations_blocking` — и тогда обновление
         * останавливается: файлы менять нельзя, пока нечем откатиться.
         */
        $backup = null;

        try {
            $backup = $this->dump->take('обновление системы до версии ' . (string) $latest['version']);
        } catch (Throwable $e) {
            $this->log('Копия базы не снята, обновление остановлено: ' . $e->getMessage());

            return [
                'ok' => false,
                'message' => 'Обновление остановлено: не снята резервная копия базы. ' . $e->getMessage(),
            ];
        }

        $directory = $this->workDirectory();
        $archive = $directory . '/release.zip';

        try {
            $this->download($url, $archive, (string) ($latest['digest'] ?? ''));
        } catch (Throwable $e) {
            $this->remove($archive);

            // Причина уже записана в журнал внутри download().
            return ['ok' => false, 'message' => 'Обновление не выполнено: ' . $e->getMessage()];
        }

        $result = self::install($archive, $this->app->rootDir());

        $this->remove($archive);

        if (($result['ok'] ?? false) !== true) {
            $this->log('Архив не применён: ' . (string) ($result['message'] ?? 'причина не указана'));

            return $result;
        }

        $this->log('Система обновлена до версии ' . (string) $latest['version']
            . ', файлов заменено: ' . (int) ($result['files'] ?? 0)
            . ', копия базы: ' . ($backup ?? 'не снята'));

        return [
            'ok' => true,
            'message' => 'Система обновлена до версии ' . (string) $latest['version']
                . '. Файлов заменено: ' . (int) ($result['files'] ?? 0) . '.',
            'files' => (int) ($result['files'] ?? 0),
            'backup' => $backup,
            'version' => (string) $latest['version'],
        ];
    }

    /**
     * Разложить архив поверх проекта.
     *
     * Копия базы снимается отдельно — в `apply()`, до скачивания. Здесь только
     * распаковка: так её можно проверить, положив архив в отдельный каталог и
     * не трогая настоящий проект.
     *
     * @param string $archive путь к архиву
     * @param string $root    куда раскладывать
     *
     * @return array{ok:bool,message:string,files?:int}
     */
    public static function install(string $archive, string $root): array
    {
        try {
            $result = self::unpack($archive, $root);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Обновление не выполнено: ' . $e->getMessage()];
        }

        $files = (int) ($result['files'] ?? 0);
        $skipped = is_array($result['skipped'] ?? null) ? $result['skipped'] : [];

        $message = 'Файлов заменено: ' . $files . '.';

        if ($skipped !== []) {
            $message .= ' Пропущено файлов, которые на сервере трогать нельзя: ' . count($skipped) . '.';
        }

        return [
            'ok' => true,
            'message' => $message,
            'files' => $files,
            'skipped' => $skipped,
        ];
    }

    // ------------------------------------------------------------------ внутри

    /**
     * Распаковать архив поверх проекта.
     *
     * @return array{ok:bool,message:string,files?:int,skipped?:list<string>}
     */
    private static function unpack(string $archive, string $root): array
    {
        $files = \MyEdu\Transfer\Zip::read($archive);
        $prefix = self::commonPrefix(array_keys($files));
        $written = 0;
        $skipped = [];

        foreach ($files as $name => $content) {
            $relative = self::safePath(self::stripPrefix($name, $prefix));

            /*
             * Запрещённый путь — не повод отказывать от всего обновления.
             *
             * В исходном архиве тега лежат и данные (настройки с доступами к
             * базе, загруженные картинки), и служебные каталоги. Такие файлы
             * пропускаются: прежние остаются на месте, а обновление идёт дальше.
             * Отказ здесь означал бы, что обновиться нельзя никогда.
             */
            if ($relative === null) {
                $skipped[] = $name;

                continue;
            }

            $target = rtrim($root, '/\\') . '/' . $relative;
            $directory = dirname($target);

            if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException('Не удалось создать каталог ' . $directory);
            }

            if (@file_put_contents($target, $content) === false) {
                throw new RuntimeException('Не удалось записать файл ' . $relative . ' (нет прав на запись).');
            }

            $written++;
        }

        if ($written === 0) {
            throw new RuntimeException('В архиве нет ни одного файла.');
        }

        return ['files' => $written, 'skipped' => $skipped];
    }

    /**
     * Общий каталог верхнего уровня у всех файлов архива.
     *
     * Исходный архив тега на GitHub лежит в каталоге `имя-репозитория-тег`:
     * без снятия этого каталога файлы разложились бы в подкаталог, и обновление
     * ничего бы не заменило.
     *
     * @param list<string> $names
     */
    private static function commonPrefix(array $names): string
    {
        $prefix = null;

        foreach ($names as $name) {
            $slash = strpos($name, '/');

            if ($slash === false) {
                return '';
            }

            $part = substr($name, 0, $slash + 1);

            if ($prefix === null) {
                $prefix = $part;

                continue;
            }

            if ($prefix !== $part) {
                return '';
            }
        }

        return $prefix ?? '';
    }

    /**
     * Снять общий каталог с пути.
     */
    private static function stripPrefix(string $name, string $prefix): string
    {
        if ($prefix === '' || !str_starts_with($name, $prefix)) {
            return $name;
        }

        return substr($name, strlen($prefix));
    }

    /**
     * Путь внутри проекта, безопасный для записи.
     *
     * null — путь запрещён: он ведёт наружу или туда, куда писать нельзя.
     *
     * Запрещённые префиксы сверяются без учёта регистра. На Linux файловые
     * имена различают буквы, и `VAR/log` был бы другим каталогом; на Windows —
     * нет, а архивы приезжают и туда (локальная разработка, тестовые машины).
     * Одинаковое правило на обеих системах стоит того, что гипотетический файл
     * проекта с заглавной буквы в запрещённом каталоге будет пропущен — таких
     * путей в проекте нет.
     */
    public static function safePath(string $name): ?string
    {
        $normalized = str_replace('\\', '/', trim($name));

        if ($normalized === '' || str_starts_with($normalized, '/')) {
            return null;
        }

        if (str_contains($normalized, '../') || str_ends_with($normalized, '/..') || $normalized === '..') {
            return null;
        }

        if (str_contains($normalized, "\0")) {
            return null;
        }

        $lowered = strtolower($normalized);

        foreach (self::FORBIDDEN as $forbidden) {
            if (str_starts_with($lowered, strtolower($forbidden))) {
                return null;
            }
        }

        return $normalized;
    }

    /**
     * Скачать архив релиза.
     *
     * Проверка результата — `downloadSucceeded()`, а не `is_string`: при
     * скачивании в файл `fetch()` возвращает true (работа сделана — тело уже
     * лежит в $target), и прежняя проверка отвергала ИМЕННО успешное
     * скачивание: исключение «Архив не скачан: неизвестная причина»
     * появлялось при lastError = null, то есть без единой причины. Так
     * обновление не работало вовсе, а в журнале не было ничего, потому что
     * запись туда стоит только на успешном конце пути.
     */
    private function download(string $url, string $target, string $digest = ''): void
    {
        $body = $this->fetch($url, 120, $target);

        if (!self::downloadSucceeded($body)) {
            $reason = $this->lastError ?? 'причина не записана — подробности в var/log/update.log';

            $this->log('Скачивание архива не удалось: ' . $reason . ' (адрес: ' . $url . ')');

            throw new RuntimeException('Архив не скачан: ' . $reason . '.');
        }

        $size = (int) @filesize($target);

        if ($size <= 0) {
            $this->log('Скачанный файл пустой: ' . $target . ' (адрес: ' . $url . ')');

            throw new RuntimeException('Скачанный файл пустой.');
        }

        if ($size > self::MAX_ASSET_BYTES) {
            throw new RuntimeException('Архив релиза слишком большой: ' . round($size / 1024 / 1024) . ' МБ.');
        }

        /*
         * Контрольная сумма — до распаковки.
         *
         * Сумму отдал GitHub вместе со сведениями о релизе, и сверяется она с
         * тем, что реально легло на диск: обрыв загрузки, подмена файла другим
         * ассетом или байт в середине потока — всё это отказ, а не обновление.
         * Распаковки при отказе не будет вовсе: проверка стоит раньше неё.
         *
         * Суммы может не быть (исходный архив тега, старый формат ответа):
         * тогда остаётся только TLS, и об этом сказано в журнале, а не
         * промолчано.
         */
        if ($digest !== '') {
            if (!self::fileMatchesDigest($target, $digest)) {
                $this->log('Контрольная сумма архива не совпала с релизом: файл отвергнут'
                    . ' (адрес: ' . $url . ', ждали: ' . $digest . ')');

                throw new RuntimeException(
                    'Скачанный архив не совпал с контрольной суммой релиза. Обновление не выполнено: '
                    . 'файл мог повредиться при передаче. Повторите обновление; если отказ повторяется, '
                    . 'сверьте выпуск на GitHub.'
                );
            }
        } else {
            $this->log('У архива релиза нет контрольной суммы: целостность держится'
                . ' только на TLS-соединении (адрес: ' . $url . ')');
        }
    }

    /**
     * Алгоритм контрольной суммы в записи «sha256:hex…».
     *
     * null — запись не разобрана: нет двоеточия, алгоритма нет в этой сборке
     * PHP или шестнадцатеричная часть не той длины. Проверка длины по самому
     * алгоритму, а не по списку известных: единое правило для любого алгоритма.
     */
    public static function digestAlgorithm(string $digest): ?string
    {
        $colon = strpos($digest, ':');

        if ($colon === false) {
            return null;
        }

        $algo = strtolower(substr($digest, 0, $colon));
        $value = substr($digest, $colon + 1);

        if (!in_array($algo, hash_algos(), true)) {
            return null;
        }

        return is_string($value)
            && ctype_xdigit($value)
            && strlen($value) === strlen(hash($algo, ''))
            ? $algo
            : null;
    }

    /**
     * Совпадает ли файл с контрольной суммой вида «sha256:hex…».
     *
     * Сравнение через hash_equals: одинаковая длина и одинаковое время на
     * совпадение и на расхождение, чтобы время проверки ничего не подсказывало.
     */
    public static function fileMatchesDigest(string $file, string $digest): bool
    {
        $algo = self::digestAlgorithm($digest);

        if ($algo === null || !is_file($file)) {
            return false;
        }

        $actual = hash_file($algo, $file);
        $expected = substr($digest, strpos($digest, ':') + 1);

        return is_string($actual) && hash_equals($expected, $actual);
    }

    /**
     * Удалось ли скачивание.
     *
     * Разные пути `fetch()` отвечают по-разному, и это нормально:
     *
     *  - запись в файл (тот случай, что здесь) — true при успехе, false при
     *    отказе, тело не возвращается вовсе, оно в файле;
     *  - чтение в память — строка с телом, пустая строка при отказе чтения
     *    внутри потока.
     *
     * Именно поэтому проверка «строка и не пустая» здесь неверна: к ней
     * успех записи в файл не проходит. Метод вынесен отдельно, чтобы правило
     * проверялось тестами, а не додумывалось при каждой правке.
     */
    public static function downloadSucceeded(mixed $result): bool
    {
        if ($result === true) {
            return true;
        }

        return is_string($result) && $result !== '';
    }

    /**
     * Скачать через curl, перебирая варианты проверки сертификата.
     *
     * null — ни один вариант не подошёл, и об ошибке сказано в `lastError`.
     *
     * @param resource|null $handle
     * @param list<string>  $headers
     * @return string|bool|null
     */
    private function fetchWithCurl(
        string $url,
        int $timeout,
        ?string $toFile,
        $handle,
        array $headers
    ): string|bool|null {
        $lastError = '';

        foreach ($this->tlsVariants() as $variant) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => $toFile === null,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_USERAGENT => 'my.edu',
            ] + $variant);

            if ($handle !== null) {
                curl_setopt($curl, CURLOPT_FILE, $handle);
            }

            $body = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);

            /*
             * Ручку закрывать не надо: с PHP 8.0 она ничего не делает, а с
             * PHP 8.5 объявлена устаревшей, и сервер с этой версией печатал
             * предупреждение прямо в ответ на обновление. Ресурс освобождается
             * сам, когда переменная выходит из области видимости.
             */

            if ($body !== false && $status < 400) {
                $this->lastError = null;

                return $toFile === null ? (string) $body : true;
            }

            $lastError = $error !== '' ? $error : ('GitHub ответил кодом ' . $status . '.');

            /*
             * Файл ответа при неудаче обрезается: иначе в следующей попытке
             * он допишется к предыдущему, и на диске окажется половина архива
             * плюс начало другого.
             */
            if ($handle !== null) {
                @rewind($handle);
                @ftruncate($handle, 0);
            }
        }

        $this->lastError = $lastError;

        if ($lastError !== '' && stripos($lastError, 'certificate') !== false) {
            $this->lastError = $lastError
                . ' Сервер не смог проверить сертификат GitHub: обновите сертификаты на сервере '
                . '(пакет ca-certificates) или укажите путь к свежему файлу в config/config.local.php: '
                . "['app']['update']['ca_bundle'].";
        }

        return null;
    }

    /**
     * Варианты проверки сертификата, по возрастанию уступчивости.
     *
     * Сначала — как есть: системные сертификаты сервера. Потом — тот же
     * файл, но из известных мест: на сервере пакет сертификатов иногда лежит
     * не там, где его ищет PHP, и в этом случае достаточно указать путь.
     *
     * И только потом — по решению владельца проверка выключается. Обновление
     * кладёт на сервер файлы, из которых работает весь сайт, и подделать их
     * может тот, кто сумел встать посередине соединения; молча ослаблять
     * проверку здесь нельзя.
     *
     * @return list<array<int,mixed>>
     */
    private function tlsVariants(): array
    {
        $variants = [[]];

        $configured = trim((string) $this->config->string('app.update.ca_bundle', ''));

        if ($configured !== '' && is_file($configured)) {
            $variants[] = [CURLOPT_CAINFO => $configured];
        }

        foreach (self::CA_BUNDLES as $path) {
            if (is_file($path)) {
                $variants[] = [CURLOPT_CAINFO => $path];
            }
        }

        if ($this->config->bool('app.update.allow_insecure_tls', false)) {
            $variants[] = [
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
            ];
        }

        return $variants;
    }

    /**
     * Скачать адрес.
     *
     * $toFile — куда положить тело ответа; без неё возвращается строка.
     *
     * @return string|bool тело ответа или false при неудаче
     */
    private function fetch(string $url, int $timeout, ?string $toFile = null): string|bool
    {
        $this->lastError = null;

        if (!preg_match('#^https://#i', $url)) {
            $this->lastError = 'Скачивание разрешено только по https.';

            return false;
        }

        $handle = $toFile !== null ? @fopen($toFile, 'wb') : null;

        if ($toFile !== null && $handle === false) {
            $this->lastError = 'Не удалось создать временный файл для загрузки.';

            return false;
        }

        $headers = [
            'Accept: application/vnd.github+json',
            'User-Agent: my.edu',
            'Accept-Encoding: identity',
        ];

        $curlError = null;

        if (function_exists('curl_init')) {
            $result = $this->fetchWithCurl($url, $timeout, $toFile, $handle, $headers);

            if ($result !== null) {
                return $result;
            }

            $curlError = $this->lastError;
        }

        /*
         * Без расширения curl остаётся потоковое чтение.
         *
         * `allow_url_fopen` на сервере может быть выключен — тогда сработает
         * только этот путь, и он тоже должен честно сказать об отказе, а не
         * вернуть пустую строку.
         */
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'header' => implode("\r\n", $headers),
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $stream = @fopen($url, 'rb', false, $context);

        if ($stream === false) {
            $this->lastError = $curlError !== null
                ? $curlError . ' Потоковое чтение тоже не помогло: на сервере нет ни curl, ни доступа к внешним адресам.'
                : 'Скачать не удалось: на сервере нет ни curl, ни доступа к внешним адресам.';

            return false;
        }

        $body = '';

        while (!feof($stream)) {
            $chunk = fread($stream, 65536);

            if ($chunk === false) {
                break;
            }

            if ($handle !== null) {
                fwrite($handle, $chunk);
            } else {
                $body .= $chunk;
            }
        }

        fclose($stream);

        if ($handle !== null) {
            fclose($handle);
        }

        return $toFile === null ? $body : true;
    }

    /**
     * Найти в релизе готовый архив.
     *
     * Контрольная сумма берётся рядом с адресом: GitHub отдаёт её в поле
     * `digest` того же ассета. Старые ответы GitHub и исходные архивы тега
     * поля могут не иметь — пустая строка означает «сверять не с чем», и это
     * не повод отказываться от обновления.
     *
     * @param list<mixed> $assets
     * @return array{url:string,name:string,digest:string}|null
     */
    private function releaseAsset(array $assets): ?array
    {
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }

            $name = (string) ($asset['name'] ?? '');
            $url = (string) ($asset['browser_download_url'] ?? '');

            if ($name === '' || $url === '') {
                continue;
            }

            if (!str_ends_with(strtolower($name), '.zip')) {
                continue;
            }

            return [
                'url'    => $url,
                'name'   => $name,
                'digest' => (string) ($asset['digest'] ?? ''),
            ];
        }

        return null;
    }

    /**
     * Временный каталог для загрузки.
     */
    private function workDirectory(): string
    {
        $base = sys_get_temp_dir() . '/' . self::WORK_DIRECTORY;

        if (!is_dir($base) && !@mkdir($base, 0700, true) && !is_dir($base)) {
            throw new RuntimeException('Не удалось создать временный каталог ' . $base);
        }

        return $base;
    }

    /**
     * Файл с ответом о последнем релизе.
     */
    private function cacheFile(): string
    {
        return $this->app->rootDir() . '/var/update/last-release.json';
    }

    /**
     * @param array<string,mixed> $result
     */
    private function cache(array $result): void
    {
        $file = $this->cacheFile();
        $directory = dirname($file);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        @file_put_contents($file, (string) json_encode($result, JSON_UNESCAPED_UNICODE));
    }

    private function remove(string $file): void
    {
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * Запись в журнал обновлений.
     *
     * Дублируется в error_log: журнал `var/log/update.log` пишется самим
     * приложением и зависит от прав каталога, а журнал PHP на шаред-хостинге
     * владелец видит всегда — именно туда он и смотрит, когда письма или
     * обновление «просто не работают». Причина одной ошибки попадает в оба
     * места и видна с двух сторон.
     */
    private function log(string $message): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;

        $file = $this->app->rootDir() . '/var/log/update.log';
        $directory = dirname($file);

        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);

        error_log('[update] ' . $message);
    }

    private ?string $lastError = null;
}

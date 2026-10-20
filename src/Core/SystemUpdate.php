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
 *    размер, состав, контрольные суммы. Архив бомбы должен отклониться, не
 *    оставив систему в полусломанном виде.
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
     * @var list<string>
     */
    private const FORBIDDEN = [
        'var/',
        'config/config.local.php',
        'public/uploads/',
        'resources/',
        '.git/',
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

        $url = 'https://api.github.com/repos/' . trim($repository, '/') . '/releases/latest';
        $answer = $this->fetch($url, 20);

        if (!is_string($answer) || $answer === '') {
            $error = $this->lastError ?? 'Ответ GitHub не получен.';

            return ['ok' => false, 'error' => $error];
        }

        $release = json_decode($answer, true);

        if (!is_array($release)) {
            return ['ok' => false, 'error' => 'Ответ GitHub не разобран.'];
        }

        if (isset($release['message']) && !isset($release['tag_name'])) {
            return ['ok' => false, 'error' => 'GitHub: ' . (string) $release['message']];
        }

        $tag = (string) ($release['tag_name'] ?? '');

        if ($tag === '') {
            return ['ok' => false, 'error' => 'В релизе не указан номер версии.'];
        }

        $asset = $this->releaseAsset(is_array($release['assets'] ?? null) ? $release['assets'] : []);

        $result = [
            'ok' => true,
            'version' => Version::display($tag),
            'tag' => $tag,
            'asset' => $asset !== null,
            'url' => $asset !== null ? (string) $asset['url'] : '',
            'published' => (string) ($release['published_at'] ?? ''),
            'error' => $asset === null
                ? 'В релизе ' . $tag . ' нет готового архива (.zip).'
                : '',
        ];

        $this->cache($result);

        return $result;
    }

    /**
     * Текущая версия системы.
     */
    public function currentVersion(): string
    {
        return (string) $this->config->string('app.version', '');
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
            return [
                'ok' => false,
                'message' => 'Обновление не выполнено: ' . (string) ($latest['error'] ?? 'неизвестная причина.'),
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
            return [
                'ok' => false,
                'message' => 'Обновление остановлено: не снята резервная копия базы. ' . $e->getMessage(),
            ];
        }

        $directory = $this->workDirectory();
        $archive = $directory . '/release.zip';

        try {
            $this->download($url, $archive);
        } catch (Throwable $e) {
            $this->remove($archive);

            return ['ok' => false, 'message' => 'Обновление не выполнено: ' . $e->getMessage()];
        }

        $result = self::install($archive, $this->app->rootDir());

        $this->remove($archive);

        if (($result['ok'] ?? false) !== true) {
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
            $files = self::unpack($archive, $root);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Обновление не выполнено: ' . $e->getMessage()];
        }

        return [
            'ok' => true,
            'message' => 'Файлов заменено: ' . $files . '.',
            'files' => $files,
        ];
    }

    // ------------------------------------------------------------------ внутри

    /**
     * Распаковать архив поверх проекта.
     *
     * @return int сколько файлов заменено
     */
    private static function unpack(string $archive, string $root): int
    {
        $files = \MyEdu\Transfer\Zip::read($archive);
        $written = 0;

        foreach ($files as $name => $content) {
            $relative = self::safePath($name);

            if ($relative === null) {
                throw new RuntimeException('В архиве есть файл, который нельзя распаковать на сервер: ' . $name);
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

        return $written;
    }

    /**
     * Путь внутри проекта, безопасный для записи.
     *
     * null — путь запрещён: он ведёт наружу или туда, куда писать нельзя.
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

        foreach (self::FORBIDDEN as $forbidden) {
            if (str_starts_with($normalized, $forbidden)) {
                return null;
            }
        }

        return $normalized;
    }

    /**
     * Скачать архив релиза.
     */
    private function download(string $url, string $target): void
    {
        $body = $this->fetch($url, 120, $target);

        if (!is_string($body) || $body === '') {
            throw new RuntimeException('Архив не скачан: ' . ($this->lastError ?? 'неизвестная причина.'));
        }

        $size = (int) @filesize($target);

        if ($size <= 0) {
            throw new RuntimeException('Скачанный файл пустой.');
        }

        if ($size > self::MAX_ASSET_BYTES) {
            throw new RuntimeException('Архив релиза слишком большой: ' . round($size / 1024 / 1024) . ' МБ.');
        }
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

        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => $toFile === null,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_USERAGENT => 'my.edu',
            ]);

            if ($handle !== false && $handle !== null) {
                curl_setopt($curl, CURLOPT_FILE, $handle);
            }

            $body = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);

            curl_close($curl);

            if (is_resource($handle)) {
                fclose($handle);
            }

            if ($body === false || $status >= 400) {
                $this->lastError = $error !== '' ? $error : ('GitHub ответил кодом ' . $status . '.');

                return false;
            }

            return $toFile === null ? (string) $body : true;
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
            $this->lastError = 'Скачать не удалось: на сервере нет ни curl, ни доступа к внешним адресам.';

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
     * @param list<mixed> $assets
     * @return array{url:string,name:string}|null
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

            return ['url' => $url, 'name' => $name];
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

    private function log(string $message): void
    {
        $file = $this->app->rootDir() . '/var/log/update.log';
        $directory = dirname($file);

        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        @file_put_contents($file, '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n", FILE_APPEND | LOCK_EX);
    }

    private ?string $lastError = null;
}

<?php

declare(strict_types=1);

namespace MyEdu\Core;

/**
 * Очистка размеченного текста по белому списку.
 *
 * Текст вопроса пишет учитель в визуальном редакторе, а читают его ученики.
 * Это первый в проекте путь, где в базу попадает HTML, а значит и первый,
 * где ошибочная разметка обернётся выполнением чужого кода в браузере
 * ребёнка. Поэтому доверия нет ни к браузеру, ни к тому, что пришло в
 * `$_POST`: всё, чего нет в списке ниже, выбрасывается безусловно.
 *
 * Список закрытый, а не запрещающий. «Запретить `script`» не работает:
 * достаточно одного неучтённого вектора — обработчика события, `javascript:`
 * в ссылке, `data:` в картинке — и разметка снова опасна. Белый список
 * обретает новые возможности только через правку этого файла, и это видно
 * в истории изменений.
 *
 * Правила, которые нельзя вынести в список тегов:
 *
 *   — `javascript:`, `data:`, `vbscript:` и прочие схемы отбрасываются
 *     целиком, а не чистятся: у ссылки без безопасной схемы смысла нет;
 *   — картинки допускаются только из каталога загрузок самого сайта;
 *     внешняя картинка — это запрос на чужой сервер из браузера ученика,
 *     то есть и утечка, и способ отследить, кто открыл работу;
 *   — `style`, `id`, `class` и обработчики `on*` не пропускаются: ими
 *     перекрывают чужой вид страницы или вешают обработчик.
 *
 * Класс не зависит от расширений PHP: разбор сделан на DOM-документе,
 * который есть в стандартной поставке, и на preg_replace для верхнего
 * уровня.
 */
final class Html
{
    /** Теги, которые можно оставить вместе с их содержимым. */
    private const ALLOWED_TAGS = [
        'p' => ['class' => false],
        'br' => [],
        'strong' => [],
        'b' => [],
        'em' => [],
        'i' => [],
        'u' => [],
        's' => [],
        'del' => [],
        'sub' => [],
        'sup' => [],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'blockquote' => [],
        'code' => [],
        'pre' => [],
        'a' => ['href' => true, 'title' => true],
        'img' => ['src' => true, 'alt' => true, 'width' => true, 'height' => true],
    ];

    /** Разрешены ли картинки вообще. */
    private const IMAGES_ALLOWED = true;

    /**
     * Теги, которые делают пустой на вид абзац непустым.
     *
     * Без них `<p><img src="…"></p>` счёлся бы пустым, и картинка исчезла бы
     * вместе с абзацем.
     */
    private const CONTENT_TAGS = [
        'img', 'picture', 'video', 'audio', 'iframe', 'table', 'hr',
        'svg', 'object', 'embed', 'canvas', 'input',
    ];

    /** Каталог загрузок относительно корня сайта. */
    private const UPLOAD_PREFIX = '/uploads/';

    /**
     * Возвращает безопасный HTML.
     *
     * Пустая строка на входе даёт пустую строку на выходе, а не «<p></p>»:
     * редактор без содержимого не должен оставлять после себя обломок
     * разметки, который потом сравнивается с текстом и не совпадает.
     */
    public static function clean(string $html): string
    {
        $html = trim($html);

        if ($html === '') {
            return '';
        }

        // Простой текст без тегов не трогаем: он безопаснее любой разметки, а
        // прогон через DOM превратил бы его в набор пустых <p>.
        if (!str_contains($html, '<')) {
            return $html;
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="myedu-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            // Разобрать не удалось — текст не показываем вовсе, потому что
            // показать его можно было бы только как есть, то есть как сырое.
            return '';
        }

        $root = $document->getElementById('myedu-root');

        if ($root === null) {
            return '';
        }

        self::cleanNode($root);
        self::pruneEmptyParagraphs($root);

        $out = '';

        foreach ($root->childNodes as $child) {
            $out .= $document->saveHTML($child);
        }

        return trim($out);
    }

    /**
     * Убрать абзацы, в которых ничего нет.
     *
     * Пустой абзац приезжает из чужих редакторов сплошным потоком: человек
     * нажал Enter в конце текста, и дальше пошёл `<p></p>`. Разметка при этом
     * безопасная, поэтому очистка её пропускала, и обломок уезжал в базу, а
     * потом в форму редактора — уже как часть условия, хотя к тексту отношения
     * не имеет.
     *
     * Считается непустым то, что действительно что-то показывает: текст —
     * даже неразрывный пробел им не считается, — картинка, таблица, вставка.
     * `<br>` пустым не считается: это единственный способ оставить отступ, и
     * одиночный `<br>` в середине условия встречается в чужих выгрузках.
     */
    private static function pruneEmptyParagraphs(\DOMElement $root): void
    {
        $paragraphs = [];

        self::collectParagraphs($root, $paragraphs);

        foreach ($paragraphs as $paragraph) {
            if (!self::paragraphHasContent($paragraph)) {
                $paragraph->parentNode?->removeChild($paragraph);
            }
        }
    }

    /**
     * @param list<\DOMElement> $found
     */
    private static function collectParagraphs(\DOMNode $node, array &$found): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }

            if (strtolower($child->nodeName) === 'p') {
                $found[] = $child;
            }

            self::collectParagraphs($child, $found);
        }
    }

    /**
     * Есть ли в абзаце что-то, кроме пробелов.
     */
    private static function paragraphHasContent(\DOMElement $paragraph): bool
    {
        // Неразрывный пробел — пробел: он виден как ничего не значащая ширина.
        $text = str_replace(["\xC2\xA0", '&nbsp;'], ' ', $paragraph->textContent);

        if (trim($text) !== '') {
            return true;
        }

        foreach ($paragraph->getElementsByTagName('*') as $inside) {
            if (in_array(strtolower($inside->nodeName), self::CONTENT_TAGS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Чистый текст без разметки.
     *
     * Нужен везде, где размеченный текст сравнивается со словом: проверка
     * ключевых слов, поиск по работам, заголовки в списках. Иначе вхождение
     * «хлорофилл» в «<strong>хлорофилл</strong>» не найдётся, и правильный
     * ответ перестанет засчитываться.
     */
    public static function toText(string $html): string
    {
        $text = preg_replace('/<[^>]*>/u', ' ', $html);

        if ($text === null) {
            return $html;
        }

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Неразрывные пробелы после правки разметки схлопываются в обычные:
        // иначе слова перестали бы находиться по соседству с тегом.
        $text = str_replace("\xC2\xA0", ' ', $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /** Есть ли в тексте разметка — чтобы не экранировать лишний раз. */
    private static function cleanNode(\DOMNode $node): void
    {
        // Список копируется: обход идёт по живой коллекции, а удалять из неё
        // же во время обхода нельзя.
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction) {
                $node->removeChild($child);
                continue;
            }

            if (!$child instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($child->nodeName);

            if (!isset(self::ALLOWED_TAGS[$tag])) {
                /*
                 * Запрещённый тег не выбрасывается вместе со содержимым:
                 * внутри <script> текст остался бы видимым куском кода, а
                 * внутри <b> — потерялся бы кусок вопроса. Разница принципиальная,
                 * поэтому она и не сводится к одному правилу.
                 */
                if (in_array($tag, ['script', 'style', 'template', 'iframe', 'object', 'embed'], true)) {
                    $node->removeChild($child);
                    continue;
                }

                self::cleanNode($child);

                $node->replaceChild(
                    $child->ownerDocument?->createTextNode($child->textContent) ?? $child,
                    $child
                );

                continue;
            }

            self::cleanAttributes($child, self::ALLOWED_TAGS[$tag]);
            self::cleanNode($child);
        }
    }

    /**
     * @param array<string,bool> $allowed true — атрибут допускается к проверке
     *                                     значения, false — пропускается вовсе
     */
    private static function cleanAttributes(\DOMElement $element, array $allowed): void
    {
        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            $name = strtolower($attribute->nodeName);
            $value = trim($attribute->nodeValue);

            if (!isset($allowed[$name]) || $allowed[$name] !== true) {
                $element->removeAttribute($attribute->nodeName);
                continue;
            }

            if ($name === 'href') {
                if (!self::safeLink($value)) {
                    $element->removeAttribute('href');
                }

                continue;
            }

            if ($name === 'src') {
                if (!self::safeImageSource($value)) {
                    // Картинка с чужим или опасным адресом удаляется целиком:
                    // пустой <img src=""> — это запрос к текущей странице.
                    $element->parentNode?->removeChild($element);
                }

                continue;
            }

            if (($name === 'width' || $name === 'height') && preg_match('/^\d{1,5}$/', $value) !== 1) {
                $element->removeAttribute($attribute->nodeName);
            }
        }
    }

    /** Схема ссылки, после которой браузер исполнит код. */
    private static function safeLink(string $value): bool
    {
        // Схему проверяем после уборки пробелов и управляющих символов:
        // "java\tscript:" браузер понимает, а простая проверка — нет.
        $probe = strtolower(preg_replace('/[\s\x00-\x1F]+/', '', $value) ?? $value);

        if ($probe === '') {
            return false;
        }

        if (str_starts_with($probe, '#') || str_starts_with($probe, '/')) {
            return true;
        }

        foreach (['http://', 'https://', 'mailto:'] as $scheme) {
            if (str_starts_with($probe, $scheme)) {
                return true;
            }
        }

        return false;
    }

    private static function safeImageSource(string $value): bool
    {
        if (!self::IMAGES_ALLOWED) {
            return false;
        }

        $probe = preg_replace('/[\s\x00-\x1F]+/', '', $value) ?? $value;

        // Только свой каталог загрузок. Внешняя картинка отслеживает, кто
        // открыл работу, и тянет на чужой сервер то, что не нужно.
        return str_starts_with($probe, self::UPLOAD_PREFIX) && !str_contains($probe, '..');
    }
}
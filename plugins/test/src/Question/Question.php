<?php

declare(strict_types=1);

namespace MyEduPlugin\Test\Question;

/**
 * Данные одного вопроса в виде, удобном для проверки и рендера.
 *
 * Вопросы приходят из БД, поэтому здесь нет ничего, что нельзя было бы
 * выразить в SQL. Класс используется и серверной проверкой, и контроллером,
 * который передаёт вопрос в браузер.
 */
final class Question
{
    public const KIND_OPTION = 'option';
    public const KIND_ITEM   = 'item';
    public const KIND_LEFT   = 'left';
    public const KIND_RIGHT  = 'right';

    /**
     * Параметры показа, вычисляемые при отрисовке страницы, а не хранящиеся в БД.
     *
     * @var array{seed:int,shuffle_options:bool}
     */
    private array $runtime = ['seed' => 0, 'shuffle_options' => false];

    /**
     * @param array<string,mixed>                $config  настройки типа (декодированный JSON)
     * @param list<array<string,mixed>>          $options элементы вопроса
     */
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly string $prompt,
        public readonly float $weight = 1.0,
        public readonly bool $isRequired = true,
        public readonly ?string $explanation = null,
        public readonly array $config = [],
        public readonly array $options = []
    ) {
    }

    /**
     * Копия вопроса с параметрами перемешивания.
     *
     * Перемешивание выполняется на сервере и детерминировано seed'ом, привязанным
     * к попытке: при обновлении страницы порядок не меняется, но восстановить
     * его из исходного кода невозможно.
     */
    public function withRuntime(int $seed, bool $shuffleOptions): self
    {
        $clone = new self(
            id: $this->id,
            type: $this->type,
            prompt: $this->prompt,
            weight: $this->weight,
            isRequired: $this->isRequired,
            explanation: $this->explanation,
            config: $this->config,
            options: $this->options
        );

        $clone->runtime = ['seed' => $seed, 'shuffle_options' => $shuffleOptions];

        return $clone;
    }

    public function shuffleSeed(): int
    {
        return $this->runtime['seed'];
    }

    public function shouldShuffleOptions(): bool
    {
        return $this->runtime['shuffle_options'];
    }

    /**
     * Детерминированное перемешивание на основе локального ГПСЧ.
     *
     * Нужно именно mt_srand: последовательность должна совпадать между запросами,
     * иначе ученик получит новый порядок при каждом обновлении страницы.
     *
     * @template T
     * @param list<T> $items
     * @return list<T>
     */
    public function shuffled(array $items): array
    {
        if (count($items) < 2) {
            return $items;
        }

        mt_srand($this->runtime['seed']);

        $result = $items;
        // Алгоритм Фишера—Йетса на собственном ГПСЧ, без shuffle(),
        // чтобы не зависеть от глобального состояния генератора.
        for ($i = count($result) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$result[$i], $result[$j]] = [$result[$j], $result[$i]];
        }

        mt_srand();

        return $result;
    }

    /**
     * @param array<string,mixed>       $row     строка таблицы questions
     * @param list<array<string,mixed>> $options строки таблицы question_options
     */
    public static function fromRow(array $row, array $options = []): self
    {
        return new self(
            id: (int) $row['id'],
            type: (string) $row['type'],
            prompt: (string) $row['prompt'],
            weight: (float) $row['weight'],
            isRequired: (bool) ($row['is_required'] ?? 1),
            explanation: isset($row['explanation']) && $row['explanation'] !== null
                ? (string) $row['explanation']
                : null,
            config: self::decodeConfig($row['config'] ?? null),
            options: $options
        );
    }

    /** @return array<string,mixed> */
    private static function decodeConfig(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** Максимальный балл вопроса = его вес. */
    public function maxScore(): float
    {
        return $this->weight;
    }

    public function configValue(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public function configBool(string $key, bool $default = false): bool
    {
        $value = $this->config[$key] ?? null;

        return is_bool($value) ? $value : $default;
    }

    public function configFloat(string $key, float $default = 0.0): float
    {
        $value = $this->config[$key] ?? null;

        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * Элементы вопроса заданного вида, отсортированные по позиции.
     *
     * @return list<array<string,mixed>>
     */
    public function optionsOfKind(string $kind): array
    {
        $filtered = array_values(array_filter(
            $this->options,
            static fn (array $o): bool => (string) ($o['kind'] ?? '') === $kind
        ));

        usort($filtered, static fn (array $a, array $b): int => (int) $a['position'] <=> (int) $b['position']);

        return $filtered;
    }

    /** @return array<string,mixed>|null */
    public function optionByKey(string $kind, string $valueKey): ?array
    {
        foreach ($this->options as $option) {
            if ((string) ($option['kind'] ?? '') === $kind
                && (string) ($option['value_key'] ?? '') === $valueKey
            ) {
                return $option;
            }
        }

        return null;
    }

    /**
     * Данные для отрисовки вопроса в браузере.
     *
     * Правильный ответ сюда не попадает — см. QuestionTypeInterface::payload().
     *
     * @return array<string,mixed>
     */
    public function payload(): array
    {
        return QuestionTypeRegistry::instance()->get($this->type)->payload($this);
    }

    /**
     * Тип вопроса.
     */
    public function type(): QuestionTypeInterface
    {
        return QuestionTypeRegistry::instance()->get($this->type);
    }

    /**
     * Текст правильного ответа — используется в разборе работы.
     */
    public function expectedText(): string
    {
        return $this->type()->expectedText($this);
    }
}
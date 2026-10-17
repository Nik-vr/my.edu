<?php

declare(strict_types=1);

namespace MyEdu\Import;

use MyEdu\Transfer\WorkPackageBuilder;

/**
 * Разобранный тест → пакет переноса работы.
 *
 * Собирается ровно тот формат, который понимает внос своей выгрузки, и вносится
 * тем же кодом. Свой путь записи здесь был бы второй копией правил переноса:
 * расхождение между ними проявилось бы не сразу, а на работе, которую уже
 * создали.
 */
final class OtpPackage
{
    /**
     * Собрать пакет.
     *
     * @param array<string,mixed> $parsed результат {@see OtpParser::parse()}
     *
     * @return array{manifest:array<string,mixed>,work:array<string,mixed>,media:array<string,string>}
     */
    public function build(array $parsed, string $subject, ?int $parallel): array
    {
        $questions = is_array($parsed['questions'] ?? null) ? $parsed['questions'] : [];
        $items = [];

        foreach (array_values($questions) as $at => $question) {
            $items[] = $this->item($question, $at + 1, $parsed);
        }

        $work = [
            'title'         => (string) ($parsed['title'] ?? 'Тест из OnlineTestPad'),
            'description'   => (string) ($parsed['description'] ?? ''),
            'subject'       => $subject,
            'parallel'      => $parallel,
            'grade_mode'    => 'weighted',
            'criteria_mode' => 'percent',
            'show_results'  => !empty($parsed['showResults']) ? 1 : 0,
            'time_limit'    => $parsed['timeLimit'] === null ? null : (int) $parsed['timeLimit'],
            'max_attempts'  => 0,
            'criteria'      => null,
            'items'         => $items,
        ];

        return [
            'manifest' => [
                'format'       => 'myedu-work',
                'version'      => WorkPackageBuilder::FORMAT_VERSION,
                'source'       => 'OnlineTestPad',
                'items'        => count($items),
            ],
            'work'  => $work,
            // Картинки из выгрузки не едут: офлайн-версия их не содержит, а
            // условия с картинками учителю придётся доделать руками.
            'media' => [],
        ];
    }

    /**
     * Одно задание исходного теста → позиция работы.
     *
     * @param array<string,mixed> $question
     * @param array<string,mixed> $parsed
     *
     * @return array<string,mixed>
     */
    private function item(array $question, int $position, array $parsed): array
    {
        $type = (string) ($question['type'] ?? 'text');
        $weight = (float) ($question['weight'] ?? 1);

        return [
            'position'  => $position,
            'plugin'    => (string) ($question['plugin'] ?? 'test'),
            'kind'      => $type,
            'weight'    => $weight,
            'max_score' => $weight,
            'subject'   => null,
            'payload'   => [
                'test' => [
                    'description'       => '',
                    'time_limit_min'    => 0,
                    // Перемешивание вопросов переносить некуда: в системе каждое
                    // задание работы самостоятельно и идёт в порядке исходного
                    // теста. Перемешивание вариантов переносится.
                    'shuffle_questions' => 0,
                    'shuffle_options'   => !empty($parsed['shuffleOptions']) ? 1 : 0,
                    'max_attempts'      => 0,
                    'show_results'      => 1,
                    'status'            => 'draft',
                ],
                'questions' => [[
                    'type'        => $type,
                    'prompt'      => (string) ($question['prompt'] ?? ''),
                    'position'    => 0,
                    'weight'      => $weight,
                    'is_required' => !empty($question['required']) ? 1 : 0,
                    'explanation' => (string) ($question['explanation'] ?? ''),
                    // Строкой, а не массивом: плагин пишет в базу поле конфигурации
                    // как есть, без перекодирования.
                    'config'      => (string) json_encode(
                        $question['config'] ?? [],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ),
                    'options'     => $this->options($question),
                ]],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $question
     *
     * @return list<array<string,mixed>>
     */
    private function options(array $question): array
    {
        $options = [];

        foreach (is_array($question['options'] ?? null) ? $question['options'] : [] as $at => $option) {
            $options[] = [
                'kind'      => (string) ($option['kind'] ?? 'option'),
                'value_key' => (string) ($option['value_key'] ?? chr(97 + $at)),
                'text'      => (string) ($option['text'] ?? ''),
                'position'  => (int) ($option['position'] ?? $at),
                'pair_key'  => $option['pair_key'] ?? null,
            ];
        }

        return $options;
    }
}
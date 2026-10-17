<?php

declare(strict_types=1);

namespace MyEduPlugin\Test\Question;

/**
 * Приведение сохранённого ответа к строке для проверки учителем.
 *
 * Формат ответа задаёт вид вопроса: без него в проверке был бы сырой JSON
 * вроде ["a","c"] — нечитаемо и неоднозначно, а ученик в чужой работе увидел
 * бы не то, что он отвечал.
 *
 * Вынесено отдельно, потому что разбор нужен в двух местах — на экране
 * проверки работы и на экране ответов ученика. Две копии одного и того же
 * разбора разошлись бы при первой же правке вида вопроса, и один из экранов
 * стал бы показывать ответ иначе, чем другой.
 */
final class AnswerText
{
 /**
 * @param array<string,mixed> $answer сохранённый ответ: answer_text либо answer_data
 */
 public static function format(
 ?QuestionTypeInterface $type,
 ?Question $question,
 array $answer
 ): string {
 $stored = (string) ($answer['answer_text'] ?? '');

 if ($stored !== '') {
 return $stored;
 }

 if ($type === null || $question === null) {
 return (string) ($answer['answer_data'] ?? '');
 }

 $decoded = json_decode((string) ($answer['answer_data'] ?? 'null'), true);

 try {
 return $type->answerText($question, $type->normalizeAnswer($decoded));
 } catch (\Throwable) {
 // Вид вопроса мог измениться после ответа, или данные повреждены.
 // Показать сырой JSON всё же лучше, чем ничего.
 return (string) ($answer['answer_data'] ?? '');
 }
 }
}
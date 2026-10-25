<?php

declare(strict_types=1);

namespace MyEdu\Auth;

use MyEdu\Core\Config;
use MyEdu\Core\Session;

/**
 * Текущая сессия: сотрудника или ученика.
 *
 * Сессия хранит только идентификатор: все роли и права читаются из базы при
 * каждом запросе. Благодаря этому снятие роли действует немедленно, а не
 * после выхода из системы, и нельзя «подсунуть» себе права через данные
 * сессии, которые видел браузер.
 *
 * У сотрудника и ученика свои ключи. В сессии бывает ровно одна личность:
 * вход сотрудника снимает ученические ключи и наоборот. Смешанной сессии не
 * бывает уже потому, что id сотрудника и id ученика — номера из разных
 * таблиц, и один ключ для них означал бы двусмысленный идентификатор.
 *
 * Вместе с идентификатором кладётся отпечаток пароля — и это единственное, что
 * обрывает сессии после смены пароля. Идентификатора самого по себе мало: он
 * переживает смену пароля, и человек, у которого угнали пароль, после смены
 * оставался внутри под чужой сессией. То же касается всех входов сразу — на
 * телефоне, на школьном компьютере, на чужом устройстве, — и закрывать их по
 * одному человек не может.
 *
 * Отпечаток — кусок самого хеша, а не сравнение паролей: он ничего не говорит
 * о пароле, но меняется вместе с ним и стоит в сессии рядом с идентификатором.
 */
final class Auth
{
    private const SESSION_KEY = '_auth_user_id';

    private const STAMP_KEY = '_auth_pwd_stamp';

    private const STUDENT_SESSION_KEY = '_auth_student_id';

    private const STUDENT_STAMP_KEY = '_auth_student_pwd_stamp';

    private ?User $user = null;

    private ?Student $student = null;

    private bool $loaded = false;

    public function __construct(
        private readonly Session $session,
        private readonly UserRepository $users,
        private readonly StudentRepository $students,
        private readonly Config $config
    ) {
    }

    /** Текущий сотрудник или null — гость или вошедший ученик. */
    public function user(): ?User
    {
        $this->load();

        return $this->user;
    }

    /** Текущий ученик или null — гость или вошедший сотрудник. */
    public function student(): ?Student
    {
        $this->load();

        return $this->student;
    }

    /**
     * Загрузка сессии.
     *
     * Обе личности проверяются, хотя в сессии бывает только одна: так
     * защищённая кнопка выхода и шапка не зависят от того, какая именно
     * личность вошла.
     */
    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;
        $this->user = $this->loadUser();
        $this->student = $this->loadStudent();
    }

    private function loadUser(): ?User
    {
        $id = $this->session->get(self::SESSION_KEY);

        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            return null;
        }

        $user = $this->users->findUser((int) $id);

        // Заблокированного пользователя мгновенно выкидываем из сессии.
        if ($user === null || !$user->isActive()) {
            $this->session->remove(self::SESSION_KEY);

            return null;
        }

        /*
         * Пароль сменили после того, как эта сессия была заведена: значит, её
         * оставлять нельзя. Именно это и бывает при взломе — человек меняет
         * пароль, чтобы чужой вход перестал работать, а чужой вход работает,
         * пока сессия жива.
         *
         * Сессии, заведённые без отпечатка (старые файлы сессий, до этой правки),
         * тоже отпускаются: доверять им нечем — пароль в них могли сменить
         * сколько угодно раз назад.
         */
        $stamp = $this->session->get(self::STAMP_KEY);

        if (!is_string($stamp) || !hash_equals($this->stampOf($user->passwordStamp), $stamp)) {
            $this->session->remove(self::SESSION_KEY);
            $this->session->remove(self::STAMP_KEY);

            return null;
        }

        return $user;
    }

    private function loadStudent(): ?Student
    {
        $id = $this->session->get(self::STUDENT_SESSION_KEY);

        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            return null;
        }

        $row = $this->students->find((int) $id);

        if ($row === null) {
            $this->session->remove(self::STUDENT_SESSION_KEY);

            return null;
        }

        $student = $this->students->hydrate($row);
        $stamp = $this->session->get(self::STUDENT_STAMP_KEY);

        if (!is_string($stamp) || !hash_equals($this->stampOf($student->passwordStamp), $stamp)) {
            $this->session->remove(self::STUDENT_SESSION_KEY);
            $this->session->remove(self::STUDENT_STAMP_KEY);

            return null;
        }

        return $student;
    }

    /**
     * Отпечаток пароля для сессии.
     *
     * Считается на настоящем объекте, который уже несёт его с собой из базы.
     * Отдельного запроса за хешем нет намеренно: это был бы лишний запрос на
     * каждый заход в систему.
     */
    private function stampOf(?string $passwordStamp): string
    {
        return $passwordStamp ?? substr(hash('sha256', ''), 0, 32);
    }

    /** Вошёл ли кто-нибудь — сотрудник или ученик. */
    public function check(): bool
    {
        $this->load();

        return $this->user !== null || $this->student !== null;
    }

    /** Номер вошедшего сотрудника; у ученика и гостя — null. */
    public function id(): ?int
    {
        return $this->user()?->id;
    }

    /** Номер вошедшего ученика; у сотрудника и гостя — null. */
    public function studentId(): ?int
    {
        return $this->student()?->id;
    }

    /**
     * Кто вошёл — ученик или сотрудник.
     *
     * Гостю возвращается null: у него нет ни одной идентичности. Метод нужен
     * там, где человек действует от своего имени — начало работы, проверка,
     * «своя ли это работа», — а различать ученика и сотрудника в вызывающем
     * коде не хочется.
     */
    public function actor(): ?Actor
    {
        $studentId = $this->studentId();

        if ($studentId !== null) {
            return Actor::ofStudent($studentId);
        }

        $staffId = $this->id();

        return $staffId === null ? null : Actor::ofStaff($staffId);
    }

    public function isOwner(): bool
    {
        return $this->user()?->isOwner ?? false;
    }

    public function can(string $capability): bool
    {
        /*
         * Ученик не имеет ролей и записей в user_roles: его личность сама по
         * себе есть разрешение сдавать работы. Право сдачи — не назначение
         * завуча, а то, ради чего ученика вообще завели, поэтому оно следует
         * из сессии, а не из записи в базе. Проверка `/works` и `/work/…` идёт
         * по этому праву, и без этой строки ученик после разделения таблиц
         * получал бы 403 на всех работах.
         *
         * Всё остальное ученику закрыто тем же порядком, что и гостю: другие
         * права у него нет.
         */
        if ($this->student() !== null && $capability === Role::TESTS_TAKE) {
            return true;
        }

        return $this->user()?->hasCapability($capability) ?? false;
    }

    /**
     * Чей круг работ видит человек в журнале и в счётчиках меню.
     *
     * Решение владельца проекта: статистика — своя у каждого. Работу выдаёт
     * учитель, и средний балл, и журнал он видит по своим выданным работам.
     * Завуч — такой же ведущий свои работы, а не смотритель школы: раньше
     * право `classes.manage` отдавало ему все работы школы, и журнал под ним
     * расходился со «Средним баллом», который считал только его собственные
     * работы. Отдельной логики для завуча больше нет — она и была источником
     * расхождения: круг работ определяется одинаково для журнала, для
     * счётчиков меню и для сводки.
     *
     * Все работы школы (0 — без ограничения по автору) видит только
     * администратор: управление пользователями и сайтом — его уровень, и
     * сводка по всей школе для него не «отдельная логика», а полномочие.
     * То же право решает, показывать ли в журнале все работы, и в
     * `IssueController`, и в `RatingController`: круг нигде не расходится.
     *
     * Без вошедшего сотрудника — а это гость или ученик, у которого ролей нет
     * вовсе — возвращается -1: номер, которого ни у кого не бывает. Ноль здесь
     * значил бы «все работы школы», и любой, до кого дотянулся бы вызов без
     * проверки права, увидел бы чужие классы.
     *
     * @return int автор работ или 0, если видны все
     */
    public function journalAuthorId(): int
    {
        if ($this->can(Role::USERS_MANAGE)) {
            return 0;
        }

        return $this->user()?->id ?? -1;
    }

    /**
     * Вход сотрудника в систему.
     *
     * Идентификатор сессии обновляется, чтобы прежний идентификатор cookie
     * нельзя было использовать для доступа от имени вошедшего.
     */
    public function login(User $user): void
    {
        $this->session->regenerate();

        // В сессии ровно одна личность: ученические ключи снимаются.
        $this->session->remove(self::STUDENT_SESSION_KEY);
        $this->session->remove(self::STUDENT_STAMP_KEY);

        $this->session->set(self::SESSION_KEY, $user->id);
        $this->session->set(self::STAMP_KEY, $this->stampOf($user->passwordStamp));

        $this->users->update($user->id, ['last_login_at' => date('Y-m-d H:i:s')]);

        $this->user = $user;
        $this->student = null;
        $this->loaded = true;
    }

    /**
     * Вход ученика в систему.
     *
     * Время последнего захода не запоминается: у учеников этой колонки нет —
     * она нужна была только списку сотрудников, а их журнал заходов владелец
     * не вёл.
     */
    public function loginStudent(Student $student): void
    {
        $this->session->regenerate();

        // В сессии ровно одна личность: ключи сотрудника снимаются.
        $this->session->remove(self::SESSION_KEY);
        $this->session->remove(self::STAMP_KEY);

        $this->session->set(self::STUDENT_SESSION_KEY, $student->id);
        $this->session->set(self::STUDENT_STAMP_KEY, $this->stampOf($student->passwordStamp));

        $this->student = $student;
        $this->user = null;
        $this->loaded = true;
    }

    public function logout(): void
    {
        $this->session->destroy();

        $this->user = null;
        $this->student = null;
        $this->loaded = true;
    }

    /**
     * Проверка пароля.
     *
     * Пустой хеш (ученик из этапа 1) паролем не является: такой пользователь
     * не сможет войти, пока пароль не зададут заново.
     */
    public function checkPassword(string $password, string $hash): bool
    {
        if (trim($hash) === '') {
            return false;
        }

        return password_verify($password, $hash);
    }

    public function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public function minPasswordLength(): int
    {
        return max(6, $this->config->int('auth.min_password_length', 8));
    }
}
<?php

declare(strict_types=1);

namespace MyEdu\Auth;

use MyEdu\Core\Config;
use MyEdu\Core\MailerFactory;
use MyEdu\Core\MailerInterface;
use MyEdu\Core\MailMessage;
use MyEdu\Core\MailSetup;

/**
 * Письма, связанные со входом: подтверждение адреса и сброс пароля.
 *
 * Тексты писем собраны здесь, а не в контроллере: контроллер занимается
 * страницами, а письма — отдельная забота со своим набором требований
 * (правильные ссылки, понятная инструкция, русский язык).
 */
final class AuthMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Config $config,
        private readonly MailerFactory $factory
    ) {
    }

    public function driverName(): string
    {
        return $this->mailer->name();
    }

    /**
     * Как настроена отправка — для подсказок на страницах регистрации и
     * восстановления пароля.
     *
     * Отдельный метод рядом с driverName() нужен потому, что название драйвера
     * отвечает на вопрос «что сейчас включено», а странице нужен другой — «уходят
     * ли письма получателям». На боевом сервере с настоящей отправкой название
     * водителя может быть любым, и подсказка про локальный почтовик там вредна.
     */
    public function setup(): MailSetup
    {
        return $this->factory->setup();
    }

    /** Отправка письма со ссылкой подтверждения. */
    public function sendConfirmation(string $to, string $fullName, string $url): bool
    {
        $subject = 'Подтверждение регистрации в системе контроля знаний';

        $text = "Здравствуйте, {$fullName}!\n\n"
            . "Вы зарегистрировались в системе контроля знаний. "
            . "Осталось подтвердить адрес электронной почты.\n\n"
            . "Откройте ссылку, чтобы завершить регистрацию:\n\n"
            . $url . "\n\n"
            . "Ссылка действует "
            . $this->config->int('auth.confirm_token_hours', 48)
            . " ч. Если вы не регистрировались, просто проигнорируйте это письмо.\n";

        return $this->mailer->send(new MailMessage($to, $subject, $text));
    }

    /** Отправка письма со ссылкой сброса пароля. */
    public function sendPasswordReset(string $to, string $fullName, string $url): bool
    {
        $subject = 'Восстановление пароля в системе контроля знаний';

        $text = "Здравствуйте, {$fullName}!\n\n"
            . "По вашей учётной записи запрошено восстановление пароля.\n\n"
            . "Чтобы задать новый пароль, откройте ссылку:\n\n"
            . $url . "\n\n"
            . "Ссылка действует " . $this->config->int('auth.reset_token_hours', 2)
            . " ч и может быть использована один раз.\n\n"
            . "Если вы не запрашивали смену пароля, проигнорируйте это письмо "
            . "и ничего не меняйте.\n";

        return $this->mailer->send(new MailMessage($to, $subject, $text));
    }
}
<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Slack\BlockKit\Blocks\ContextBlock;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage;
use NotificationChannels\Discord\DiscordMessage;
use NotificationChannels\Pushover\PushoverMessage;
use NotificationChannels\Telegram\TelegramMessage;

class NotificationMessage
{
    private const int WECOM_MARKDOWN_MAX_BYTES = 4096;

    /**
     * @param  array<string, string>  $fields
     */
    public function __construct(
        public NotificationType $type,
        public string $title,
        public string $body,
        public string $actionText,
        public string $actionUrl,
        public string $footerText,
        public array $fields = [],
        public ?string $errorMessage = null,
        public ?string $errorLabel = null,
    ) {}

    public function hasError(): bool
    {
        return $this->errorMessage !== null;
    }

    public function toMail(): MailMessage
    {
        $mail = (new MailMessage)->subject($this->title);

        $mail = match ($this->type) {
            NotificationType::Success => $mail->success(),
            NotificationType::Failure => $mail->error(),
        };

        return $mail->markdown('mail.notification', [
            'title' => $this->title,
            'body' => $this->body,
            'fields' => $this->fields,
            'errorMessage' => $this->errorMessage,
            'errorLabel' => $this->errorLabel,
            'actionText' => $this->actionText,
            'actionUrl' => $this->actionUrl,
            'footerText' => $this->footerText,
            'buttonColor' => $this->type->mailButtonColor(),
            'actionRequired' => $this->type === NotificationType::Failure,
        ]);
    }

    public function toSlack(): SlackMessage
    {
        $message = (new SlackMessage)
            ->username(config('app.name'))
            ->emoji($this->type->slackEmoji())
            ->text($this->title)
            ->headerBlock($this->title)
            ->contextBlock(fn (ContextBlock $block) => $block->text($this->footerText))
            ->dividerBlock()
            ->sectionBlock(function (SectionBlock $block) {
                $block->text($this->body);
                foreach ($this->fields as $label => $value) {
                    $block->field("*{$label}:*\n{$value}")->markdown();
                }
            });

        if ($this->hasError()) {
            $message->sectionBlock(fn (SectionBlock $block) => $block->text("*{$this->errorLabel}:*\n```{$this->errorMessage}```")->markdown());
        }

        return $message
            ->dividerBlock()
            ->sectionBlock(fn (SectionBlock $block) => $block->text("<{$this->actionUrl}|{$this->actionText}>")->markdown());
    }

    public function toDiscord(): DiscordMessage
    {
        return DiscordMessage::create()
            ->body($this->body)
            ->embed([
                'title' => $this->title,
                'color' => $this->type->discordColor(),
                'fields' => $this->buildEmbedFields(),
                'footer' => ['text' => $this->footerText],
            ]);
    }

    public function toTelegram(string $chatId, ?string $topicId = null): TelegramMessage
    {
        $lines = ['<b>'.e($this->title).'</b>', '', e($this->body), ''];

        foreach ($this->fields as $label => $value) {
            $lines[] = '<b>'.e($label).':</b> '.e($value);
        }

        if ($this->hasError()) {
            $lines[] = '';
            $lines[] = '<b>'.e($this->errorLabel).':</b>';
            $lines[] = '<code>'.e($this->errorMessage).'</code>';
        }

        $lines[] = '';
        $lines[] = '<i>'.e($this->footerText).'</i>';

        $options = ['parse_mode' => 'HTML'];

        if ($topicId !== null && $topicId !== '') {
            $options['message_thread_id'] = (int) $topicId;
        }

        return TelegramMessage::create(implode("\n", $lines))
            ->to($chatId)
            ->options($options)
            ->button($this->actionText, $this->actionUrl);
    }

    public function toPushover(): PushoverMessage
    {
        $lines = [$this->body, ''];

        foreach ($this->fields as $label => $value) {
            $lines[] = "{$label}: {$value}";
        }

        if ($this->hasError()) {
            $lines[] = '';
            $lines[] = "{$this->errorLabel}: {$this->errorMessage}";
        }

        $message = PushoverMessage::create(implode("\n", $lines))
            ->title($this->title)
            ->url($this->actionUrl, $this->actionText);

        return match ($this->type) {
            NotificationType::Success => $message->normalPriority(),
            NotificationType::Failure => $message->highPriority(),
        };
    }

    /**
     * @return array{title: string, message: string, priority: int}
     */
    public function toGotify(): array
    {
        $lines = [$this->body, ''];

        foreach ($this->fields as $label => $value) {
            $lines[] = "{$label}: {$value}";
        }

        if ($this->hasError()) {
            $lines[] = '';
            $lines[] = "{$this->errorLabel}: {$this->errorMessage}";
        }

        $lines[] = '';
        $lines[] = "{$this->actionText}: {$this->actionUrl}";

        return [
            'title' => $this->title,
            'message' => implode("\n", $lines),
            'priority' => $this->type->gotifyPriority(),
        ];
    }

    /**
     * @return array{msgtype: string, markdown: array{content: string}}
     */
    public function toWeCom(): array
    {
        $lines = [
            sprintf(
                '### <font color="%s">%s</font>',
                $this->type->weComColor(),
                $this->escapeWeComMarkdown($this->title),
            ),
            $this->escapeWeComMarkdown($this->body),
        ];

        foreach ($this->fields as $label => $value) {
            $lines[] = sprintf('> **%s:** %s', $this->escapeWeComMarkdown($label), $this->escapeWeComMarkdown($value));
        }

        if ($this->hasError()) {
            $lines[] = sprintf('> **%s:** %s', $this->escapeWeComMarkdown((string) $this->errorLabel), $this->escapeWeComMarkdown((string) $this->errorMessage));
        }

        $lines[] = sprintf('> <font color="comment">%s</font>', $this->escapeWeComMarkdown($this->footerText));

        $action = sprintf("\n\n[%s](%s)", $this->escapeWeComMarkdown($this->actionText), $this->actionUrl);

        return [
            'msgtype' => 'markdown',
            'markdown' => ['content' => $this->fitWeComContent(implode("\n", $lines), $action)],
        ];
    }

    private function escapeWeComMarkdown(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function fitWeComContent(string $content, string $action): string
    {
        if (strlen($content.$action) <= self::WECOM_MARKDOWN_MAX_BYTES) {
            return $content.$action;
        }

        $available = self::WECOM_MARKDOWN_MAX_BYTES - strlen($action) - 3;

        if ($available <= 0) {
            return mb_strcut($content.$action, 0, self::WECOM_MARKDOWN_MAX_BYTES, 'UTF-8');
        }

        return rtrim(mb_strcut($content, 0, $available, 'UTF-8')).'...'.$action;
    }

    /**
     * @return array{content: string, embeds: array<int, array<string, mixed>>}
     */
    public function toDiscordWebhook(): array
    {
        return [
            'content' => $this->body,
            'embeds' => [
                [
                    'title' => $this->title,
                    'color' => $this->type->discordColor(),
                    'fields' => $this->buildEmbedFields(),
                    'footer' => ['text' => $this->footerText],
                ],
            ],
        ];
    }

    /**
     * @return array{event: string, title: string, body: string, fields: array<string, string>, error?: string, action_url: string, timestamp: string}
     */
    public function toWebhook(string $event): array
    {
        $payload = [
            'event' => $event,
            'title' => $this->title,
            'body' => $this->body,
            'fields' => $this->fields,
            'action_url' => $this->actionUrl,
            'timestamp' => now()->toIso8601String(),
        ];

        if ($this->hasError()) {
            $payload['error'] = $this->errorMessage;
        }

        return $payload;
    }

    /**
     * @return array<int, array{name: string, value: string, inline: bool}>
     */
    private function buildEmbedFields(): array
    {
        $embedFields = [];

        foreach ($this->fields as $label => $value) {
            $embedFields[] = ['name' => $label, 'value' => $value, 'inline' => true];
        }

        if ($this->hasError()) {
            $embedFields[] = ['name' => $this->errorLabel, 'value' => "```{$this->errorMessage}```", 'inline' => false];
        }

        $embedFields[] = ['name' => 'Job Details', 'value' => "[{$this->actionText}]({$this->actionUrl})", 'inline' => false];

        return $embedFields;
    }
}

<?php

declare(strict_types=1);

namespace CloudMe\Notify\Responses;

/**
 * One template from `$notify->templates()->list()` - send it with
 * `templateId: $template->id` and a value for every name in $variables.
 */
final class TemplateResponse
{
    /**
     * @param  list<string>  $variables
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $channel,
        /** "system" (shared by every company) or "company" (your own approved template). */
        public readonly string $type,
        public readonly ?string $subject,
        public readonly string $content,
        public readonly array $variables,
        public readonly ?string $whatsappTemplateName,
        public readonly ?string $whatsappTemplateLanguage,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            name: (string) $data['name'],
            channel: (string) $data['channel'],
            type: (string) $data['type'],
            subject: $data['subject'] ?? null,
            content: (string) $data['content'],
            variables: array_values(array_map('strval', $data['variables'] ?? [])),
            whatsappTemplateName: $data['whatsapp_template_name'] ?? null,
            whatsappTemplateLanguage: $data['whatsapp_template_language'] ?? null,
        );
    }
}

<?php

declare(strict_types=1);

namespace FarosCMS;

/**
 * What happens to a form someone fills in on the site: the values a form starts with, checking what was sent against
 * the fields (required, email, address, number, allowed options), the record kept of a submission, and the emails it
 * causes (a notification for the site, an automatic reply for the visitor, with the reply-to that can be worked out).
 * Sending, storing, the session, and the redirect are the caller's.
 */
final class FormProcessor
{
    /** @param callable(string, string): string $translate a theme string: key, fallback */
    public function __construct(private $translate)
    {
    }

    /**
     * The values a form shows before anything was sent.
     *
     * @param array<int, array<string, mixed>> $fields
     * @return array<string, mixed>
     */
    public function defaults(array $fields): array
    {
        $values = [];
        foreach ($fields as $field) {
            $name = (string)($field['name'] ?? '');
            $type = (string)($field['type'] ?? '');
            if ($name === '' || FormFields::isDisplay($type)) {
                continue;
            }
            $default = $field['default'] ?? '';
            if ($type === 'checkboxes') {
                $values[$name] = is_array($default) ? $default : FormFields::parseOptions((string)$default);
            } elseif ($type === 'checkbox') {
                $values[$name] = Format::isTruthy($default) ? '1' : '';
            } else {
                $values[$name] = $default;
            }
        }
        return $values;
    }

    /**
     * The values sent, cleaned, and what is wrong with them (field name => message). Options that are not on the
     * list are dropped from checkboxes and refused for a select or radio.
     *
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, mixed> $payload
     * @return array{values: array<string, mixed>, errors: array<string, string>}
     */
    public function collect(array $fields, array $payload): array
    {
        $values = [];
        $errors = [];
        $required = fn(): string => ($this->translate)('form.error.required', 'This field is required.');
        foreach ($fields as $field) {
            $name = (string)($field['name'] ?? '');
            $type = (string)($field['type'] ?? 'text');
            if ($name === '' || FormFields::isDisplay($type)) {
                continue;
            }
            $isRequired = (bool)($field['required'] ?? false);
            $options = $field['options'] ?? [];
            $optionValues = array_map(static fn($option) => $option['value'], is_array($options) ? $options : []);

            if ($type === 'checkboxes') {
                $raw = $payload[$name] ?? [];
                $clean = [];
                foreach (is_array($raw) ? $raw : [] as $value) {
                    $value = trim((string)$value);
                    if ($value === '' || (!empty($optionValues) && !in_array($value, $optionValues, true))) {
                        continue;
                    }
                    $clean[] = $value;
                }
                $values[$name] = $clean;
                if ($isRequired && empty($clean)) {
                    $errors[$name] = $required();
                }
                continue;
            }

            if ($type === 'checkbox') {
                $checked = isset($payload[$name]) && (string)$payload[$name] !== '';
                $values[$name] = $checked ? '1' : '';
                if ($isRequired && !$checked) {
                    $errors[$name] = $required();
                }
                continue;
            }

            $value = trim((string)($payload[$name] ?? ''));
            if ($type === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[$name] = ($this->translate)('form.error.email', 'Please enter a valid email.');
            }
            if ($type === 'url' && $value !== '' && !filter_var($value, FILTER_VALIDATE_URL)) {
                $errors[$name] = ($this->translate)('form.error.url', 'Please enter a valid URL.');
            }
            if (in_array($type, ['number', 'range'], true) && $value !== '' && !is_numeric($value)) {
                $errors[$name] = ($this->translate)('form.error.numeric', 'Please enter a numeric value.');
            }
            if (in_array($type, ['select', 'radio'], true) && $value !== '' && !empty($optionValues) && !in_array($value, $optionValues, true)) {
                $errors[$name] = ($this->translate)('form.error.option', 'Please select a valid option.');
            }
            if ($isRequired && $value === '') {
                $errors[$name] = $required();
            }
            $values[$name] = $value;
        }
        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * The record kept of a submission.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function record(ContentItem $form, array $values, string $ip, string $userAgent): array
    {
        return [
            'form' => $form->slug,
            'lang' => $form->lang,
            'translation_id' => (string)($form->meta['translation_id'] ?? ''),
            'submitted_at' => date('c'),
            'ip' => $ip,
            'user_agent' => $userAgent,
            'fields' => $values,
        ];
    }

    /**
     * The emails a submission causes: one for the site (when the form has notifications on and somewhere to send
     * them) and one automatic reply to the visitor (when it is on and the visitor gave an email).
     *
     * @param array<string, mixed> $values
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, mixed> $siteMail the site's mail settings (forms.notifications): from, from_name
     * @return array<int, array{to: string, subject: string, body: string, headers: array<string, string>}>
     */
    public function emails(ContentItem $form, array $values, array $fields, array $siteMail, string $formUrl): array
    {
        $notifications = is_array($form->meta['notifications'] ?? null) ? $form->meta['notifications'] : [];
        $subject = self::fill(trim((string)($notifications['subject'] ?? '')), $values, $fields);
        if ($subject === '') {
            $subject = 'New submission: ' . (string)($form->meta['title'] ?? $form->slug);
        }
        $body = $this->body($form, $values, $fields, $formUrl);

        $from = trim((string)($siteMail['from'] ?? ''));
        $fromName = trim((string)($siteMail['from_name'] ?? ''));
        if ($from === '') {
            $from = 'noreply@localhost';
        }
        $fromHeader = $fromName !== '' ? $fromName . ' <' . $from . '>' : $from;
        $headers = ['From' => $fromHeader];
        $replyEmail = self::replyTo($values, $fields, trim((string)($notifications['reply_to_field'] ?? '')));
        if ($replyEmail !== '') {
            $headers['Reply-To'] = $replyEmail;
        }
        foreach (['Cc' => 'cc', 'Bcc' => 'bcc'] as $header => $key) {
            $value = trim((string)($notifications[$key] ?? ''));
            if ($value !== '') {
                $headers[$header] = $value;
            }
        }

        $messages = [];
        $to = trim((string)($notifications['to'] ?? ''));
        if (Format::isTruthy($notifications['enabled'] ?? false) && $to !== '') {
            $messages[] = ['to' => $to, 'subject' => $subject, 'body' => $body, 'headers' => $headers];
        }
        if (Format::isTruthy($notifications['auto_reply'] ?? false) && $replyEmail !== '') {
            $autoSubject = self::fill(trim((string)($notifications['auto_reply_subject'] ?? '')), $values, $fields);
            $autoMessage = self::fill(trim((string)($notifications['auto_reply_message'] ?? '')), $values, $fields);
            if ($autoMessage === '') {
                $autoMessage = "Thanks for contacting us.\n\nWe received your submission and will get back to you soon.";
            }
            if (Format::isTruthy($notifications['auto_reply_include'] ?? false)) {
                $autoMessage .= "\n\n---\n\n" . $body;
            }
            $messages[] = ['to' => $replyEmail, 'subject' => $autoSubject !== '' ? $autoSubject : 'Thanks for your message', 'body' => $autoMessage, 'headers' => ['From' => $fromHeader]];
        }
        return $messages;
    }

    /**
     * A text with what was answered put in: {full-name} becomes the answer of the field called full-name. A word in
     * braces that is not a field of the form is left as it is.
     *
     * @param array<string, mixed> $values
     * @param array<int, array<string, mixed>> $fields
     */
    public static function fill(string $text, array $values, array $fields): string
    {
        if ($text === '' || !str_contains($text, '{')) {
            return $text;
        }
        $known = [];
        foreach ($fields as $field) {
            $name = (string)($field['name'] ?? '');
            if ($name !== '' && !FormFields::isDisplay((string)($field['type'] ?? ''))) {
                $known['{' . $name . '}'] = str_replace(["\r", "\n"], ' ', self::text($values[$name] ?? ''));
            }
        }
        return strtr($text, $known);
    }

    /** The text of the notification: the form, where it is, and each field with what was sent. @param array<string, mixed> $values @param array<int, array<string, mixed>> $fields */
    public function body(ContentItem $form, array $values, array $fields, string $formUrl): string
    {
        $lines = ['Form: ' . (string)($form->meta['title'] ?? $form->slug), 'URL: ' . $formUrl, ''];
        foreach ($fields as $field) {
            $name = (string)($field['name'] ?? '');
            if ($name === '' || FormFields::isDisplay((string)($field['type'] ?? ''))) {
                continue;
            }
            $lines[] = (string)($field['label'] ?? $name) . ': ' . self::text($values[$name] ?? '');
        }
        return implode("\n", $lines);
    }

    /**
     * The address to reply to: the field the form names, else the first email field with a valid address, else a
     * field called "email". '' when there is none.
     *
     * @param array<string, mixed> $values
     * @param array<int, array<string, mixed>> $fields
     */
    public static function replyTo(array $values, array $fields, string $replyToField): string
    {
        if ($replyToField !== '' && isset($values[$replyToField])) {
            $candidate = trim((string)$values[$replyToField]);
            if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                return $candidate;
            }
        }
        foreach ($fields as $field) {
            if (($field['type'] ?? '') === 'email') {
                $candidate = trim((string)($values[(string)($field['name'] ?? '')] ?? ''));
                if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                    return $candidate;
                }
            }
        }
        if (isset($values['email']) && filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            return (string)$values['email'];
        }
        return '';
    }

    /** A value as one line of text: a list of choices is joined with commas. */
    public static function text(mixed $value): string
    {
        return is_array($value) ? implode(', ', array_map('strval', $value)) : trim((string)$value);
    }

    /** What to call a submission: the name, else the full name, else the email, else "Submission". */
    public static function title(mixed $fields): string
    {
        if (is_array($fields)) {
            foreach (['name', 'full_name', 'email'] as $key) {
                if (isset($fields[$key]) && trim((string)$fields[$key]) !== '') {
                    return trim((string)$fields[$key]);
                }
            }
        }
        return 'Submission';
    }

    /** A date and time as the admin shows it; text that is not a date is shown as it is. */
    public static function date(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? $value : date('Y-m-d H:i', $timestamp);
    }
}

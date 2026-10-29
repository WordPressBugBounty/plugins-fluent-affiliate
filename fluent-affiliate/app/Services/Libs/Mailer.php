<?php

namespace FluentAffiliate\App\Services\Libs;


use FluentAffiliate\App\Helper\Utility;
use FluentAffiliate\App\Services\Libs\Emogrifier\Emogrifier;
use FluentAffiliate\Framework\Support\Arr;

class Mailer
{
    private $subject = '';

    private $body = '';

    private $to = '';

    private $from = '';

    private $cc = [];

    private $bcc = [];

    private $replyTo = '';

    private $isHtml = true;

    public function __construct($to = '', $subject = '', $body = '')
    {
        $this->to = $to;
        $this->subject = $subject;
        $this->body = $body;

        $this->setDefaultHeaders();
    }

    public function setDefaultHeaders($settings = null)
    {
        if (!$settings) {
            $settings = Utility::getEmailSettings();
        }

        $sendFromName = Arr::get($settings, 'send_from_name', '');
        $sendFromEmail = Arr::get($settings, 'send_from_email', '');

        if ($sendFromName && $sendFromEmail) {
            $this->from = $sendFromName . ' <' . $sendFromEmail . '>';
        } else if ($sendFromEmail) {
            $this->from = $sendFromEmail;
        }

        $replyToEmail = Arr::get($settings, 'reply_to_email', '');
        $replyToName = Arr::get($settings, 'reply_to_name', '');

        if ($replyToEmail && $replyToName) {
            $this->replyTo = $replyToName . ' <' . $replyToEmail . '>';
        } else if ($replyToEmail) {
            $this->replyTo = $replyToEmail;
        }

        return $this;
    }

    public function setSubject($subject)
    {
        $this->subject = $subject;
        return $this;
    }

    public function setBody($body)
    {
        $this->body = $body;
        return $this;
    }

    public function to($email, $name = '')
    {
        // wp_mail() splits a To string on commas, so a display name carrying a
        // comma/semicolon injects extra recipients; angle brackets/quotes reframe
        // the address and control characters such as CR/LF inject headers. Strip
        // them before building it.
        if ($name) {
            $name = trim(preg_replace('/[\x00-\x1F\x7F,;<>"]+/', ' ', $name));
        }

        if ($name) {
            $this->to = $name . ' <' . $email . '>';
        } else {
            $this->to = $email;
        }
        return $this;
    }

    public function setIsHtml($isHtml)
    {
        $this->isHtml = $isHtml;
        return $this;
    }

    public function setFrom($from)
    {
        $this->from = $from;
        return $this;
    }

    public function addCC($cc)
    {
        $this->cc[] = $cc;
        return $this;
    }

    public function addBCC($bcc)
    {
        $this->bcc[] = $bcc;
        return $this;
    }

    public function setReplyTo($replyTo)
    {
        $this->replyTo = $replyTo;
        return $this;
    }

    public function send($cssInliner = false)
    {
        if (!$this->to && !$this->cc && !$this->bcc) {
            return false;
        }

        if ($cssInliner) {
            $this->body = (string)(new Emogrifier($this->body))->emogrify();
        }

        $headers = [];

        if ($this->isHtml) {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        }

        if ($this->from) {
            $headers[] = 'From: ' . $this->stripLineBreaks($this->from);
        }

        if ($this->cc) {
            $headers[] = 'Cc: ' . $this->stripLineBreaks(implode(',', $this->cc));
        }

        if ($this->bcc) {
            $headers[] = 'Bcc: ' . $this->stripLineBreaks(implode(',', $this->bcc));
        }

        if ($this->replyTo) {
            $headers[] = 'Reply-To: ' . $this->stripLineBreaks($this->replyTo);
        }

        return wp_mail($this->to, $this->stripLineBreaks($this->subject), $this->body, $headers);
    }

    /**
     * A line break inside a header value would start a header of its own.
     */
    private function stripLineBreaks($value)
    {
        return str_replace(["\r", "\n"], '', (string) $value);
    }
}

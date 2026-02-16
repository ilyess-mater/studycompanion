<?php

declare(strict_types=1);

namespace App\Enum;

enum ThirdPartyProvider: string
{
    case OpenAi = 'OPENAI';
    case GroqFree = 'GROQ_FREE';
    case LocalNlp = 'LOCAL_NLP';
    case WebLink = 'WEB_LINK';
    case Youtube = 'YOUTUBE';
    case CloudflareTurnstile = 'CLOUDFLARE_TURNSTILE';
    case GooglePerspective = 'GOOGLE_PERSPECTIVE';
    case SymfonyMailer = 'SYMFONY_MAILER';
}

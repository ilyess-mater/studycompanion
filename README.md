# StudyCompanion+ (Symfony MVP)

StudyCompanion+ is a Symfony 7 web application implementing a personalized AI tutor workflow with role-based student/teacher dashboards, lesson upload + analysis, adaptive quizzes, focus-mode monitoring, and mastery reports.

## Stack

- Symfony 7.4
- PHP 8.2+
- Doctrine ORM + Migrations + Messenger
- MySQL/MariaDB (XAMPP compatible)
- Twig + Symfony UX Stimulus
- OpenAI (primary) + Groq/Local NLP fallback + YouTube + Cloudflare Turnstile + Google Perspective API

## Local setup (XAMPP)

1. Install dependencies:
   ```bash
   composer install
   ```
2. Create local env file (optional):
   ```bash
   cp .env.local.example .env.local
   ```
3. Ensure DB config points to XAMPP MySQL:
   ```dotenv
   DATABASE_URL="mysql://root:@127.0.0.1:3306/studycompanion?serverVersion=10.4.32-MariaDB&charset=utf8mb4"
   ```
4. Create DB and migrate:
   ```bash
   php bin/console doctrine:database:create --if-not-exists
   php bin/console doctrine:migrations:migrate --no-interaction
   ```
5. Load demo fixtures:
   ```bash
   php bin/console doctrine:fixtures:load --no-interaction
   ```
6. Start app:
   ```bash
   symfony server:start
   ```
7. Run async workers in another terminal:
   ```bash
   php bin/console messenger:consume async -vv
   ```

## Demo credentials

- Teacher: `teacher@studycompanion.local` / `Teacher123!`
- Student: `student@studycompanion.local` / `Student123!`

## Core routes

- Auth: `/register`, `/login`, `/logout`
- Student: `/student/dashboard`, `/student/lessons`, `/student/reports`, `/student/groups/join`
- Teacher: `/teacher/dashboard`, `/teacher/groups`, `/teacher/lessons`, `/teacher/reports`
- Shared: `/settings`, `/teacher/admin/health`
- API: `/api/v1/*` (see `docs/api/openapi.yaml`)

## Documents

- ER diagram: `docs/uml/er.puml`
- Use case diagram: `docs/uml/usecase.puml`
- API contract: `docs/api/openapi.yaml`
- Java stub client: `java-stub-client/`

## Notes

- Focus mode in this MVP is browser-level (fullscreen + violation logging + timer). Full OS lock enforcement is deferred to Java desktop integration.
- AI runtime uses OpenAI as primary provider when `OPENAI_API_KEY` is configured, then automatically falls back to Groq and finally local deterministic NLP.
- A `third_party_meta` JSON evidence payload is stored on all core diagram entities for provider traceability.
8. Optional third-party keys (set in `.env.local`):
   ```dotenv
   AI_PROVIDER=openai
   AI_STRICT_MODE=0
   AI_FALLBACK_PROVIDER=groq_local
   OPENAI_API_KEY=...
   OPENAI_MODEL=gpt-4o-mini
   GROQ_API_KEY=...
   GROQ_MODEL=llama-3.1-8b-instant
   TURNSTILE_ENABLED=1
   TURNSTILE_SITE_KEY=...
   TURNSTILE_SECRET_KEY=...
   YOUTUBE_API_KEY=...
   GOOGLE_PERSPECTIVE_API_KEY=...
   THIRD_PARTY_STRICT_MODE=0
   ```

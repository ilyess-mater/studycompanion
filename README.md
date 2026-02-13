# StudyCompanion+ (Symfony MVP)

StudyCompanion+ is a Symfony 7 web application implementing a personalized AI tutor workflow with role-based student/teacher dashboards, lesson upload + analysis, adaptive quizzes, focus-mode monitoring, and mastery reports.

## Stack

- Symfony 7.4
- PHP 8.2+
- Doctrine ORM + Migrations + Messenger
- MySQL/MariaDB (XAMPP compatible)
- Twig + Symfony UX Stimulus

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
- Teacher: `/teacher/dashboard`, `/teacher/groups`, `/teacher/lessons`, `/teacher/comments`
- API: `/api/v1/*` (see `docs/api/openapi.yaml`)

## Documents

- ER diagram: `docs/uml/er.puml`
- Use case diagram: `docs/uml/usecase.puml`
- API contract: `docs/api/openapi.yaml`
- Java stub client: `java-stub-client/`

## Notes

- Focus mode in this MVP is browser-level (fullscreen + violation logging + timer). Full OS lock enforcement is deferred to Java desktop integration.
- AI calls use OpenAI when `OPENAI_API_KEY` is configured; deterministic fallbacks are used otherwise.
